<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing transactions
 *
 * Transactions support many-to-many relationships with funds, categories, and cashbooks:
 * - A transaction can be split across multiple funds (transaction_fund_entries)
 * - A transaction can be split across multiple categories (transaction_category_entries)
 * - A transaction can be split across multiple cashbooks (transaction_cashbook_entries)
 * - Fund entries are required; category entries are required; cashbook entries are optional
 * - All category entries must be of the same type (all receipt OR all payment)
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
     * Note: For transactions with multiple fund/category entries, this returns the primary
     * (first entry). Use getFundEntries()/getCategoryEntries() for full breakdown.
     */
    public function getTransactions(int $charityId, array $filters = []): array
    {
        // Use subqueries to get primary fund and category (for display) - first entries
        $sql = "SELECT t.*,
                       pf.name as fund_name,
                       pf.id as primary_fund_id,
                       poc.name as category_name,
                       prc.name as reporting_category_name,
                       d.name as donor_name,
                       (SELECT COUNT(*) FROM transaction_fund_entries WHERE transaction_id = t.id) as fund_entry_count,
                       (SELECT COUNT(*) FROM transaction_category_entries WHERE transaction_id = t.id) as category_entry_count,
                       (SELECT COUNT(*) FROM transaction_cashbook_entries WHERE transaction_id = t.id) as cashbook_entry_count
                FROM transactions t
                LEFT JOIN transaction_fund_entries tfe_primary ON tfe_primary.id = (
                    SELECT id FROM transaction_fund_entries WHERE transaction_id = t.id ORDER BY ABS(amount) DESC LIMIT 1
                )
                LEFT JOIN funds pf ON pf.id = tfe_primary.fund_id
                LEFT JOIN transaction_category_entries tce_primary ON tce_primary.id = (
                    SELECT id FROM transaction_category_entries WHERE transaction_id = t.id ORDER BY ABS(amount) DESC LIMIT 1
                )
                LEFT JOIN operational_categories poc ON poc.id = COALESCE(tce_primary.operational_category_id, t.operational_category_id)
                LEFT JOIN reporting_categories prc ON prc.id = poc.reporting_category_id
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
            // Filter by category using entries table for multi-category support
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_category_entries tce WHERE tce.transaction_id = t.id AND tce.operational_category_id = ?)";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['reporting_category_id'])) {
            // Filter by reporting category using entries table for multi-category support
            $sql .= " AND EXISTS (
                SELECT 1 FROM transaction_category_entries tce
                JOIN operational_categories oc2 ON oc2.id = tce.operational_category_id
                WHERE tce.transaction_id = t.id AND oc2.reporting_category_id = ?
            )";
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

            if (!empty($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int) $filters['offset'];
            }
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Count transactions for a charity
     */
    public function countTransactions(int $charityId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as cnt
                FROM transactions t
                JOIN operational_categories oc ON oc.id = t.operational_category_id
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
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_fund_entries tfe WHERE tfe.transaction_id = t.id AND tfe.fund_id = ?)";
            $params[] = $filters['fund_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_category_entries tce WHERE tce.transaction_id = t.id AND tce.operational_category_id = ?)";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND t.transaction_type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND t.description LIKE ?";
            $params[] = '%' . $filters['search'] . '%';
        }

        $result = $this->db->queryOne($sql, $params);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Get receipt and payment totals for filters
     */
    public function getTotals(int $charityId, array $filters = []): array
    {
        $sql = "SELECT t.transaction_type, SUM(t.amount) as total
                FROM transactions t
                JOIN operational_categories oc ON oc.id = t.operational_category_id
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
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_fund_entries tfe WHERE tfe.transaction_id = t.id AND tfe.fund_id = ?)";
            $params[] = $filters['fund_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM transaction_category_entries tce WHERE tce.transaction_id = t.id AND tce.operational_category_id = ?)";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND t.transaction_type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND t.description LIKE ?";
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql .= " GROUP BY t.transaction_type";

        $results = $this->db->query($sql, $params);

        $totals = ['receipts' => 0, 'payments' => 0];
        foreach ($results as $row) {
            if ($row['transaction_type'] === 'receipt') {
                $totals['receipts'] = (float) $row['total'];
            } else {
                $totals['payments'] = (float) $row['total'];
            }
        }

        return $totals;
    }

    /**
     * Get a single transaction with its entries
     */
    public function get(int $id, int $charityId): ?array
    {
        $transaction = $this->db->queryOne(
            "SELECT t.*,
                    d.name as donor_name
             FROM transactions t
             LEFT JOIN donors d ON d.id = t.donor_id
             WHERE t.id = ? AND t.charity_id = ?",
            [$id, $charityId]
        );

        if (!$transaction) {
            return null;
        }

        // Load fund entries
        $transaction['fund_entries'] = $this->getFundEntries($id);

        // Load category entries
        $transaction['category_entries'] = $this->getCategoryEntries($id);

        // Load cashbook entries
        $transaction['cashbook_entries'] = $this->getCashbookEntries($id);

        // For backwards compatibility, set names from primary entries
        if (!empty($transaction['fund_entries'])) {
            $transaction['fund_name'] = $transaction['fund_entries'][0]['fund_name'];
            $transaction['fund_id'] = $transaction['fund_entries'][0]['fund_id'];
        }

        if (!empty($transaction['category_entries'])) {
            $transaction['category_name'] = $transaction['category_entries'][0]['category_name'];
            $transaction['reporting_category_name'] = $transaction['category_entries'][0]['reporting_category_name'];
        } elseif (!empty($transaction['operational_category_id'])) {
            // Fallback to legacy column
            $category = $this->categoryService->getOperationalCategory((int) $transaction['operational_category_id'], $charityId);
            $transaction['category_name'] = $category['name'] ?? null;
            $transaction['reporting_category_name'] = $category['reporting_category_name'] ?? null;
        } else {
            $transaction['category_name'] = null;
            $transaction['reporting_category_name'] = null;
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
     * Get category entries for a transaction
     */
    public function getCategoryEntries(int $transactionId): array
    {
        return $this->db->query(
            "SELECT tce.*, oc.name as category_name, oc.giftaid_eligible_default,
                    rc.name as reporting_category_name, rc.category_type
             FROM transaction_category_entries tce
             JOIN operational_categories oc ON oc.id = tce.operational_category_id
             JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
             WHERE tce.transaction_id = ?
             ORDER BY tce.id",
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
     * Supports both simple mode (single fund_id, category_id, optional cash_book_id) and
     * split mode (fund_entries, category_entries, cashbook_entries arrays).
     */
    public function create(int $charityId, int $userId, array $data): int
    {
        // Determine transaction type
        $transactionType = $data['transaction_type'] ?? null;

        // Normalize category entries first (even for transfers, to detect if they were provided)
        $categoryEntries = $this->normalizeCategoryEntries($data, $charityId);

        // If not explicitly set, determine from categories
        if (!$transactionType || !in_array($transactionType, ['receipt', 'payment', 'transfer'])) {
            $transactionType = $this->validateCategoryEntries($categoryEntries, $charityId);
        } else {
            // Validate categories for non-transfers
            if ($transactionType !== 'transfer' && !empty($categoryEntries)) {
                $this->validateCategoryEntries($categoryEntries, $charityId);
            }
        }

        $this->validate($data, $charityId);

        // Gift Aid only applies to receipts with categories
        $giftaidStatus = 'not_applicable';
        if ($transactionType === 'receipt' && !empty($categoryEntries)) {
            $firstCategory = $this->categoryService->getOperationalCategory(
                $categoryEntries[0]['operational_category_id'],
                $charityId
            );
            $giftaidStatus = $this->determineGiftAidStatus($data, $firstCategory, $charityId);
        }

        // Normalize fund entries
        $fundEntries = $this->normalizeFundEntries($data, $transactionType);

        // Normalize cashbook entries (even for transfers, to detect if they were provided)
        $cashbookEntries = $this->normalizeCashbookEntries($data, $transactionType);

        // Apply sign to category entries (not applicable for transfers)
        if ($transactionType !== 'transfer') {
            foreach ($categoryEntries as &$entry) {
                $entry['amount'] = $transactionType === 'receipt' ? abs($entry['amount']) : -abs($entry['amount']);
            }
            unset($entry);
        }

        // Validate entries
        $this->validateEntries($fundEntries, $categoryEntries, $cashbookEntries, (float) $data['amount'], $transactionType);

        $this->db->beginTransaction();

        try {
            // Insert transaction (keep legacy columns for backwards compatibility during transition)
            $primaryFundId = $fundEntries[0]['fund_id'] ?? null;
            $primaryCategoryId = $categoryEntries[0]['operational_category_id'] ?? null;
            $primaryCashbookId = $cashbookEntries[0]['cash_book_id'] ?? null;

            $insertData = [
                'charity_id' => $charityId,
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => $transactionType,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => $primaryFundId, // Legacy column
                'operational_category_id' => $primaryCategoryId, // Legacy column (now uses entries table)
                'cash_book_id' => $primaryCashbookId, // Legacy column
                'created_by_user_id' => $userId,
            ];

            // Only set donor/giftaid fields for receipts/payments
            if ($transactionType !== 'transfer') {
                $insertData['donor_id'] = $data['donor_id'] ?? null;
                $insertData['has_oneoff_declaration'] = $data['has_oneoff_declaration'] ?? false;
                $insertData['giftaid_status'] = $giftaidStatus;
            } else {
                $insertData['donor_id'] = null;
                $insertData['has_oneoff_declaration'] = false;
                $insertData['giftaid_status'] = 'not_applicable';
            }

            $id = $this->db->insert('transactions', $insertData);

            // Insert fund entries
            foreach ($fundEntries as $entry) {
                $this->db->insert('transaction_fund_entries', [
                    'transaction_id' => $id,
                    'fund_id' => $entry['fund_id'],
                    'amount' => $entry['amount'],
                ]);
            }

            // Insert category entries
            foreach ($categoryEntries as $entry) {
                $this->db->insert('transaction_category_entries', [
                    'transaction_id' => $id,
                    'operational_category_id' => $entry['operational_category_id'],
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
                'category_entries' => count($categoryEntries),
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

        // Determine transaction type
        $transactionType = $data['transaction_type'] ?? null;

        // Normalize category entries first (even for transfers, to detect if they were provided)
        $categoryEntries = $this->normalizeCategoryEntries($data, $charityId);

        // If not explicitly set, determine from categories
        if (!$transactionType || !in_array($transactionType, ['receipt', 'payment', 'transfer'])) {
            $transactionType = $this->validateCategoryEntries($categoryEntries, $charityId);
        } else {
            // Validate categories for non-transfers
            if ($transactionType !== 'transfer' && !empty($categoryEntries)) {
                $this->validateCategoryEntries($categoryEntries, $charityId);
            }
        }

        $this->validate($data, $charityId);

        // Gift Aid only applies to receipts with categories
        $giftaidStatus = 'not_applicable';
        if ($transactionType === 'receipt' && !empty($categoryEntries)) {
            $firstCategory = $this->categoryService->getOperationalCategory(
                $categoryEntries[0]['operational_category_id'],
                $charityId
            );
            $giftaidStatus = $this->determineGiftAidStatus($data, $firstCategory, $charityId);
        }

        // Normalize entries
        $fundEntries = $this->normalizeFundEntries($data, $transactionType);
        // Normalize cashbook entries (even for transfers, to detect if they were provided)
        $cashbookEntries = $this->normalizeCashbookEntries($data, $transactionType);

        // Apply sign to category entries (not applicable for transfers)
        if ($transactionType !== 'transfer') {
            foreach ($categoryEntries as &$entry) {
                $entry['amount'] = $transactionType === 'receipt' ? abs($entry['amount']) : -abs($entry['amount']);
            }
            unset($entry);
        }

        // Validate entries
        $this->validateEntries($fundEntries, $categoryEntries, $cashbookEntries, (float) $data['amount'], $transactionType);

        // Track changes
        $changes = $this->getChanges($existing, $data);

        $this->db->beginTransaction();

        try {
            // Update transaction
            $primaryFundId = $fundEntries[0]['fund_id'] ?? null;
            $primaryCategoryId = $categoryEntries[0]['operational_category_id'] ?? null;
            $primaryCashbookId = $cashbookEntries[0]['cash_book_id'] ?? null;

            $updateData = [
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => $transactionType,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => $primaryFundId, // Legacy column
                'operational_category_id' => $primaryCategoryId, // Legacy column
                'cash_book_id' => $primaryCashbookId, // Legacy column
                'updated_by_user_id' => $userId,
            ];

            // Only set donor/giftaid fields for receipts/payments
            if ($transactionType !== 'transfer') {
                $updateData['donor_id'] = $data['donor_id'] ?? null;
                $updateData['has_oneoff_declaration'] = $data['has_oneoff_declaration'] ?? false;
                $updateData['giftaid_status'] = $giftaidStatus;
            } else {
                $updateData['donor_id'] = null;
                $updateData['has_oneoff_declaration'] = false;
                $updateData['giftaid_status'] = 'not_applicable';
            }

            $this->db->update('transactions', $updateData, 'id = ? AND charity_id = ?', [$id, $charityId]);

            // Replace fund entries
            $this->db->execute("DELETE FROM transaction_fund_entries WHERE transaction_id = ?", [$id]);
            foreach ($fundEntries as $entry) {
                $this->db->insert('transaction_fund_entries', [
                    'transaction_id' => $id,
                    'fund_id' => $entry['fund_id'],
                    'amount' => $entry['amount'],
                ]);
            }

            // Replace category entries
            $this->db->execute("DELETE FROM transaction_category_entries WHERE transaction_id = ?", [$id]);
            foreach ($categoryEntries as $entry) {
                $this->db->insert('transaction_category_entries', [
                    'transaction_id' => $id,
                    'operational_category_id' => $entry['operational_category_id'],
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
                    $amount = (float) $entry['amount'];

                    // For transfers, amounts are already signed (positive/negative)
                    // For receipts/payments, convert to signed amount based on transaction type
                    if ($transactionType === 'transfer') {
                        $signedAmount = $amount;
                    } else {
                        $signedAmount = $transactionType === 'receipt' ? abs($amount) : -abs($amount);
                    }

                    $entries[] = [
                        'fund_id' => (int) $entry['fund_id'],
                        'amount' => $signedAmount,
                    ];
                }
            }
            return $entries;
        }

        // Simple mode: single fund_id (not applicable for transfers)
        if (!empty($data['fund_id']) && $transactionType !== 'transfer') {
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
                    $amount = (float) $entry['amount'];

                    // For transfers, amounts are already signed (positive/negative)
                    // For receipts/payments, convert to signed amount based on transaction type
                    if ($transactionType === 'transfer') {
                        $signedAmount = $amount;
                    } else {
                        $signedAmount = $transactionType === 'receipt' ? abs($amount) : -abs($amount);
                    }

                    $entries[] = [
                        'cash_book_id' => (int) $entry['cash_book_id'],
                        'amount' => $signedAmount,
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
     * Normalize category entries from input data
     *
     * Accepts either:
     * - Simple mode: operational_category_id (single category gets full amount)
     * - Split mode: category_entries array [{operational_category_id, amount}, ...]
     *
     * Note: Sign is NOT applied here - it's applied after validation determines the transaction type
     */
    private function normalizeCategoryEntries(array $data, int $charityId): array
    {
        // Split mode: category_entries array provided
        if (!empty($data['category_entries']) && is_array($data['category_entries'])) {
            $entries = [];
            foreach ($data['category_entries'] as $entry) {
                if (!empty($entry['operational_category_id']) && isset($entry['amount'])) {
                    $entries[] = [
                        'operational_category_id' => (int) $entry['operational_category_id'],
                        'amount' => (float) $entry['amount'], // Unsigned at this point
                    ];
                }
            }
            return $entries;
        }

        // Simple mode: single operational_category_id
        if (!empty($data['operational_category_id'])) {
            return [[
                'operational_category_id' => (int) $data['operational_category_id'],
                'amount' => (float) ($data['amount'] ?? 0), // Unsigned at this point
            ]];
        }

        return [];
    }

    /**
     * Validate category entries and determine transaction type
     *
     * All categories must be of the same type (all receipt OR all payment).
     * Returns the transaction type.
     */
    private function validateCategoryEntries(array $categoryEntries, int $charityId): string
    {
        if (empty($categoryEntries)) {
            throw new ValidationException(['category' => 'At least one category must be specified']);
        }

        $transactionType = null;

        foreach ($categoryEntries as $entry) {
            $category = $this->categoryService->getOperationalCategory(
                $entry['operational_category_id'],
                $charityId
            );

            if (!$category) {
                throw new ValidationException(['category' => 'Invalid category specified']);
            }

            if ($transactionType === null) {
                $transactionType = $category['category_type'];
            } elseif ($category['category_type'] !== $transactionType) {
                throw new ValidationException([
                    'category' => sprintf(
                        "Category '%s' is a %s, but this transaction is a %s. All categories must be the same type.",
                        $category['name'],
                        $category['category_type'],
                        $transactionType
                    )
                ]);
            }
        }

        return $transactionType;
    }

    /**
     * Validate that entries are consistent
     */
    private function validateEntries(array $fundEntries, array $categoryEntries, array $cashbookEntries, float $amount, string $transactionType): void
    {
        $errors = [];

        // Must have at least one fund entry
        // Transfers have special validation rules
        if ($transactionType === 'transfer') {
            $hasFundTransfer = count($fundEntries) >= 2;
            $hasCashbookTransfer = count($cashbookEntries) >= 2;

            // Must have at least one type of transfer (fund or cashbook)
            if (!$hasFundTransfer && !$hasCashbookTransfer) {
                $errors['entries'] = 'Transfers must have at least 2 fund entries or 2 cashbook entries';
            }

            // If fund entries provided, they must sum to zero
            if (!empty($fundEntries)) {
                $fundSum = array_sum(array_column($fundEntries, 'amount'));
                if (abs($fundSum) > 0.01) {
                    $errors['fund_entries'] = sprintf(
                        'Transfer fund entries must balance to zero (currently £%.2f)',
                        abs($fundSum)
                    );
                }
            }

            // If cashbook entries provided, they must sum to zero
            if (!empty($cashbookEntries)) {
                $cashbookSum = array_sum(array_column($cashbookEntries, 'amount'));
                if (abs($cashbookSum) > 0.01) {
                    $errors['cashbook_entries'] = sprintf(
                        'Transfer cashbook entries must balance to zero (currently £%.2f)',
                        abs($cashbookSum)
                    );
                }
            }

            // Transfers should not have categories
            if (!empty($categoryEntries)) {
                $errors['category_entries'] = 'Transfers should not have categories';
            }
        } else {
            // Regular receipts/payments require at least one fund
            if (empty($fundEntries)) {
                $errors['fund_entries'] = 'At least one fund must be specified';
            }
            // Regular receipts/payments validation
            // Must have at least one category entry
            if (empty($categoryEntries)) {
                $errors['category_entries'] = 'At least one category must be specified';
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

            // Category entries must sum to transaction amount (with correct sign)
            $categorySum = array_sum(array_column($categoryEntries, 'amount'));
            if (abs($categorySum - $expectedSum) > 0.01) {
                $errors['category_entries'] = sprintf(
                    'Category entries must sum to £%.2f (currently £%.2f)',
                    abs($expectedSum),
                    abs($categorySum)
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

        $transactionType = $data['transaction_type'] ?? null;

        // Fund is required for receipts/payments, optional for transfers (which can be cashbook-only)
        if ($transactionType !== 'transfer') {
            if (empty($data['fund_id']) && empty($data['fund_entries'])) {
                $errors['fund_id'] = 'Fund is required';
            }
            // Category is required for receipts and payments (not for transfers)
            if (empty($data['operational_category_id']) && empty($data['category_entries'])) {
                $errors['operational_category_id'] = 'Category is required';
            }
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
     * Uses category entries table to properly handle split transactions
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
                    COALESCE(SUM(ABS(tce.amount)), 0) as total
                FROM reporting_categories rc
                LEFT JOIN operational_categories oc ON oc.reporting_category_id = rc.id
                LEFT JOIN transaction_category_entries tce ON tce.operational_category_id = oc.id
                LEFT JOIN transactions t ON t.id = tce.transaction_id
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
