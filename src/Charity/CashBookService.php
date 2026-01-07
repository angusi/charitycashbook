<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Service for managing cash books (bank accounts, petty cash, etc.)
 */
class CashBookService
{
    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct(DatabaseInterface $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Get all cash books for a charity
     */
    public function getCashBooks(int $charityId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM cash_books WHERE charity_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }
        $sql .= " ORDER BY display_order, name";
        
        return $this->db->query($sql, [$charityId]);
    }

    /**
     * Get a single cash book
     */
    public function getCashBook(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM cash_books WHERE id = ?",
            [$id]
        );
    }

    /**
     * Create a new cash book
     */
    public function create(int $charityId, array $data): int
    {
        $this->db->insert('cash_books', [
            'charity_id' => $charityId,
            'name' => $data['name'],
            'book_type' => $data['book_type'] ?? 'bank',
            'description' => $data['description'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'sort_code' => $data['sort_code'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? 0.00,
            'opening_balance_date' => $data['opening_balance_date'],
            'display_order' => $data['display_order'] ?? 0,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update a cash book
     */
    public function update(int $id, array $data): void
    {
        $updates = [];
        $params = [];

        $allowedFields = ['name', 'book_type', 'description', 'account_number', 'sort_code', 
                         'opening_balance', 'opening_balance_date', 'is_active', 'display_order'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return;
        }

        $params[] = $id;
        $this->db->execute(
            "UPDATE cash_books SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    }

    /**
     * Calculate current balance for a cash book
     * 
     * Uses transaction_cashbook_entries table for accurate multi-cashbook support.
     */
    public function calculateBalance(int $cashBookId, ?string $asOfDate = null): array
    {
        $cashBook = $this->getCashBook($cashBookId);
        if (!$cashBook) {
            throw new \InvalidArgumentException("Cash book not found");
        }

        $openingBalance = (float) $cashBook['opening_balance'];
        $openingDate = $cashBook['opening_balance_date'];

        // Build query for cashbook entries (amount is already signed: + for money in, - for money out)
        $sql = "SELECT 
                    COALESCE(SUM(CASE WHEN tce.amount > 0 THEN tce.amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN tce.amount < 0 THEN ABS(tce.amount) ELSE 0 END), 0) as total_expenses,
                    COUNT(*) as entry_count
                FROM transaction_cashbook_entries tce
                JOIN transactions t ON t.id = tce.transaction_id
                WHERE tce.cash_book_id = ? AND t.transaction_date >= ? AND t.is_deleted = FALSE";
        $params = [$cashBookId, $openingDate];

        if ($asOfDate) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $asOfDate;
        }

        $result = $this->db->queryOne($sql, $params);

        $totalIncome = (float) ($result['total_income'] ?? 0);
        $totalExpenses = (float) ($result['total_expenses'] ?? 0);
        $currentBalance = $openingBalance + $totalIncome - $totalExpenses;

        return [
            'opening_balance' => $openingBalance,
            'opening_date' => $openingDate,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'current_balance' => $currentBalance,
            'entry_count' => (int) ($result['entry_count'] ?? 0),
            'as_of_date' => $asOfDate ?? date('Y-m-d'),
        ];
    }

    /**
     * Get transactions for a cash book (via cashbook entries)
     */
    public function getTransactions(int $cashBookId, array $filters = []): array
    {
        $sql = "SELECT t.*, 
                       tce.amount as cashbook_amount,
                       tce.is_reconciled,
                       tce.reconciled_at,
                       tce.id as cashbook_entry_id,
                       f.name as fund_name, 
                       rc.name as reporting_category_name,
                       oc.name as operational_category_name
                FROM transaction_cashbook_entries tce
                JOIN transactions t ON t.id = tce.transaction_id
                LEFT JOIN transaction_fund_entries tfe ON tfe.id = (
                    SELECT MIN(id) FROM transaction_fund_entries WHERE transaction_id = t.id
                )
                LEFT JOIN funds f ON f.id = tfe.fund_id
                LEFT JOIN operational_categories oc ON oc.id = t.operational_category_id
                LEFT JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
                WHERE tce.cash_book_id = ? AND t.is_deleted = FALSE";
        $params = [$cashBookId];

        if (!empty($filters['start_date'])) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (isset($filters['is_reconciled'])) {
            $sql .= " AND tce.is_reconciled = ?";
            $params[] = $filters['is_reconciled'] ? 1 : 0;
        }

        $sql .= " ORDER BY t.transaction_date DESC, t.id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Get unassigned transactions (not assigned to any cash book)
     * 
     * Only returns transactions that:
     * 1. Have no cashbook entries
     * 2. Are not inter-fund transfers (fund entries sum != 0)
     * 3. Transaction date is on or after the earliest cashbook opening_balance_date
     */
    public function getUnassignedTransactions(int $charityId, array $filters = []): array
    {
        $sql = "SELECT t.*, 
                       f.name as fund_name,
                       rc.name as reporting_category_name,
                       oc.name as operational_category_name
                FROM transactions t
                LEFT JOIN transaction_fund_entries tfe ON tfe.id = (
                    SELECT MIN(id) FROM transaction_fund_entries WHERE transaction_id = t.id
                )
                LEFT JOIN funds f ON f.id = tfe.fund_id
                LEFT JOIN operational_categories oc ON oc.id = t.operational_category_id
                LEFT JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
                WHERE t.charity_id = ? 
                  AND t.is_deleted = FALSE
                  AND NOT EXISTS (
                      SELECT 1 FROM transaction_cashbook_entries tce WHERE tce.transaction_id = t.id
                  )
                  AND t.transaction_date >= (
                      SELECT COALESCE(MIN(opening_balance_date), '1900-01-01')
                      FROM cash_books 
                      WHERE charity_id = t.charity_id AND is_active = TRUE
                  )
                  AND (
                      SELECT COALESCE(SUM(amount), 0) FROM transaction_fund_entries 
                      WHERE transaction_id = t.id
                  ) != 0";
        $params = [$charityId];

        if (!empty($filters['start_date'])) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $filters['end_date'];
        }

        $sql .= " ORDER BY t.transaction_date DESC, t.id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Get unlinked transactions (legacy method - redirects to getUnassignedTransactions)
     * @deprecated Use getUnassignedTransactions instead
     */
    public function getUnlinkedTransactions(int $charityId, array $filters = []): array
    {
        return $this->getUnassignedTransactions($charityId, $filters);
    }

    /**
     * Count unassigned transactions
     */
    public function countUnassignedTransactions(int $charityId): int
    {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as cnt 
             FROM transactions t
             WHERE t.charity_id = ? 
               AND t.is_deleted = FALSE
               AND NOT EXISTS (
                   SELECT 1 FROM transaction_cashbook_entries tce WHERE tce.transaction_id = t.id
               )
               AND t.transaction_date >= (
                   SELECT COALESCE(MIN(opening_balance_date), '1900-01-01')
                   FROM cash_books 
                   WHERE charity_id = t.charity_id AND is_active = TRUE
               )
               AND (
                   SELECT COALESCE(SUM(amount), 0) FROM transaction_fund_entries 
                   WHERE transaction_id = t.id
               ) != 0",
            [$charityId]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Count unlinked transactions (legacy method)
     * @deprecated Use countUnassignedTransactions instead
     */
    public function countUnlinkedTransactions(int $charityId): int
    {
        return $this->countUnassignedTransactions($charityId);
    }

    /**
     * Assign transactions to a cash book
     * 
     * Creates cashbook entries for the full transaction amount.
     */
    public function assignTransactions(array $transactionIds, int $cashBookId): int
    {
        if (empty($transactionIds)) {
            return 0;
        }

        $count = 0;
        foreach ($transactionIds as $transactionId) {
            // Get transaction to determine amount and type
            $transaction = $this->db->queryOne(
                "SELECT id, amount, transaction_type FROM transactions WHERE id = ?",
                [$transactionId]
            );
            
            if (!$transaction) {
                continue;
            }

            // Check if already has cashbook entries
            $existingCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM transaction_cashbook_entries WHERE transaction_id = ?",
                [$transactionId]
            );

            if ($existingCount > 0) {
                continue; // Skip if already assigned
            }

            // Calculate signed amount
            $amount = (float) $transaction['amount'];
            $signedAmount = $transaction['transaction_type'] === 'receipt' ? $amount : -$amount;

            // Create cashbook entry
            $this->db->insert('transaction_cashbook_entries', [
                'transaction_id' => $transactionId,
                'cash_book_id' => $cashBookId,
                'amount' => $signedAmount,
                'is_reconciled' => false,
            ]);

            // Update legacy column for backwards compatibility
            $this->db->execute(
                "UPDATE transactions SET cash_book_id = ? WHERE id = ?",
                [$cashBookId, $transactionId]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Unassign transactions from cash book
     */
    public function unassignTransactions(array $transactionIds): int
    {
        if (empty($transactionIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));

        // Delete cashbook entries
        $this->db->execute(
            "DELETE FROM transaction_cashbook_entries WHERE transaction_id IN ($placeholders)",
            $transactionIds
        );

        // Update legacy columns
        $this->db->execute(
            "UPDATE transactions SET cash_book_id = NULL, is_reconciled = FALSE, reconciled_at = NULL WHERE id IN ($placeholders)",
            $transactionIds
        );

        return count($transactionIds);
    }

    /**
     * Create a reconciliation record
     */
    public function reconcile(int $cashBookId, array $data, int $userId): int
    {
        $expectedBalance = $this->calculateBalance($cashBookId, $data['reconciled_date']);
        $statementBalance = (float) $data['statement_balance'];
        $discrepancy = $statementBalance - $expectedBalance['current_balance'];

        $this->db->insert('cash_book_reconciliations', [
            'cash_book_id' => $cashBookId,
            'reconciled_date' => $data['reconciled_date'],
            'statement_balance' => $statementBalance,
            'expected_balance' => $expectedBalance['current_balance'],
            'discrepancy' => $discrepancy,
            'notes' => $data['notes'] ?? null,
            'reconciled_by_user_id' => $userId,
        ]);

        $reconciliationId = $this->db->lastInsertId();

        // Mark cashbook entries up to this date as reconciled
        if (empty($data['skip_mark_reconciled'])) {
            $this->db->execute(
                "UPDATE transaction_cashbook_entries tce
                 JOIN transactions t ON t.id = tce.transaction_id
                 SET tce.is_reconciled = TRUE, tce.reconciled_at = NOW() 
                 WHERE tce.cash_book_id = ? AND t.transaction_date <= ? AND tce.is_reconciled = FALSE",
                [$cashBookId, $data['reconciled_date']]
            );

            // Also update legacy column on transactions for backwards compatibility
            $this->db->execute(
                "UPDATE transactions t
                 JOIN transaction_cashbook_entries tce ON tce.transaction_id = t.id
                 SET t.is_reconciled = TRUE, t.reconciled_at = NOW() 
                 WHERE tce.cash_book_id = ? AND t.transaction_date <= ? AND t.is_reconciled = FALSE",
                [$cashBookId, $data['reconciled_date']]
            );
        }

        return $reconciliationId;
    }

    /**
     * Get reconciliation history for a cash book
     */
    public function getReconciliations(int $cashBookId): array
    {
        return $this->db->query(
            "SELECT r.*, COALESCE(cu.display_name, u.display_name) as reconciled_by_name
             FROM cash_book_reconciliations r
             LEFT JOIN users u ON u.id = r.reconciled_by_user_id
             LEFT JOIN cash_books cb ON cb.id = r.cash_book_id
             LEFT JOIN charity_users cu ON cu.user_id = r.reconciled_by_user_id AND cu.charity_id = cb.charity_id
             WHERE r.cash_book_id = ?
             ORDER BY r.reconciled_date DESC",
            [$cashBookId]
        );
    }

    /**
     * Get the last reconciliation for a cash book
     */
    public function getLastReconciliation(int $cashBookId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM cash_book_reconciliations 
             WHERE cash_book_id = ? 
             ORDER BY reconciled_date DESC 
             LIMIT 1",
            [$cashBookId]
        );
    }

    /**
     * Get summary stats for all cash books
     */
    public function getSummary(int $charityId): array
    {
        $cashBooks = $this->getCashBooks($charityId);
        $totalBalance = 0;
        $summary = [];

        foreach ($cashBooks as $cb) {
            $balance = $this->calculateBalance($cb['id']);
            $lastRecon = $this->getLastReconciliation($cb['id']);
            $unreconciled = $this->db->queryOne(
                "SELECT COUNT(*) as cnt 
                 FROM transaction_cashbook_entries tce
                 JOIN transactions t ON t.id = tce.transaction_id
                 WHERE tce.cash_book_id = ? AND tce.is_reconciled = FALSE AND t.is_deleted = FALSE",
                [$cb['id']]
            );

            $summary[] = [
                'cash_book' => $cb,
                'balance' => $balance['current_balance'],
                'last_reconciled' => $lastRecon ? $lastRecon['reconciled_date'] : null,
                'unreconciled_count' => (int) ($unreconciled['cnt'] ?? 0),
            ];

            $totalBalance += $balance['current_balance'];
        }

        return [
            'cash_books' => $summary,
            'total_balance' => $totalBalance,
            'unassigned_count' => $this->countUnassignedTransactions($charityId),
        ];
    }

    /**
     * Get the date of last cashbook activity (entry or reconciliation)
     */
    public function getLastActivityDate(int $charityId): ?string
    {
        // Get last cashbook entry date
        $lastEntry = $this->db->queryOne(
            "SELECT MAX(t.transaction_date) as last_date
             FROM transaction_cashbook_entries tce
             JOIN transactions t ON t.id = tce.transaction_id
             JOIN cash_books cb ON cb.id = tce.cash_book_id
             WHERE cb.charity_id = ? AND t.is_deleted = FALSE",
            [$charityId]
        );

        // Get last reconciliation date
        $lastRecon = $this->db->queryOne(
            "SELECT MAX(r.reconciled_date) as last_date
             FROM cashbook_reconciliations r
             JOIN cash_books cb ON cb.id = r.cash_book_id
             WHERE cb.charity_id = ?",
            [$charityId]
        );

        $dates = array_filter([
            $lastEntry['last_date'] ?? null,
            $lastRecon['last_date'] ?? null,
        ]);

        if (empty($dates)) {
            return null;
        }

        return max($dates);
    }

    /**
     * Assign all transactions for a fund to a cashbook
     * Used during setup wizard for bulk historical assignment
     */
    public function assignTransactionsByFund(int $charityId, int $fundId, int $cashBookId): int
    {
        // Get the cashbook to verify ownership and get opening date
        $cashBook = $this->getCashBook($cashBookId);
        if (!$cashBook || $cashBook['charity_id'] !== $charityId) {
            throw new \InvalidArgumentException('Invalid cash book');
        }

        // Find all transactions for this fund that don't have cashbook entries
        // and are on or after the cashbook's opening balance date
        $transactions = $this->db->query(
            "SELECT DISTINCT t.id, t.amount, t.transaction_type
             FROM transactions t
             JOIN transaction_fund_entries tfe ON tfe.transaction_id = t.id
             WHERE t.charity_id = ?
               AND t.is_deleted = FALSE
               AND tfe.fund_id = ?
               AND t.transaction_date >= ?
               AND NOT EXISTS (
                   SELECT 1 FROM transaction_cashbook_entries tce WHERE tce.transaction_id = t.id
               )",
            [$charityId, $fundId, $cashBook['opening_balance_date']]
        );

        $count = 0;
        foreach ($transactions as $t) {
            // Calculate signed amount for cashbook entry
            $signedAmount = $t['transaction_type'] === 'receipt' 
                ? (float) $t['amount'] 
                : -(float) $t['amount'];

            $this->db->insert('transaction_cashbook_entries', [
                'transaction_id' => $t['id'],
                'cash_book_id' => $cashBookId,
                'amount' => $signedAmount,
                'is_reconciled' => false,
            ]);
            $count++;
        }

        $this->logger->info("Assigned {$count} transactions from fund {$fundId} to cashbook {$cashBookId}");

        return $count;
    }
}
