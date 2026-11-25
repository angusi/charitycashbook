<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Money;

/**
 * Service for managing funds and calculating fund balances.
 *
 * Balance rule (used everywhere, including reports and cash books):
 * a fund's opening_balance is its position at the close of opening_balance_date.
 * Transactions dated on or before that date are already reflected in it and are
 * ignored; only later transactions move the balance. A fund with no
 * opening_balance_date counts every transaction.
 */
class FundService
{
    /** Fund types in report column order */
    public const FUND_TYPES = ['unrestricted', 'restricted', 'endowment'];

    public const FUND_TYPE_LABELS = [
        'unrestricted' => 'Unrestricted',
        'restricted' => 'Restricted',
        'endowment' => 'Endowment',
    ];

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
            'opening_balance' => Money::normalise($data['opening_balance'] ?? 0),
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
        if (array_key_exists('opening_balance', $data)) {
            $data['opening_balance'] = Money::normalise($data['opening_balance']);
        }
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
     * Whether a fund has any (non-deleted) transactions dated on or before a date
     */
    public function hasTransactionsOnOrBefore(int $fundId, int $charityId, string $date): bool
    {
        return (int) $this->db->queryValue(
            "SELECT COUNT(*)
             FROM transaction_fund_entries tfe
             JOIN transactions t ON t.id = tfe.transaction_id
             WHERE tfe.fund_id = ? AND t.charity_id = ? AND t.is_deleted = FALSE
               AND t.transaction_date <= ?",
            [$fundId, $charityId, $date]
        ) > 0;
    }

    /**
     * Get fund balance figures.
     *
     * Cumulative mode ($dateFrom null): opening is the fund's opening balance and the
     * activity is everything after the opening-balance date up to $asOfDate.
     *
     * Period mode ($dateFrom set): opening is the balance at the close of the day
     * before $dateFrom, and the activity is $dateFrom..$asOfDate.
     *
     * In both modes: balance = opening + receipts - payments + transfers_in - transfers_out.
     */
    public function getBalance(int $fundId, int $charityId, ?string $asOfDate = null, ?string $dateFrom = null): array
    {
        $fund = $this->get($fundId, $charityId);
        if (!$fund) {
            return self::emptyBalance();
        }

        return $this->calculateForFund($fund, $asOfDate, $dateFrom);
    }

    /**
     * Balance figures for an already-loaded fund row (see getBalance()).
     */
    public function calculateForFund(array $fund, ?string $asOfDate = null, ?string $dateFrom = null): array
    {
        if ($dateFrom !== null) {
            $opening = $this->balanceAt($fund, FinancialYear::addDays($dateFrom, -1));
        } else {
            $opening = (float) ($fund['opening_balance'] ?? 0);
        }

        $activity = $this->movement($fund, $dateFrom, $asOfDate);

        return [
            'opening' => $opening,
            'receipts' => $activity['receipts'],
            'payments' => $activity['payments'],
            'transfers_in' => $activity['transfers_in'],
            'transfers_out' => $activity['transfers_out'],
            'balance' => self::close($opening, $activity),
        ];
    }

    /**
     * Fund balance at the close of $date.
     */
    public function balanceAt(array $fund, string $date): float
    {
        $opening = (float) ($fund['opening_balance'] ?? 0);

        return self::close($opening, $this->movement($fund, null, $date));
    }

    /**
     * Activity for a fund between two dates (inclusive, either may be null for open-ended),
     * applying the opening-balance-date rule.
     *
     * @return array{receipts: float, payments: float, transfers_in: float, transfers_out: float}
     */
    public function movement(array $fund, ?string $dateFrom, ?string $dateTo): array
    {
        $conditions = [];
        $params = [(int) $fund['id'], (int) $fund['charity_id']];

        if (!empty($fund['opening_balance_date'])) {
            $conditions[] = 't.transaction_date > ?';
            $params[] = $fund['opening_balance_date'];
        }
        if ($dateFrom !== null) {
            $conditions[] = 't.transaction_date >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== null) {
            $conditions[] = 't.transaction_date <= ?';
            $params[] = $dateTo;
        }

        $dateCondition = $conditions ? ' AND ' . implode(' AND ', $conditions) : '';

        $totals = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN t.transaction_type = 'receipt' THEN tfe.amount ELSE 0 END), 0) as receipts,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'payment' THEN -tfe.amount ELSE 0 END), 0) as payments,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount > 0 THEN tfe.amount ELSE 0 END), 0) as transfers_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount < 0 THEN -tfe.amount ELSE 0 END), 0) as transfers_out
             FROM transaction_fund_entries tfe
             JOIN transactions t ON t.id = tfe.transaction_id
             WHERE tfe.fund_id = ? AND t.charity_id = ? AND t.is_deleted = FALSE$dateCondition",
            $params
        );

        return [
            'receipts' => (float) ($totals['receipts'] ?? 0),
            'payments' => (float) ($totals['payments'] ?? 0),
            'transfers_in' => (float) ($totals['transfers_in'] ?? 0),
            'transfers_out' => (float) ($totals['transfers_out'] ?? 0),
        ];
    }

    /**
     * Get balances for every fund that matters for the period.
     *
     * Active funds are always included. Inactive funds are included whenever they have
     * a non-zero opening, closing or activity figure, so totals always add up.
     *
     * @param int $charityId The charity ID
     * @param string|null $asOfDate End date for balance calculation
     * @param string|null $dateFrom Start date for period calculation (see getBalance())
     */
    public function getAllBalances(int $charityId, ?string $asOfDate = null, ?string $dateFrom = null): array
    {
        $result = [];

        foreach ($this->getAll($charityId, false) as $fund) {
            $balance = $this->calculateForFund($fund, $asOfDate, $dateFrom);
            if (!$fund['is_active'] && !self::hasAnyFigure($balance)) {
                continue;
            }
            $result[] = array_merge($fund, $balance);
        }

        return $result;
    }

    /**
     * Whether any balance figure is non-zero (to the penny)
     */
    public static function hasAnyFigure(array $balance): bool
    {
        foreach (['opening', 'receipts', 'payments', 'transfers_in', 'transfers_out', 'balance'] as $key) {
            if (Money::toPence($balance[$key] ?? 0) !== 0) {
                return true;
            }
        }

        return false;
    }

    public static function emptyBalance(): array
    {
        return ['opening' => 0.0, 'receipts' => 0.0, 'payments' => 0.0, 'transfers_in' => 0.0, 'transfers_out' => 0.0, 'balance' => 0.0];
    }

    private static function close(float $opening, array $activity): float
    {
        $pence = Money::toPence($opening)
            + Money::toPence($activity['receipts'])
            - Money::toPence($activity['payments'])
            + Money::toPence($activity['transfers_in'])
            - Money::toPence($activity['transfers_out']);

        return $pence / 100;
    }
}
