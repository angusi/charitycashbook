<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing transactions
 * 
 * Transactions support many-to-many relationships with funds and cashbooks:
 * - A transaction can be split across multiple funds (transaction_fund_entries)
 * - A transaction can be split across multiple cashbooks (transaction_cashbook_entries)
 * - Fund entries are required; cashbook entries are optional
 */
class TransactionService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private CategoryService $categoryService;
    private AuditService $auditService;

    public function __construct(
        DatabaseInterface $db,
        Logger $logger,
        CategoryService $categoryService,
        AuditService $auditService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->categoryService = $categoryService;
        $this->auditService = $auditService;
    }

    /**
     * Get transactions with filters
     * 
     * Note: For transactions with multiple fund entries, this returns the primary fund
     * (first entry). Use getFundEntries() for full fund breakdown.
     */
    public function getTransactions(int $charityId, array $filters = []): array
    {
        // Use subquery to get primary fund (for display) - first fund entry
        $sql = "SELECT t.*, 
                       pf.name as fund_name,
                       pf.id as primary_fund_id,
                       oc.name as category_name,
                       rc.name as reporting_category_name,
                       d.name as donor_name,
                       (SELECT COUNT(*) FROM transaction_fund_entries WHERE transaction_id = t.id) as fund_entry_count,
                       (SELECT COUNT(*) FROM transaction_cashbook_entries WHERE transaction_id = t.id) as cashbook_entry_count
                FROM transactions t
                LEFT JOIN transaction_fund_entries tfe_primary ON tfe_primary.id = (
                    SELECT MIN(id) FROM transaction_fund_entries WHERE transaction_id = t.id
                )
                LEFT JOIN funds pf ON pf.id = tfe_primary.fund_id
                JOIN operational_categories oc ON oc.id = t.operational_category_id
                JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
                LEFT JOIN donors d ON d.id = t.donor_id
                WHERE t.charity_id = ? AND t.is_deleted = FALSE";
        
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['fund_id'])) {
            // Filter by fund using the entries table
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_fund_entries tfe WHERE tfe.transaction_id = t.id AND tfe.fund_id = ?)";
            $params[] = $filters['fund_id'];
        }

        if (!empty($filters['cash_book_id'])) {
            // Filter by cashbook using the entries table
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_cashbook_entries tce WHERE tce.transaction_id = t.id AND tce.cash_book_id = ?)";
            $params[] = $filters['cash_book_id'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND t.transaction_type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND t.operational_category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['reporting_category_id'])) {
            $sql .= " AND oc.reporting_category_id = ?";
            $params[] = $filters['reporting_category_id'];
        }

        if (!empty($filters['giftaid_status'])) {
            $sql .= " AND t.giftaid_status = ?";
            $params[] = $filters['giftaid_status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND t.description LIKE ?";
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY t.transaction_date DESC, t.id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Count transactions for a charity
     */
    public function countTransactions(int $charityId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM transactions WHERE charity_id = ? AND is_deleted = FALSE";
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $sql .= " AND transaction_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND transaction_date <= ?";
            $params[] = $filters['date_to'];
        }

        $result = $this->db->queryOne($sql, $params);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Get a single transaction with its entries
     */
    public function get(int $id, int $charityId): ?array
    {
        $transaction = $this->db->queryOne(
            "SELECT t.*, 
                    oc.name as category_name,
                    rc.name as reporting_category_name,
                    d.name as donor_name
             FROM transactions t
             JOIN operational_categories oc ON oc.id = t.operational_category_id
             JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE t.id = ? AND t.charity_id = ?",
            [$id, $charityId]
        );

        if (!$transaction) {
            return null;
        }

        // Load fund entries
        $transaction['fund_entries'] = $this->getFundEntries($id);
        
        // Load cashbook entries
        $transaction['cashbook_entries'] = $this->getCashbookEntries($id);

        // For backwards compatibility, set fund_name from primary entry
        if (!empty($transaction['fund_entries'])) {
            $transaction['fund_name'] = $transaction['fund_entries'][0]['fund_name'];
            $transaction['fund_id'] = $transaction['fund_entries'][0]['fund_id'];
        }

        return $transaction;
    }

    /**
     * Get fund entries for a transaction
     */
    public function getFundEntries(int $transactionId): array
    {
        return $this->db->query(
            "SELECT tfe.*, f.name as fund_name, f.fund_type
             FROM transaction_fund_entries tfe
             JOIN funds f ON f.id = tfe.fund_id
             WHERE tfe.transaction_id = ?
             ORDER BY tfe.id",
            [$transactionId]
        );
    }

    /**
     * Get cashbook entries for a transaction
     */
    public function getCashbookEntries(int $transactionId): array
    {
        return $this->db->query(
            "SELECT tce.*, cb.name as cash_book_name, cb.book_type
             FROM transaction_cashbook_entries tce
             JOIN cash_books cb ON cb.id = tce.cash_book_id
             WHERE tce.transaction_id = ?
             ORDER BY tce.id",
            [$transactionId]
        );
    }

    /**
     * Create a transaction
     * 
     * Supports both simple mode (single fund_id, optional cash_book_id) and
     * split mode (fund_entries array, cashbook_entries array).
     */
    public function create(int $charityId, int $userId, array $data): int
    {
        $this->validate($data, $charityId);

        // Get category to determine transaction type
        $category = $this->categoryService->getOperationalCategory(
            $data['operational_category_id'],
            $charityId
        );

        $transactionType = $category['category_type'];

        // Determine Gift Aid status
        $giftaidStatus = $this->determineGiftAidStatus($data, $category, $charityId);

        // Normalize fund entries
        $fundEntries = $this->normalizeFundEntries($data, $transactionType);
        
        // Normalize cashbook entries
        $cashbookEntries = $this->normalizeCashbookEntries($data, $transactionType);

        // Validate entries
        $this->validateEntries($fundEntries, $cashbookEntries, (float) $data['amount'], $transactionType);

        $this->db->beginTransaction();

        try {
            // Insert transaction (keep legacy columns for backwards compatibility during transition)
            $primaryFundId = $fundEntries[0]['fund_id'] ?? null;
            $primaryCashbookId = $cashbookEntries[0]['cash_book_id'] ?? null;

            $id = $this->db->insert('transactions', [
                'charity_id' => $charityId,
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => $transactionType,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => $primaryFundId, // Legacy column
                'operational_category_id' => $data['operational_category_id'],
                'donor_id' => $data['donor_id'] ?? null,
                'has_oneoff_declaration' => $data['has_oneoff_declaration'] ?? false,
                'giftaid_status' => $giftaidStatus,
                'cash_book_id' => $primaryCashbookId, // Legacy column
                'created_by_user_id' => $userId,
            ]);

            // Insert fund entries
            foreach ($fundEntries as $entry) {
                $this->db->insert('transaction_fund_entries', [
                    'transaction_id' => $id,
                    'fund_id' => $entry['fund_id'],
                    'amount' => $entry['amount'],
                ]);
            }

            // Insert cashbook entries
            foreach ($cashbookEntries as $entry) {
                $this->db->insert('transaction_cashbook_entries', [
                    'transaction_id' => $id,
                    'cash_book_id' => $entry['cash_book_id'],
                    'amount' => $entry['amount'],
                    'is_reconciled' => false,
                ]);
            }

            // Create audit trail
            $this->auditService->logTransaction($charityId, $id, 'created', $userId);

            $this->db->commit();

            $this->logger->info('Transaction created', [
                'charity_id' => $charityId,
                'transaction_id' => $id,
                'user_id' => $userId,
                'fund_entries' => count($fundEntries),
                'cashbook_entries' => count($cashbookEntries),
            ]);

            return $id;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Update a transaction
     */
    public function update(int $id, int $charityId, int $userId, array $data): void
    {
        $existing = $this->get($id, $charityId);
        if (!$existing) {
            throw new ValidationException(['id' => 'Transaction not found']);
        }

        // Cannot edit if claimed
        if ($existing['giftaid_status'] === 'claimed') {
            throw new ValidationException(['giftaid' => 'Cannot edit a claimed transaction']);
        }

        $this->validate($data, $charityId);

        // Get category to determine transaction type
        $category = $this->categoryService->getOperationalCategory(
            $data['operational_category_id'],
            $charityId
        );

        $transactionType = $category['category_type'];
        $giftaidStatus = $this->determineGiftAidStatus($data, $category, $charityId);

        // Normalize entries
        $fundEntries = $this->normalizeFundEntries($data, $transactionType);
        $cashbookEntries = $this->normalizeCashbookEntries($data, $transactionType);

        // Validate entries
        $this->validateEntries($fundEntries, $cashbookEntries, (float) $data['amount'], $transactionType);

        // Track changes
        $changes = $this->getChanges($existing, $data);

        $this->db->beginTransaction();

        try {
            // Update transaction
            $primaryFundId = $fundEntries[0]['fund_id'] ?? null;
            $primaryCashbookId = $cashbookEntries[0]['cash_book_id'] ?? null;

            $this->db->update('transactions', [
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => $transactionType,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => $primaryFundId, // Legacy column
                'operational_category_id' => $data['operational_category_id'],
                'donor_id' => $data['donor_id'] ?? null,
                'has_oneoff_declaration' => $data['has_oneoff_declaration'] ?? false,
                'giftaid_status' => $giftaidStatus,
                'cash_book_id' => $primaryCashbookId, // Legacy column
                'updated_by_user_id' => $userId,
            ], 'id = ? AND charity_id = ?', [$id, $charityId]);

            // Replace fund entries
            $this->db->execute("DELETE FROM transaction_fund_entries WHERE transaction_id = ?", [$id]);
            foreach ($fundEntries as $entry) {
                $this->db->insert('transaction_fund_entries', [
                    'transaction_id' => $id,
                    'fund_id' => $entry['fund_id'],
                    'amount' => $entry['amount'],
                ]);
            }

            // Replace cashbook entries (preserve reconciliation status where possible)
            $existingCashbookEntries = $existing['cashbook_entries'] ?? [];
            $this->db->execute("DELETE FROM transaction_cashbook_entries WHERE transaction_id = ?", [$id]);
            
            foreach ($cashbookEntries as $entry) {
                // Try to preserve reconciliation status from matching existing entry
                $isReconciled = false;
                $reconciledAt = null;
                foreach ($existingCashbookEntries as $existingEntry) {
                    if ($existingEntry['cash_book_id'] == $entry['cash_book_id'] && $existingEntry['is_reconciled']) {
                        $isReconciled = true;
                        $reconciledAt = $existingEntry['reconciled_at'];
                        break;
                    }
                }

                $this->db->insert('transaction_cashbook_entries', [
                    'transaction_id' => $id,
                    'cash_book_id' => $entry['cash_book_id'],
                    'amount' => $entry['amount'],
                    'is_reconciled' => $isReconciled,
                    'reconciled_at' => $reconciledAt,
                ]);
            }

            // Create audit trail
            $this->auditService->logTransaction($charityId, $id, 'updated', $userId, $changes);

            $this->db->commit();

            $this->logger->info('Transaction updated', [
                'charity_id' => $charityId,
                'transaction_id' => $id,
                'user_id' => $userId,
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Normalize fund entries from input data
     * 
     * Accepts either:
     * - Simple mode: fund_id (single fund gets full amount)
     * - Split mode: fund_entries array [{fund_id, amount}, ...]
     */
    private function normalizeFundEntries(array $data, string $transactionType): array
    {
        // Split mode: fund_entries array provided
        if (!empty($data['fund_entries']) && is_array($data['fund_entries'])) {
            $entries = [];
            foreach ($data['fund_entries'] as $entry) {
                if (!empty($entry['fund_id']) && isset($entry['amount'])) {
                    $entries[] = [
                        'fund_id' => (int) $entry['fund_id'],
                        'amount' => (float) $entry['amount'],
                    ];
                }
            }
            return $entries;
        }

        // Simple mode: single fund_id
        if (!empty($data['fund_id'])) {
            $amount = (float) $data['amount'];
            // Convert to signed amount based on transaction type
            $signedAmount = $transactionType === 'receipt' ? $amount : -$amount;
            
            return [[
                'fund_id' => (int) $data['fund_id'],
                'amount' => $signedAmount,
            ]];
        }

        return [];
    }

    /**
     * Normalize cashbook entries from input data
     * 
     * Accepts either:
     * - Simple mode: cash_book_id (single cashbook gets full amount)
     * - Split mode: cashbook_entries array [{cash_book_id, amount}, ...]
     */
    private function normalizeCashbookEntries(array $data, string $transactionType): array
    {
        // Split mode: cashbook_entries array provided
        if (!empty($data['cashbook_entries']) && is_array($data['cashbook_entries'])) {
            $entries = [];
            foreach ($data['cashbook_entries'] as $entry) {
                if (!empty($entry['cash_book_id']) && isset($entry['amount'])) {
                    $entries[] = [
                        'cash_book_id' => (int) $entry['cash_book_id'],
                        'amount' => (float) $entry['amount'],
                    ];
                }
            }
            return $entries;
        }

        // Simple mode: single cash_book_id
        if (!empty($data['cash_book_id'])) {
            $amount = (float) $data['amount'];
            // Convert to signed amount based on transaction type
            $signedAmount = $transactionType === 'receipt' ? $amount : -$amount;
            
            return [[
                'cash_book_id' => (int) $data['cash_book_id'],
                'amount' => $signedAmount,
            ]];
        }

        return [];
    }

    /**
     * Validate that entries are consistent
     */
    private function validateEntries(array $fundEntries, array $cashbookEntries, float $amount, string $transactionType): void
    {
        $errors = [];

        // Must have at least one fund entry
        if (empty($fundEntries)) {
            $errors['fund_entries'] = 'At least one fund must be specified';
        }

        // Fund entries must sum to transaction amount (with correct sign)
        $fundSum = array_sum(array_column($fundEntries, 'amount'));
        $expectedSum = $transactionType === 'receipt' ? $amount : -$amount;
        
        if (abs($fundSum - $expectedSum) > 0.01) {
            $errors['fund_entries'] = sprintf(
                'Fund entries must sum to £%.2f (currently £%.2f)',
                abs($expectedSum),
                abs($fundSum)
            );
        }

        // If cashbook entries exist, they must sum to the same as fund entries
        if (!empty($cashbookEntries)) {
            $cashbookSum = array_sum(array_column($cashbookEntries, 'amount'));
            if (abs($cashbookSum - $fundSum) > 0.01) {
                $errors['cashbook_entries'] = sprintf(
                    'Cashbook entries must sum to £%.2f (currently £%.2f)',
                    abs($fundSum),
                    abs($cashbookSum)
                );
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Soft delete a transaction
     */
    public function delete(int $id, int $charityId, int $userId): void
    {
        $existing = $this->get($id, $charityId);
        if (!$existing) {
            throw new ValidationException(['id' => 'Transaction not found']);
        }

        // Cannot delete if claimed
        if ($existing['giftaid_status'] === 'claimed') {
            throw new ValidationException(['giftaid' => 'Cannot delete a claimed transaction']);
        }

        $this->db->beginTransaction();

        try {
            $this->db->execute(
                "UPDATE transactions 
                 SET is_deleted = TRUE, deleted_at = NOW(), deleted_by_user_id = ?
                 WHERE id = ? AND charity_id = ?",
                [$userId, $id, $charityId]
            );

            $this->auditService->logTransaction($charityId, $id, 'deleted', $userId);

            $this->db->commit();

            $this->logger->info('Transaction deleted', [
                'charity_id' => $charityId,
                'transaction_id' => $id,
                'user_id' => $userId,
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Validate transaction data
     */
    private function validate(array $data, int $charityId): void
    {
        $errors = [];

        if (empty($data['transaction_date'])) {
            $errors['transaction_date'] = 'Date is required';
        }

        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors['amount'] = 'Amount must be a positive number';
        }

        if (empty($data['description'])) {
            $errors['description'] = 'Description is required';
        }

        // Fund is required - either as fund_id (simple mode) or fund_entries (split mode)
        if (empty($data['fund_id']) && empty($data['fund_entries'])) {
            $errors['fund_id'] = 'Fund is required';
        }

        if (empty($data['operational_category_id'])) {
            $errors['operational_category_id'] = 'Category is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Determine Gift Aid status for a transaction
     */
    private function determineGiftAidStatus(array $data, array $category, int $charityId): string
    {
        // If it's a payment, not applicable
        if ($category['category_type'] === 'payment') {
            return 'not_applicable';
        }

        // If explicitly marked as GASDS
        if (!empty($data['is_gasds'])) {
            return 'gasds';
        }

        // If has one-off declaration
        if (!empty($data['has_oneoff_declaration'])) {
            return 'eligible';
        }

        // If no donor, check if category suggests Gift Aid eligibility
        if (empty($data['donor_id'])) {
            return $category['giftaid_eligible_default'] ? 'not_eligible' : 'not_applicable';
        }

        // Has donor - check for valid declaration
        if ($this->hasValidDeclaration($data['donor_id'], $data['transaction_date'])) {
            return 'eligible';
        }

        return 'not_eligible';
    }

    /**
     * Check if donor has valid declaration covering the date
     */
    private function hasValidDeclaration(int $donorId, string $date): bool
    {
        $count = $this->db->queryValue(
            "SELECT COUNT(*) FROM declarations 
             WHERE donor_id = ? 
             AND start_date <= ?
             AND (withdrawn_date IS NULL OR withdrawn_date >= ?)",
            [$donorId, $date, $date]
        );

        return $count > 0;
    }

    /**
     * Track changes between old and new data
     */
    private function getChanges(array $old, array $new): array
    {
        $changes = [];
        $fields = ['transaction_date', 'amount', 'description', 'reference', 'fund_id', 'operational_category_id', 'donor_id', 'cash_book_id'];

        foreach ($fields as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;

            if ($oldVal != $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        return $changes;
    }

    /**
     * Get transaction totals by category for reporting
     */
    public function getTotalsByCategory(
        int $charityId,
        string $dateFrom,
        string $dateTo,
        ?int $fundId = null
    ): array {
        $sql = "SELECT 
                    rc.id as reporting_category_id,
                    rc.name as reporting_category_name,
                    rc.category_type,
                    rc.display_order,
                    COALESCE(SUM(t.amount), 0) as total
                FROM reporting_categories rc
                LEFT JOIN operational_categories oc ON oc.reporting_category_id = rc.id
                LEFT JOIN transactions t ON t.operational_category_id = oc.id 
                    AND t.charity_id = ?
                    AND t.is_deleted = FALSE
                    AND t.transaction_date >= ?
                    AND t.transaction_date <= ?";
        
        $params = [$charityId, $dateFrom, $dateTo];

        if ($fundId) {
            // Filter by fund using entries table for multi-fund support
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_fund_entries tfe WHERE tfe.transaction_id = t.id AND tfe.fund_id = ?)";
            $params[] = $fundId;
        }

        $sql .= " WHERE rc.charity_id = ? AND rc.is_active = TRUE
                  GROUP BY rc.id, rc.name, rc.category_type, rc.display_order
                  ORDER BY rc.category_type, rc.display_order";
        
        $params[] = $charityId;

        return $this->db->query($sql, $params);
    }

    /**
     * Find a potential duplicate transaction
     */
    public function findDuplicate(int $charityId, array $criteria): ?array
    {
        $sql = "SELECT id, transaction_date, description, amount
                FROM transactions
                WHERE charity_id = ?
                  AND is_deleted = FALSE
                  AND transaction_date = ?
                  AND ABS(amount - ?) < 0.01
                  AND LOWER(description) = LOWER(?)
                LIMIT 1";
        
        $result = $this->db->query($sql, [
            $charityId,
            $criteria['transaction_date'],
            $criteria['amount'],
            $criteria['description']
        ]);
        
        return $result[0] ?? null;
    }
}
