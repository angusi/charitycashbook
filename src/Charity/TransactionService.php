<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Money;
use CharityCashbook\Core\Exceptions\ValidationException;

/**
 * Service for managing transactions
 *
 * Ledger model:
 * - Receipts and payments are made of lines: each line has an operational category,
 *   a fund and an amount (transaction_category_entries.fund_id). The lines sum exactly
 *   to the transaction amount. Fund entries (transaction_fund_entries) are derived from
 *   the lines, one per fund, so fund balances and category reports always agree.
 * - Transfers have no categories: they move money between funds (fund entries summing
 *   to zero) and/or between cash books (cash book entries summing to zero).
 * - Cash book entries are optional for receipts/payments and must sum to the amount.
 * - All amounts are validated in integer pence. Stored amounts are signed:
 *   positive for receipts / money in, negative for payments / money out.
 */
class TransactionService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private CategoryService $categoryService;
    private AuditService $auditService;
    private DonorService $donorService;

    /** Largest single donation that may be claimed under GASDS */
    public const GASDS_MAX_DONATION = '30.00';

    public function __construct(
        DatabaseInterface $db,
        Logger $logger,
        CategoryService $categoryService,
        AuditService $auditService,
        DonorService $donorService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->categoryService = $categoryService;
        $this->auditService = $auditService;
        $this->donorService = $donorService;
    }

    // =========================================================================
    // Queries
    // =========================================================================

    /**
     * Get transactions with filters
     *
     * Note: fund_name / category_name / cashbook_name are the largest entry, for display.
     * When a fund, cash book or category filter is applied, filtered_amount is the part
     * of the transaction that falls within the filter.
     */
    public function getTransactions(int $charityId, array $filters = []): array
    {
        [$where, $whereParams] = $this->buildFilterSql($charityId, $filters);
        [$amountExpr, $amountParams] = $this->filteredAmountSql($filters);

        $sql = "SELECT t.*,
                       pf.name as fund_name,
                       pf.id as primary_fund_id,
                       poc.name as category_name,
                       prc.name as reporting_category_name,
                       pcb.name as cashbook_name,
                       d.name as donor_name,
                       " . ($amountExpr ?? 'NULL') . " as filtered_amount,
                       (SELECT COUNT(DISTINCT fund_id) FROM transaction_fund_entries WHERE transaction_id = t.id) as fund_entry_count,
                       (SELECT COUNT(*) FROM transaction_category_entries WHERE transaction_id = t.id) as category_entry_count,
                       (SELECT COUNT(*) FROM transaction_cashbook_entries WHERE transaction_id = t.id) as cashbook_entry_count
                FROM transactions t
                LEFT JOIN transaction_fund_entries tfe_primary ON tfe_primary.id = (
                    SELECT id FROM transaction_fund_entries WHERE transaction_id = t.id ORDER BY ABS(amount) DESC, id LIMIT 1
                )
                LEFT JOIN funds pf ON pf.id = tfe_primary.fund_id
                LEFT JOIN transaction_category_entries tce_primary ON tce_primary.id = (
                    SELECT id FROM transaction_category_entries WHERE transaction_id = t.id ORDER BY ABS(amount) DESC, id LIMIT 1
                )
                LEFT JOIN operational_categories poc ON poc.id = COALESCE(tce_primary.operational_category_id, t.operational_category_id)
                LEFT JOIN reporting_categories prc ON prc.id = poc.reporting_category_id
                LEFT JOIN transaction_cashbook_entries tcbe_primary ON tcbe_primary.id = (
                    SELECT id FROM transaction_cashbook_entries WHERE transaction_id = t.id ORDER BY ABS(amount) DESC, id LIMIT 1
                )
                LEFT JOIN cash_books pcb ON pcb.id = tcbe_primary.cash_book_id
                LEFT JOIN donors d ON d.id = t.donor_id AND d.charity_id = t.charity_id
                WHERE $where
                ORDER BY t.transaction_date DESC, t.id DESC";

        $params = array_merge($amountParams, $whereParams);

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . max(1, (int) $filters['limit']);

            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . max(0, (int) $filters['offset']);
            }
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Count transactions matching the same filters as getTransactions()
     */
    public function countTransactions(int $charityId, array $filters = []): int
    {
        [$where, $params] = $this->buildFilterSql($charityId, $filters);

        return (int) $this->db->queryValue("SELECT COUNT(*) FROM transactions t WHERE $where", $params);
    }

    /**
     * Totals for the same filters as getTransactions()
     *
     * receipts/payments are positive totals. transfers is the gross amount transferred,
     * or - when a fund or cash book filter is applied - the net amount transferred in (+)
     * or out (-) of that fund / cash book.
     *
     * @return array{receipts: float, payments: float, transfers: float}
     */
    public function getTotals(int $charityId, array $filters = []): array
    {
        [$where, $whereParams] = $this->buildFilterSql($charityId, $filters);
        [$amountExpr, $amountParams] = $this->filteredAmountSql($filters);
        $amountExpr ??= 't.amount';

        $rows = $this->db->query(
            "SELECT t.transaction_type, SUM($amountExpr) as total
             FROM transactions t
             WHERE $where
             GROUP BY t.transaction_type",
            array_merge($amountParams, $whereParams)
        );

        $totals = ['receipts' => 0.0, 'payments' => 0.0, 'transfers' => 0.0];
        foreach ($rows as $row) {
            $total = (float) $row['total'];
            match ($row['transaction_type']) {
                'receipt' => $totals['receipts'] = abs($total),
                'payment' => $totals['payments'] = abs($total),
                default => $totals['transfers'] = $total,
            };
        }

        return $totals;
    }

    /**
     * Shared WHERE clause for list, count and totals (so they always agree)
     *
     * @return array{0: string, 1: array}
     */
    private function buildFilterSql(int $charityId, array $filters): array
    {
        $where = ['t.charity_id = ?', 't.is_deleted = FALSE'];
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $where[] = 't.transaction_date >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 't.transaction_date <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['fund_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM transaction_fund_entries tfe WHERE tfe.transaction_id = t.id AND tfe.fund_id = ?)';
            $params[] = (int) $filters['fund_id'];
        }

        if (!empty($filters['cash_book_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM transaction_cashbook_entries tcbe WHERE tcbe.transaction_id = t.id AND tcbe.cash_book_id = ?)';
            $params[] = (int) $filters['cash_book_id'];
        }

        if (!empty($filters['type'])) {
            $where[] = 't.transaction_type = ?';
            $params[] = $filters['type'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM transaction_category_entries tce WHERE tce.transaction_id = t.id AND tce.operational_category_id = ?)';
            $params[] = (int) $filters['category_id'];
        }

        if (!empty($filters['reporting_category_id'])) {
            $where[] = 'EXISTS (
                SELECT 1 FROM transaction_category_entries tce
                JOIN operational_categories oc2 ON oc2.id = tce.operational_category_id
                WHERE tce.transaction_id = t.id AND oc2.reporting_category_id = ?
            )';
            $params[] = (int) $filters['reporting_category_id'];
        }

        if (!empty($filters['giftaid_status'])) {
            $where[] = 't.giftaid_status = ?';
            $params[] = $filters['giftaid_status'];
        }

        if (!empty($filters['needs_review'])) {
            $where[] = 't.allocation_needs_review = TRUE';
        }

        if (!empty($filters['search'])) {
            $where[] = 't.description LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * SQL for the part of a transaction's amount that falls within a category, fund or
     * cash book filter (signed), or null when no such filter is applied.
     *
     * @return array{0: ?string, 1: array}
     */
    private function filteredAmountSql(array $filters): array
    {
        $fundId = !empty($filters['fund_id']) ? (int) $filters['fund_id'] : null;

        if (!empty($filters['category_id']) || !empty($filters['reporting_category_id'])) {
            $sql = "(SELECT COALESCE(SUM(tcx.amount), 0) FROM transaction_category_entries tcx
                     JOIN operational_categories ocx ON ocx.id = tcx.operational_category_id
                     WHERE tcx.transaction_id = t.id";
            $params = [];
            if (!empty($filters['category_id'])) {
                $sql .= " AND tcx.operational_category_id = ?";
                $params[] = (int) $filters['category_id'];
            }
            if (!empty($filters['reporting_category_id'])) {
                $sql .= " AND ocx.reporting_category_id = ?";
                $params[] = (int) $filters['reporting_category_id'];
            }
            if ($fundId) {
                $sql .= " AND tcx.fund_id = ?";
                $params[] = $fundId;
            }

            return [$sql . ")", $params];
        }

        if ($fundId) {
            return [
                "(SELECT COALESCE(SUM(tfx.amount), 0) FROM transaction_fund_entries tfx WHERE tfx.transaction_id = t.id AND tfx.fund_id = ?)",
                [$fundId],
            ];
        }

        if (!empty($filters['cash_book_id'])) {
            return [
                "(SELECT COALESCE(SUM(tbx.amount), 0) FROM transaction_cashbook_entries tbx WHERE tbx.transaction_id = t.id AND tbx.cash_book_id = ?)",
                [(int) $filters['cash_book_id']],
            ];
        }

        return [null, []];
    }

    /**
     * Get a single transaction with its entries
     */
    public function get(int $id, int $charityId): ?array
    {
        $transaction = $this->db->queryOne(
            "SELECT t.*,
                    d.name as donor_name,
                    gc.status as giftaid_claim_status
             FROM transactions t
             LEFT JOIN donors d ON d.id = t.donor_id AND d.charity_id = t.charity_id
             LEFT JOIN giftaid_claims gc ON gc.id = t.giftaid_claim_id
             WHERE t.id = ? AND t.charity_id = ?",
            [$id, $charityId]
        );

        if (!$transaction) {
            return null;
        }

        $transaction['fund_entries'] = $this->getFundEntries($id);
        $transaction['category_entries'] = $this->getCategoryEntries($id);
        $transaction['cashbook_entries'] = $this->getCashbookEntries($id);
        $transaction['is_reconciled_any'] = !empty(array_filter(
            $transaction['cashbook_entries'],
            fn($e) => (bool) $e['is_reconciled']
        ));

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
     * Get category lines for a transaction (each with its fund)
     */
    public function getCategoryEntries(int $transactionId): array
    {
        return $this->db->query(
            "SELECT tce.*, oc.name as category_name, oc.giftaid_eligible_default,
                    rc.name as reporting_category_name, rc.category_type,
                    f.name as fund_name, f.fund_type
             FROM transaction_category_entries tce
             JOIN operational_categories oc ON oc.id = tce.operational_category_id
             JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
             LEFT JOIN funds f ON f.id = tce.fund_id
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

    // =========================================================================
    // Create / update / delete
    // =========================================================================

    /**
     * Create a transaction
     *
     * Accepts, for receipts/payments, either `lines` ([{operational_category_id, fund_id, amount}])
     * or the simple fields (operational_category_id + fund_id), or a split on one dimension only
     * (category_entries with a single fund_id, or fund_entries with a single category).
     * Transfers take fund_entries and/or cashbook_entries with signed amounts.
     */
    public function create(int $charityId, int $userId, array $data): int
    {
        $charity = $this->getCharitySettings($charityId);
        $ledger = $this->normaliseLedger($charityId, $data);

        BooksLock::assertUnlocked($charity['books_locked_until'], $ledger['transaction_date']);

        [$giftaidStatus, $giftaidAmount] = $this->determineGiftAid($charityId, $charity, $data, $ledger, null);

        $this->db->beginTransaction();

        try {
            $id = $this->db->insert('transactions', array_merge(
                $this->transactionRow($ledger, $data),
                $this->giftAidRow($ledger, $data, $giftaidStatus, $giftaidAmount),
                [
                    'charity_id' => $charityId,
                    'created_by_user_id' => $userId,
                ]
            ));

            $this->writeEntries($id, $ledger, []);

            $this->auditService->logTransaction($charityId, $id, 'created', $userId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Transaction created', [
            'charity_id' => $charityId,
            'transaction_id' => $id,
            'user_id' => $userId,
            'lines' => count($ledger['lines']),
            'cashbook_entries' => count($ledger['cashbook_entries']),
        ]);

        return $id;
    }

    /**
     * Update a transaction
     *
     * Some transactions are partly locked:
     * - in a closed financial year: no changes at all
     * - claimed for Gift Aid, or in a draft claim: amount, date, type, donor, lines and
     *   Gift Aid details cannot change (correct a submitted claim with a Gift Aid correction)
     * - reconciled to a bank statement: amount, date, type and cash book entries cannot change
     */
    public function update(int $id, int $charityId, int $userId, array $data): void
    {
        $existing = $this->get($id, $charityId);
        if (!$existing || $existing['is_deleted']) {
            throw new ValidationException(['id' => 'Transaction not found']);
        }

        $charity = $this->getCharitySettings($charityId);
        BooksLock::assertUnlocked($charity['books_locked_until'], $existing['transaction_date']);

        $inClaim = $existing['giftaid_status'] === 'claimed' || !empty($existing['giftaid_claim_id']);
        $isReconciled = (bool) $existing['is_reconciled_any'];

        // Description/reference-only edit of a locked transaction (form may omit other fields)
        if (($inClaim || $isReconciled) && !$this->hasLedgerFields($data)) {
            $this->updateDescriptionOnly($id, $charityId, $userId, $data, $existing);
            return;
        }

        $data['transaction_type'] = $data['transaction_type'] ?? $existing['transaction_type'];
        $ledger = $this->normaliseLedger($charityId, $data);

        BooksLock::assertUnlocked($charity['books_locked_until'], $ledger['transaction_date']);

        $restricted = ($inClaim || $isReconciled)
            ? $this->restrictedChanges($existing, $ledger, $data, $inClaim, $isReconciled)
            : [];
        if ($restricted) {
            $reason = $inClaim
                ? (($existing['giftaid_status'] === 'claimed')
                    ? 'it has been claimed for Gift Aid. To correct the claim, add a Gift Aid correction instead.'
                    : "it is in draft Gift Aid claim #{$existing['giftaid_claim_id']}. Remove it from the claim first.")
                : 'it has been reconciled to a bank statement.';
            throw new ValidationException([
                'locked' => 'Cannot change ' . implode(', ', $restricted) . ' because ' . $reason,
            ]);
        }

        if ($inClaim) {
            // Gift Aid details are frozen while the transaction is in a claim
            $giftAidRow = [
                'donor_id' => $existing['donor_id'],
                'has_oneoff_declaration' => (bool) $existing['has_oneoff_declaration'],
                'giftaid_status' => $existing['giftaid_status'],
                'giftaid_amount' => $existing['giftaid_amount'],
                'giftaid_eligibility_override' => $existing['giftaid_eligibility_override'],
            ];
        } else {
            [$giftaidStatus, $giftaidAmount] = $this->determineGiftAid($charityId, $charity, $data, $ledger, $existing);
            $giftAidRow = $this->giftAidRow($ledger, $data, $giftaidStatus, $giftaidAmount);
        }

        $changes = $this->getChanges($existing, $ledger, $data);

        $this->db->beginTransaction();

        try {
            $this->db->update(
                'transactions',
                array_merge($this->transactionRow($ledger, $data), $giftAidRow, [
                    'updated_by_user_id' => $userId,
                    'allocation_needs_review' => false,
                ]),
                'id = ? AND charity_id = ?',
                [$id, $charityId]
            );

            $this->writeEntries($id, $ledger, $existing['cashbook_entries'] ?? []);

            $this->auditService->logTransaction($charityId, $id, 'updated', $userId, $changes);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Transaction updated', [
            'charity_id' => $charityId,
            'transaction_id' => $id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Soft delete a transaction
     */
    public function delete(int $id, int $charityId, int $userId): void
    {
        $existing = $this->get($id, $charityId);
        if (!$existing || $existing['is_deleted']) {
            throw new ValidationException(['id' => 'Transaction not found']);
        }

        $charity = $this->getCharitySettings($charityId);
        BooksLock::assertUnlocked($charity['books_locked_until'], $existing['transaction_date']);

        if ($existing['giftaid_status'] === 'claimed') {
            throw new ValidationException(['giftaid' => 'Cannot delete a transaction that has been claimed for Gift Aid']);
        }

        if (!empty($existing['giftaid_claim_id'])) {
            throw new ValidationException([
                'giftaid' => "Cannot delete a transaction in draft Gift Aid claim #{$existing['giftaid_claim_id']}. Remove it from the claim first.",
            ]);
        }

        if ($existing['is_reconciled_any']) {
            throw new ValidationException(['reconciled' => 'Cannot delete a transaction that has been reconciled to a bank statement']);
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
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->logger->info('Transaction deleted', [
            'charity_id' => $charityId,
            'transaction_id' => $id,
            'user_id' => $userId,
        ]);
    }

    // =========================================================================
    // Normalisation and validation
    // =========================================================================

    /**
     * Validate input and turn it into a ledger: lines, fund entries and cash book entries,
     * all checked to belong to the charity and to add up exactly.
     *
     * @return array{
     *   transaction_type: string, transaction_date: string, amount: string,
     *   lines: array<int, array{operational_category_id: int, fund_id: int, amount: string}>,
     *   fund_entries: array<int, array{fund_id: int, amount: string}>,
     *   cashbook_entries: array<int, array{cash_book_id: int, amount: string}>,
     *   donor_id: ?int
     * }
     */
    private function normaliseLedger(int $charityId, array $data): array
    {
        $errors = [];

        $date = $data['transaction_date'] ?? null;
        if (!FinancialYear::isValidDate($date)) {
            $errors['transaction_date'] = 'A valid date is required';
        }

        $amountPence = Money::isValid($data['amount'] ?? null) ? Money::toPence($data['amount']) : 0;
        if ($amountPence <= 0 || !Money::isValid($data['amount'] ?? null)) {
            $errors['amount'] = 'Amount must be a positive amount in pounds and pence';
        }

        if (trim((string) ($data['description'] ?? '')) === '') {
            $errors['description'] = 'Description is required';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $requestedType = $data['transaction_type'] ?? null;
        if ($requestedType !== null && $requestedType !== '' && !in_array($requestedType, ['receipt', 'payment', 'transfer'], true)) {
            throw new ValidationException(['transaction_type' => 'Invalid transaction type']);
        }

        if ($requestedType === 'transfer') {
            return $this->normaliseTransfer($charityId, $data, (string) $date, $amountPence);
        }

        $lines = $this->buildLines($data, $amountPence);

        // Categories: must belong to the charity and all be the same type
        $type = null;
        foreach ($lines as $line) {
            $category = $this->categoryService->getOperationalCategory($line['operational_category_id'], $charityId);
            if (!$category) {
                throw new ValidationException(['category' => 'Invalid category specified']);
            }
            if ($type === null) {
                $type = $category['category_type'];
            } elseif ($category['category_type'] !== $type) {
                throw new ValidationException([
                    'category' => sprintf(
                        "Category '%s' is a %s, but this transaction is a %s. All categories must be the same type.",
                        $category['name'],
                        $category['category_type'],
                        $type
                    ),
                ]);
            }
        }

        if ($requestedType && $requestedType !== $type) {
            throw new ValidationException([
                'category' => "This is a $requestedType, but the categories chosen are for {$type}s",
            ]);
        }

        // Funds must belong to the charity
        $this->assertFundsBelong($charityId, array_column($lines, 'fund_id'));

        // Lines must add up exactly
        $linesPence = array_sum(array_map(fn($l) => $l['pence'], $lines));
        if ($linesPence !== $amountPence) {
            throw new ValidationException([
                'lines' => sprintf(
                    'Split lines must add up to £%s (currently £%s)',
                    Money::fromPence($amountPence),
                    Money::fromPence($linesPence)
                ),
            ]);
        }

        $sign = $type === 'receipt' ? 1 : -1;

        // Derive one fund entry per fund from the lines
        $fundPence = [];
        foreach ($lines as $line) {
            $fundPence[$line['fund_id']] = ($fundPence[$line['fund_id']] ?? 0) + $line['pence'];
        }
        $fundEntries = [];
        foreach ($fundPence as $fundId => $pence) {
            $fundEntries[] = ['fund_id' => (int) $fundId, 'amount' => Money::fromPence($sign * $pence)];
        }

        // Cash book entries (optional): must belong to the charity and add up to the amount
        $cashbookEntries = $this->buildCashbookEntries($charityId, $data, $amountPence, $sign);
        if ($cashbookEntries) {
            $cashbookPence = array_sum(array_map(fn($e) => abs(Money::toPence($e['amount'])), $cashbookEntries));
            if ($cashbookPence !== $amountPence) {
                throw new ValidationException([
                    'cashbook_entries' => sprintf(
                        'Cash book entries must add up to £%s (currently £%s)',
                        Money::fromPence($amountPence),
                        Money::fromPence($cashbookPence)
                    ),
                ]);
            }
        }

        // Donor must belong to the charity
        $donorId = !empty($data['donor_id']) ? (int) $data['donor_id'] : null;
        if ($donorId !== null && !$this->donorService->getNameOnly($donorId, $charityId)) {
            throw new ValidationException(['donor_id' => 'Donor not found']);
        }

        return [
            'transaction_type' => $type,
            'transaction_date' => (string) $date,
            'amount' => Money::fromPence($amountPence),
            'lines' => array_map(fn($l) => [
                'operational_category_id' => $l['operational_category_id'],
                'fund_id' => $l['fund_id'],
                'amount' => Money::fromPence($sign * $l['pence']),
            ], $lines),
            'fund_entries' => $fundEntries,
            'cashbook_entries' => $cashbookEntries,
            'donor_id' => $donorId,
        ];
    }

    /**
     * Build unsigned lines from the posted data
     *
     * @return array<int, array{operational_category_id: int, fund_id: int, pence: int}>
     */
    private function buildLines(array $data, int $amountPence): array
    {
        // Explicit lines: category + fund + amount per row
        if (!empty($data['lines']) && is_array($data['lines'])) {
            $lines = [];
            foreach ($data['lines'] as $row) {
                if (!is_array($row) || (empty($row['operational_category_id']) && empty($row['fund_id']) && ($row['amount'] ?? '') === '')) {
                    continue; // blank row
                }
                $lines[] = $this->makeLine($row['operational_category_id'] ?? null, $row['fund_id'] ?? null, $row['amount'] ?? null);
            }
            if (!$lines) {
                throw new ValidationException(['lines' => 'At least one line is required']);
            }
            return $lines;
        }

        $categories = [];
        if (!empty($data['category_entries']) && is_array($data['category_entries'])) {
            foreach ($data['category_entries'] as $row) {
                if (is_array($row) && !empty($row['operational_category_id'])) {
                    $categories[] = $row;
                }
            }
        } elseif (!empty($data['operational_category_id'])) {
            $categories[] = ['operational_category_id' => $data['operational_category_id'], 'amount' => Money::fromPence($amountPence)];
        }

        $funds = [];
        if (!empty($data['fund_entries']) && is_array($data['fund_entries'])) {
            foreach ($data['fund_entries'] as $row) {
                if (is_array($row) && !empty($row['fund_id'])) {
                    $funds[] = $row;
                }
            }
        } elseif (!empty($data['fund_id'])) {
            $funds[] = ['fund_id' => $data['fund_id'], 'amount' => Money::fromPence($amountPence)];
        }

        if (!$categories) {
            throw new ValidationException(['category' => 'At least one category must be specified']);
        }
        if (!$funds) {
            throw new ValidationException(['fund_id' => 'At least one fund must be specified']);
        }

        if (count($funds) === 1) {
            return array_map(
                fn($c) => $this->makeLine($c['operational_category_id'], $funds[0]['fund_id'], $c['amount'] ?? null),
                $categories
            );
        }

        if (count($categories) === 1) {
            return array_map(
                fn($f) => $this->makeLine($categories[0]['operational_category_id'], $f['fund_id'], $f['amount'] ?? null),
                $funds
            );
        }

        throw new ValidationException([
            'lines' => 'When a transaction is split across several funds and several categories, give each line its own category and fund',
        ]);
    }

    /**
     * @return array{operational_category_id: int, fund_id: int, pence: int}
     */
    private function makeLine(mixed $categoryId, mixed $fundId, mixed $amount): array
    {
        if (empty($categoryId)) {
            throw new ValidationException(['lines' => 'Every line needs a category']);
        }
        if (empty($fundId)) {
            throw new ValidationException(['lines' => 'Every line needs a fund']);
        }
        if (!Money::isValid($amount) || Money::toPence($amount) <= 0) {
            throw new ValidationException(['lines' => 'Every line needs a positive amount in pounds and pence']);
        }

        return [
            'operational_category_id' => (int) $categoryId,
            'fund_id' => (int) $fundId,
            'pence' => Money::toPence($amount),
        ];
    }

    /**
     * Signed cash book entries for a receipt/payment
     *
     * @return array<int, array{cash_book_id: int, amount: string}>
     */
    private function buildCashbookEntries(int $charityId, array $data, int $amountPence, int $sign): array
    {
        $entries = [];

        if (!empty($data['cashbook_entries']) && is_array($data['cashbook_entries'])) {
            foreach ($data['cashbook_entries'] as $row) {
                if (!is_array($row) || empty($row['cash_book_id'])) {
                    continue;
                }
                if (!Money::isValid($row['amount'] ?? null) || Money::toPence($row['amount']) <= 0) {
                    throw new ValidationException(['cashbook_entries' => 'Every cash book entry needs a positive amount']);
                }
                $entries[] = [
                    'cash_book_id' => (int) $row['cash_book_id'],
                    'amount' => Money::fromPence($sign * Money::toPence($row['amount'])),
                ];
            }
        } elseif (!empty($data['cash_book_id'])) {
            $entries[] = [
                'cash_book_id' => (int) $data['cash_book_id'],
                'amount' => Money::fromPence($sign * $amountPence),
            ];
        }

        $this->assertCashBooksBelong($charityId, array_column($entries, 'cash_book_id'));

        return $entries;
    }

    /**
     * Validate a transfer: fund entries and/or cash book entries, each summing to zero
     */
    private function normaliseTransfer(int $charityId, array $data, string $date, int $amountPence): array
    {
        $collect = function (string $key, string $idKey) use ($data): array {
            $entries = [];
            if (!empty($data[$key]) && is_array($data[$key])) {
                foreach ($data[$key] as $row) {
                    if (!is_array($row) || empty($row[$idKey])) {
                        continue;
                    }
                    if (!Money::isValid($row['amount'] ?? null) || Money::toPence($row['amount']) === 0) {
                        throw new ValidationException([$key => 'Every transfer entry needs a non-zero amount']);
                    }
                    $entries[] = [$idKey => (int) $row[$idKey], 'amount' => Money::normalise($row['amount'])];
                }
            }
            return $entries;
        };

        $fundEntries = $collect('fund_entries', 'fund_id');
        $cashbookEntries = $collect('cashbook_entries', 'cash_book_id');

        $hasCategories = !empty($data['lines'])
            || !empty($data['operational_category_id'])
            || (!empty($data['category_entries']) && is_array($data['category_entries']) && array_filter($data['category_entries'], fn($r) => !empty($r['operational_category_id'])));
        if ($hasCategories) {
            throw new ValidationException(['category_entries' => 'Transfers should not have categories']);
        }

        $errors = [];
        $hasFundTransfer = count(array_unique(array_column($fundEntries, 'fund_id'))) >= 2;
        $hasCashbookTransfer = count(array_unique(array_column($cashbookEntries, 'cash_book_id'))) >= 2;
        if (!$hasFundTransfer && !$hasCashbookTransfer) {
            $errors['entries'] = 'Transfers must move money between at least 2 different funds or 2 different cashbooks';
        }

        foreach (['fund_entries' => $fundEntries, 'cashbook_entries' => $cashbookEntries] as $key => $entries) {
            if (!$entries) {
                continue;
            }
            $label = $key === 'fund_entries' ? 'fund' : 'cashbook';
            $net = Money::sumPence(array_column($entries, 'amount'));
            if ($net !== 0) {
                $errors[$key] = sprintf('Transfer %s entries must balance to zero (currently £%s)', $label, Money::fromPence(abs($net)));
                continue;
            }
            $moved = array_sum(array_filter(array_map(fn($e) => Money::toPence($e['amount']), $entries), fn($p) => $p > 0));
            if ($moved !== $amountPence) {
                $errors[$key] = sprintf(
                    'Transfer %s entries move £%s but the transfer amount is £%s',
                    $label,
                    Money::fromPence($moved),
                    Money::fromPence($amountPence)
                );
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $this->assertFundsBelong($charityId, array_column($fundEntries, 'fund_id'));
        $this->assertCashBooksBelong($charityId, array_column($cashbookEntries, 'cash_book_id'));

        return [
            'transaction_type' => 'transfer',
            'transaction_date' => $date,
            'amount' => Money::fromPence($amountPence),
            'lines' => [],
            'fund_entries' => $fundEntries,
            'cashbook_entries' => $cashbookEntries,
            'donor_id' => null,
        ];
    }

    /**
     * @param int[] $fundIds
     */
    private function assertFundsBelong(int $charityId, array $fundIds): void
    {
        $fundIds = array_values(array_unique(array_map('intval', $fundIds)));
        if (!$fundIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($fundIds), '?'));
        $found = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM funds WHERE charity_id = ? AND id IN ($placeholders)",
            array_merge([$charityId], $fundIds)
        );
        if ($found !== count($fundIds)) {
            throw new ValidationException(['fund_id' => 'Invalid fund specified']);
        }
    }

    /**
     * @param int[] $cashBookIds
     */
    private function assertCashBooksBelong(int $charityId, array $cashBookIds): void
    {
        $cashBookIds = array_values(array_unique(array_map('intval', $cashBookIds)));
        if (!$cashBookIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($cashBookIds), '?'));
        $found = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM cash_books WHERE charity_id = ? AND id IN ($placeholders)",
            array_merge([$charityId], $cashBookIds)
        );
        if ($found !== count($cashBookIds)) {
            throw new ValidationException(['cash_book_id' => 'Invalid cash book specified']);
        }
    }

    /**
     * Whether the posted data carries ledger fields (not just a description/reference edit)
     */
    private function hasLedgerFields(array $data): bool
    {
        foreach (['amount', 'transaction_date', 'lines', 'fund_id', 'fund_entries', 'operational_category_id', 'category_entries', 'cash_book_id', 'cashbook_entries'] as $key) {
            if (!empty($data[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fields that may not change on a claimed / reconciled transaction but would
     */
    private function restrictedChanges(array $existing, array $ledger, array $data, bool $inClaim, bool $isReconciled): array
    {
        $changed = [];

        if (Money::toPence($existing['amount']) !== Money::toPence($ledger['amount'])) {
            $changed[] = 'amount';
        }
        if ($existing['transaction_date'] !== $ledger['transaction_date']) {
            $changed[] = 'date';
        }
        if ($existing['transaction_type'] !== $ledger['transaction_type']) {
            $changed[] = 'type';
        }

        if ($inClaim) {
            if ((int) ($existing['donor_id'] ?? 0) !== (int) ($ledger['donor_id'] ?? 0)) {
                $changed[] = 'donor';
            }
            if ($this->lineKey($existing['category_entries'] ?? []) !== $this->lineKey($ledger['lines'])) {
                $changed[] = 'categories or funds';
            }
            if (isset($data['giftaid_amount']) && $data['giftaid_amount'] !== '' && $data['giftaid_amount'] !== null
                && Money::toPence($data['giftaid_amount']) !== Money::toPence($existing['giftaid_amount'])) {
                $changed[] = 'Gift Aid amount';
            }
        }

        if ($isReconciled) {
            $existingBooks = array_map(
                fn($e) => (int) $e['cash_book_id'] . ':' . Money::toPence($e['amount']),
                $existing['cashbook_entries'] ?? []
            );
            $newBooks = array_map(
                fn($e) => (int) $e['cash_book_id'] . ':' . Money::toPence($e['amount']),
                $ledger['cashbook_entries']
            );
            sort($existingBooks);
            sort($newBooks);
            if ($existingBooks !== $newBooks) {
                $changed[] = 'cash book';
            }
        }

        return array_values(array_unique($changed));
    }

    private function lineKey(array $lines): array
    {
        $keys = array_map(
            fn($l) => (int) $l['operational_category_id'] . ':' . (int) ($l['fund_id'] ?? 0) . ':' . Money::toPence($l['amount']),
            $lines
        );
        sort($keys);
        return $keys;
    }

    /**
     * Edit only description/reference (for claimed/reconciled transactions)
     */
    private function updateDescriptionOnly(int $id, int $charityId, int $userId, array $data, array $existing): void
    {
        $description = trim((string) ($data['description'] ?? $existing['description']));
        if ($description === '') {
            throw new ValidationException(['description' => 'Description is required']);
        }

        $updateData = [
            'description' => $description,
            'reference' => array_key_exists('reference', $data) ? $data['reference'] : $existing['reference'],
            'updated_by_user_id' => $userId,
        ];

        $changes = [];
        foreach (['description', 'reference'] as $field) {
            if (($existing[$field] ?? null) != $updateData[$field]) {
                $changes[$field] = ['old' => $existing[$field] ?? null, 'new' => $updateData[$field]];
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->update('transactions', $updateData, 'id = ? AND charity_id = ?', [$id, $charityId]);
            $this->auditService->logTransaction($charityId, $id, 'updated', $userId, $changes ?: null);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // =========================================================================
    // Persistence helpers
    // =========================================================================

    private function transactionRow(array $ledger, array $data): array
    {
        return [
            'transaction_date' => $ledger['transaction_date'],
            'transaction_type' => $ledger['transaction_type'],
            'amount' => $ledger['amount'],
            'description' => trim((string) $data['description']),
            'reference' => ($data['reference'] ?? '') !== '' ? $data['reference'] : null,
            // Legacy columns (primary entries) kept for backwards compatibility
            'fund_id' => $ledger['fund_entries'][0]['fund_id'] ?? null,
            'operational_category_id' => $ledger['lines'][0]['operational_category_id'] ?? null,
            'cash_book_id' => $ledger['cashbook_entries'][0]['cash_book_id'] ?? null,
        ];
    }

    private function giftAidRow(array $ledger, array $data, string $giftaidStatus, ?string $giftaidAmount): array
    {
        if ($ledger['transaction_type'] === 'transfer') {
            return [
                'donor_id' => null,
                'has_oneoff_declaration' => false,
                'giftaid_status' => 'not_applicable',
                'giftaid_amount' => null,
                'giftaid_eligibility_override' => null,
            ];
        }

        $override = $data['giftaid_eligibility_override'] ?? null;

        return [
            'donor_id' => $ledger['donor_id'],
            'has_oneoff_declaration' => !empty($data['has_oneoff_declaration']),
            'giftaid_status' => $giftaidStatus,
            'giftaid_amount' => $giftaidAmount,
            'giftaid_eligibility_override' => in_array($override, ['eligible', 'not_eligible'], true) ? $override : null,
        ];
    }

    /**
     * Replace a transaction's entries, keeping reconciliation status for unchanged cash book entries
     */
    private function writeEntries(int $id, array $ledger, array $existingCashbookEntries): void
    {
        $this->db->execute("DELETE FROM transaction_fund_entries WHERE transaction_id = ?", [$id]);
        foreach ($ledger['fund_entries'] as $entry) {
            $this->db->insert('transaction_fund_entries', [
                'transaction_id' => $id,
                'fund_id' => $entry['fund_id'],
                'amount' => $entry['amount'],
            ]);
        }

        $this->db->execute("DELETE FROM transaction_category_entries WHERE transaction_id = ?", [$id]);
        foreach ($ledger['lines'] as $line) {
            $this->db->insert('transaction_category_entries', [
                'transaction_id' => $id,
                'operational_category_id' => $line['operational_category_id'],
                'fund_id' => $line['fund_id'],
                'amount' => $line['amount'],
            ]);
        }

        // Reconciliation status is only kept for an entry with the same cash book and amount
        $reconciled = [];
        foreach ($existingCashbookEntries as $existingEntry) {
            if ($existingEntry['is_reconciled']) {
                $key = (int) $existingEntry['cash_book_id'] . ':' . Money::toPence($existingEntry['amount']);
                $reconciled[$key][] = $existingEntry['reconciled_at'];
            }
        }

        $this->db->execute("DELETE FROM transaction_cashbook_entries WHERE transaction_id = ?", [$id]);
        foreach ($ledger['cashbook_entries'] as $entry) {
            $key = (int) $entry['cash_book_id'] . ':' . Money::toPence($entry['amount']);
            $reconciledAt = !empty($reconciled[$key]) ? array_shift($reconciled[$key]) : null;

            $this->db->insert('transaction_cashbook_entries', [
                'transaction_id' => $id,
                'cash_book_id' => $entry['cash_book_id'],
                'amount' => $entry['amount'],
                'is_reconciled' => $reconciledAt !== null,
                'reconciled_at' => $reconciledAt,
            ]);
        }
    }

    /**
     * Charity settings needed when saving transactions
     */
    private function getCharitySettings(int $charityId): array
    {
        $charity = $this->db->queryOne(
            "SELECT giftaid_eligible_from_date, books_locked_until FROM charities WHERE id = ?",
            [$charityId]
        );

        return [
            'giftaid_eligible_from_date' => $charity['giftaid_eligible_from_date'] ?? null,
            'books_locked_until' => !empty($charity['books_locked_until']) ? (string) $charity['books_locked_until'] : null,
        ];
    }

    // =========================================================================
    // Gift Aid
    // =========================================================================

    /**
     * Work out Gift Aid status and amount for a receipt
     *
     * @return array{0: string, 1: ?string} [status, giftaid_amount]
     */
    private function determineGiftAid(int $charityId, array $charity, array $data, array $ledger, ?array $existing): array
    {
        if ($ledger['transaction_type'] !== 'receipt' || !$ledger['lines']) {
            return ['not_applicable', null];
        }

        $data['donor_id'] = $ledger['donor_id'];
        $data['transaction_date'] = $ledger['transaction_date'];

        if (!empty($data['is_gasds']) && Money::toPence($ledger['amount']) > Money::toPence(self::GASDS_MAX_DONATION)) {
            throw new ValidationException([
                'is_gasds' => 'Only donations of £' . self::GASDS_MAX_DONATION . ' or less can be claimed under the Gift Aid Small Donations Scheme',
            ]);
        }

        $firstCategory = $this->categoryService->getOperationalCategory(
            (int) $ledger['lines'][0]['operational_category_id'],
            $charityId
        );
        $status = $this->determineGiftAidStatus($data, $charity, $firstCategory ?? []);

        if ($existing === null) {
            $amount = $this->determineGiftAidAmount($data, $ledger, $status, $charityId);
        } else {
            $amount = $this->determineGiftAidAmountForUpdate($data, $ledger, $status, $charityId, $existing);
        }

        return [$status, $amount];
    }

    /**
     * Determine Gift Aid status for a receipt
     */
    private function determineGiftAidStatus(array $data, array $charity, array $firstCategory): string
    {
        // Before the charity's Gift Aid eligibility date
        if (!empty($charity['giftaid_eligible_from_date']) && $data['transaction_date'] < $charity['giftaid_eligible_from_date']) {
            return 'not_applicable';
        }

        $override = $data['giftaid_eligibility_override'] ?? null;

        if ($override === 'not_eligible') {
            return 'excluded';
        }

        if (!empty($data['is_gasds'])) {
            return 'gasds';
        }

        if ($override === 'eligible') {
            if (!empty($data['has_oneoff_declaration'])) {
                return 'eligible';
            }
            if (empty($data['donor_id'])) {
                return 'pending_donor';
            }
            if ($this->donorService->hasValidDeclaration((int) $data['donor_id'], $data['transaction_date'])) {
                return 'eligible';
            }
            return 'pending_declaration';
        }

        // No override - automatic
        if (!empty($data['has_oneoff_declaration'])) {
            return 'eligible';
        }

        if (empty($data['donor_id'])) {
            // Without a donor, a Gift Aid-eligible category is flagged as not (yet) eligible
            return !empty($firstCategory['giftaid_eligible_default']) ? 'not_eligible' : 'not_applicable';
        }

        if ($this->donorService->hasValidDeclaration((int) $data['donor_id'], $data['transaction_date'])) {
            return 'eligible';
        }

        return 'not_eligible';
    }

    /**
     * Gift Aid amount for a new receipt: NULL means it needs confirming before it can be claimed
     */
    private function determineGiftAidAmount(array $data, array $ledger, string $status, int $charityId): ?string
    {
        if (in_array($status, ['not_applicable', 'excluded'], true)) {
            return null;
        }

        if (isset($data['giftaid_amount']) && $data['giftaid_amount'] !== '' && $data['giftaid_amount'] !== null) {
            return $this->validateGiftAidAmount($data['giftaid_amount'], $ledger['amount']);
        }

        if (in_array($status, ['pending_donor', 'pending_declaration', 'not_eligible'], true)) {
            return null;
        }

        // Simple case: every line's category is Gift Aid eligible, so the full amount is claimable
        if ($this->isSimpleGiftAidCase($ledger['lines'], $charityId)) {
            return $ledger['amount'];
        }

        return null;
    }

    private function determineGiftAidAmountForUpdate(array $data, array $ledger, string $status, int $charityId, array $existing): ?string
    {
        if (in_array($status, ['not_applicable', 'excluded'], true)) {
            return null;
        }

        if (isset($data['giftaid_amount']) && $data['giftaid_amount'] !== '' && $data['giftaid_amount'] !== null) {
            return $this->validateGiftAidAmount($data['giftaid_amount'], $ledger['amount']);
        }

        if (in_array($status, ['pending_donor', 'pending_declaration', 'not_eligible'], true)) {
            return null;
        }

        // Anything that affects eligibility or the amount means it must be confirmed again
        $eligibilityChanged = $existing['giftaid_status'] !== $status
            || (int) ($existing['donor_id'] ?? 0) !== (int) ($ledger['donor_id'] ?? 0)
            || $existing['transaction_date'] !== $ledger['transaction_date'];
        if ($eligibilityChanged) {
            return null;
        }

        if ($existing['giftaid_amount'] !== null && Money::toPence($existing['amount']) !== Money::toPence($ledger['amount'])) {
            return null;
        }

        return $existing['giftaid_amount'] !== null ? Money::normalise($existing['giftaid_amount']) : null;
    }

    private function isSimpleGiftAidCase(array $lines, int $charityId): bool
    {
        if (!$lines) {
            return false;
        }

        foreach ($lines as $line) {
            $category = $this->categoryService->getOperationalCategory((int) $line['operational_category_id'], $charityId);
            if (empty($category['giftaid_eligible_default'])) {
                return false;
            }
        }

        return true;
    }

    private function validateGiftAidAmount(mixed $giftaidAmount, string $transactionAmount): string
    {
        if (!Money::isValid($giftaidAmount) || Money::toPence($giftaidAmount) < 0) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot be negative']);
        }
        if (Money::toPence($giftaidAmount) > Money::toPence($transactionAmount)) {
            throw new ValidationException(['giftaid_amount' => 'Gift Aid amount cannot exceed transaction amount']);
        }

        return Money::normalise($giftaidAmount);
    }

    /**
     * Track changes for the audit trail
     */
    private function getChanges(array $old, array $ledger, array $data): array
    {
        $changes = [];
        $new = [
            'transaction_date' => $ledger['transaction_date'],
            'transaction_type' => $ledger['transaction_type'],
            'amount' => $ledger['amount'],
            'description' => trim((string) $data['description']),
            'reference' => ($data['reference'] ?? '') !== '' ? $data['reference'] : null,
            'donor_id' => $ledger['donor_id'],
        ];

        foreach ($new as $field => $newVal) {
            $oldVal = $old[$field] ?? null;
            $same = $field === 'amount' ? Money::equals($oldVal, $newVal) : $oldVal == $newVal;
            if (!$same) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        if ($this->lineKey($old['category_entries'] ?? []) !== $this->lineKey($ledger['lines'])) {
            $changes['lines'] = [
                'old' => array_map(fn($l) => [
                    'operational_category_id' => (int) $l['operational_category_id'],
                    'fund_id' => (int) ($l['fund_id'] ?? 0),
                    'amount' => Money::normalise($l['amount']),
                ], $old['category_entries'] ?? []),
                'new' => $ledger['lines'],
            ];
        }

        $oldBooks = array_map(fn($e) => ['cash_book_id' => (int) $e['cash_book_id'], 'amount' => Money::normalise($e['amount'])], $old['cashbook_entries'] ?? []);
        if ($oldBooks != $ledger['cashbook_entries']) {
            $changes['cashbook_entries'] = ['old' => $oldBooks, 'new' => $ledger['cashbook_entries']];
        }

        return $changes;
    }

    // =========================================================================
    // Reporting helpers
    // =========================================================================

    /**
     * Totals by reporting category for a date range (dashboard)
     *
     * The date and deleted filters are applied before aggregating, so only transactions in
     * the range are counted. Hidden categories are included when they have activity.
     */
    public function getTotalsByCategory(
        int $charityId,
        string $dateFrom,
        string $dateTo,
        ?int $fundId = null
    ): array {
        $fundCondition = '';
        $params = [$charityId, $dateFrom, $dateTo];
        if ($fundId) {
            $fundCondition = ' AND tce.fund_id = ?';
            $params[] = $fundId;
        }
        $params[] = $charityId;

        return $this->db->query(
            "SELECT
                rc.id as reporting_category_id,
                rc.name as reporting_category_name,
                rc.category_type,
                rc.display_order,
                COALESCE(x.total, 0) as total
             FROM reporting_categories rc
             LEFT JOIN (
                SELECT oc.reporting_category_id, SUM(ABS(tce.amount)) as total
                FROM transaction_category_entries tce
                JOIN transactions t ON t.id = tce.transaction_id
                JOIN operational_categories oc ON oc.id = tce.operational_category_id
                WHERE t.charity_id = ? AND t.is_deleted = FALSE
                  AND t.transaction_date >= ? AND t.transaction_date <= ?$fundCondition
                GROUP BY oc.reporting_category_id
             ) x ON x.reporting_category_id = rc.id
             WHERE rc.charity_id = ? AND (rc.is_active = TRUE OR x.total IS NOT NULL)
             ORDER BY rc.category_type, rc.display_order",
            $params
        );
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
                  AND amount = ?
                  AND LOWER(description) = LOWER(?)
                LIMIT 1";

        $result = $this->db->query($sql, [
            $charityId,
            $criteria['transaction_date'],
            Money::normalise($criteria['amount']),
            $criteria['description']
        ]);

        return $result[0] ?? null;
    }
}
