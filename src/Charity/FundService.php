<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service for managing funds
 */
class FundService
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Get all funds for a charity
     */
    public function getAll(int $charityId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM funds WHERE charity_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }
        $sql .= " ORDER BY display_order, name";

        return $this->db->query($sql, [$charityId]);
    }

    /**
     * Get a single fund
     */
    public function get(int $id, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM funds WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Create a fund
     */
    public function create(int $charityId, array $data): int
    {
        return $this->db->insert('funds', [
            'charity_id' => $charityId,
            'name' => $data['name'],
            'fund_type' => $data['fund_type'],
            'opening_balance' => $data['opening_balance'] ?? 0,
            'opening_balance_date' => $data['opening_balance_date'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * Update a fund
     */
    public function update(int $id, int $charityId, array $data): void
    {
        $this->db->update('funds', $data, 'id = ? AND charity_id = ?', [$id, $charityId]);
    }

    /**
     * Deactivate a fund (cannot delete if transactions exist)
     */
    public function deactivate(int $id, int $charityId): void
    {
        $this->db->execute(
            "UPDATE funds SET is_active = FALSE WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Get fund balance (opening + sum of fund entries)
     *
     * Uses transaction_fund_entries table for accurate multi-fund support.
     * Only sums transactions from opening_balance_date onwards to avoid double-counting.
     */
    public function getBalance(int $fundId, int $charityId, ?string $asOfDate = null): array
    {
        $fund = $this->get($fundId, $charityId);
        if (!$fund) {
            return ['opening' => 0, 'receipts' => 0, 'payments' => 0, 'transfers_in' => 0, 'transfers_out' => 0, 'balance' => 0];
        }

        $dateConditions = [];
        $params = [$fundId];

        // Only sum transactions from opening_balance_date onwards
        // (opening balance already includes transactions up to that date)
        if (!empty($fund['opening_balance_date'])) {
            $dateConditions[] = 't.transaction_date > ?';
            $params[] = $fund['opening_balance_date'];
        }

        // Optionally filter to specific date
        if ($asOfDate) {
            $dateConditions[] = 't.transaction_date <= ?';
            $params[] = $asOfDate;
        }

        $dateCondition = !empty($dateConditions) ? 'AND ' . implode(' AND ', $dateConditions) : '';

        // Sum from transaction_fund_entries
        // Track receipts, payments, and transfers separately
        $totals = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN t.transaction_type = 'receipt' THEN ABS(tfe.amount) ELSE 0 END), 0) as receipts,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'payment' THEN ABS(tfe.amount) ELSE 0 END), 0) as payments,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount > 0 THEN tfe.amount ELSE 0 END), 0) as transfers_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount < 0 THEN ABS(tfe.amount) ELSE 0 END), 0) as transfers_out
             FROM transaction_fund_entries tfe
             JOIN transactions t ON t.id = tfe.transaction_id
             WHERE tfe.fund_id = ? AND t.is_deleted = FALSE $dateCondition",
            $params
        );

        $opening = (float) $fund['opening_balance'];
        $receipts = (float) $totals['receipts'];
        $payments = (float) $totals['payments'];
        $transfersIn = (float) $totals['transfers_in'];
        $transfersOut = (float) $totals['transfers_out'];

        return [
            'opening' => $opening,
            'receipts' => $receipts,
            'payments' => $payments,
            'transfers_in' => $transfersIn,
            'transfers_out' => $transfersOut,
            'balance' => $opening + $receipts - $payments + $transfersIn - $transfersOut,
        ];
    }

    /**
     * Get all fund balances
     */
    public function getAllBalances(int $charityId, ?string $asOfDate = null): array
    {
        $funds = $this->getAll($charityId);
        $result = [];

        foreach ($funds as $fund) {
            $balance = $this->getBalance($fund['id'], $charityId, $asOfDate);
            $result[] = array_merge($fund, $balance);
        }

        return $result;
    }
}
