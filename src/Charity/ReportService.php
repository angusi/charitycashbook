<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Money;

/**
 * Service for generating reports
 *
 * Every report is built from the same two sources so that they always agree:
 * - categoryFundTotals(): receipts/payments per operational category per fund for a period
 * - FundService::balanceAt()/movement(): fund opening/closing balances and transfers
 *
 * Both apply the same rules: deleted transactions are ignored, only transactions in the
 * period are counted, and transactions dated on or before a fund's opening-balance date
 * are ignored (they are already in its opening balance). Receipts and payments are
 * classified by transaction type, exactly as fund balances are. Hidden categories and
 * inactive funds are included whenever they have activity or a balance.
 *
 * Therefore, for every fund and every column: opening + receipts - payments
 * + transfers in - transfers out = closing, and one period's closing is the next
 * period's opening.
 */
class ReportService
{
    private DatabaseInterface $db;
    private FundService $fundService;
    private CategoryService $categoryService;
    private ?CashBookService $cashBookService;

    public function __construct(
        DatabaseInterface $db,
        FundService $fundService,
        CategoryService $categoryService,
        ?CashBookService $cashBookService = null
    ) {
        $this->db = $db;
        $this->fundService = $fundService;
        $this->categoryService = $categoryService;
        $this->cashBookService = $cashBookService;
    }

    // =========================================================================
    // Building blocks
    // =========================================================================

    /**
     * Receipts and payments per operational category per fund, in pence (always positive).
     *
     * @return array<int, array{operational_category_id: int, operational_category_name: string,
     *   oc_display_order: int, reporting_category_id: int, reporting_category_name: string,
     *   rc_display_order: int, side: string, fund_id: int, pence: int, transaction_count: int}>
     */
    public function categoryFundTotals(int $charityId, string $dateFrom, string $dateTo): array
    {
        $rows = $this->db->query(
            "SELECT
                tce.operational_category_id,
                oc.name as operational_category_name,
                oc.display_order as oc_display_order,
                oc.reporting_category_id,
                rc.name as reporting_category_name,
                rc.display_order as rc_display_order,
                t.transaction_type as side,
                tce.fund_id,
                SUM(CASE WHEN t.transaction_type = 'receipt' THEN tce.amount ELSE -tce.amount END) as total,
                COUNT(DISTINCT t.id) as transaction_count
             FROM transaction_category_entries tce
             JOIN transactions t ON t.id = tce.transaction_id
             JOIN funds f ON f.id = tce.fund_id
             JOIN operational_categories oc ON oc.id = tce.operational_category_id
             JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
             WHERE t.charity_id = ?
               AND t.is_deleted = FALSE
               AND t.transaction_type IN ('receipt', 'payment')
               AND t.transaction_date >= ?
               AND t.transaction_date <= ?
               AND (f.opening_balance_date IS NULL OR t.transaction_date > f.opening_balance_date)
             GROUP BY tce.operational_category_id, oc.name, oc.display_order, oc.reporting_category_id,
                      rc.name, rc.display_order, t.transaction_type, tce.fund_id",
            [$charityId, $dateFrom, $dateTo]
        );

        return array_map(fn($r) => [
            'operational_category_id' => (int) $r['operational_category_id'],
            'operational_category_name' => $r['operational_category_name'],
            'oc_display_order' => (int) $r['oc_display_order'],
            'reporting_category_id' => (int) $r['reporting_category_id'],
            'reporting_category_name' => $r['reporting_category_name'],
            'rc_display_order' => (int) $r['rc_display_order'],
            'side' => $r['side'],
            'fund_id' => (int) $r['fund_id'],
            'pence' => Money::toPence($r['total']),
            'transaction_count' => (int) $r['transaction_count'],
        ], $rows);
    }

    /**
     * Opening/closing balances and activity for every fund that matters for the period.
     *
     * @return array<int, array> fund rows keyed by fund id, each with opening, receipts,
     *   payments, transfers_in, transfers_out, closing (pence) and the fund columns
     */
    public function fundPositions(int $charityId, string $dateFrom, string $dateTo): array
    {
        $positions = [];

        foreach ($this->fundService->getAll($charityId, false) as $fund) {
            $opening = Money::toPence($this->fundService->balanceAt($fund, FinancialYear::addDays($dateFrom, -1)));
            $movement = $this->fundService->movement($fund, $dateFrom, $dateTo);

            $position = [
                'fund' => $fund,
                'opening' => $opening,
                'receipts' => Money::toPence($movement['receipts']),
                'payments' => Money::toPence($movement['payments']),
                'transfers_in' => Money::toPence($movement['transfers_in']),
                'transfers_out' => Money::toPence($movement['transfers_out']),
            ];
            $position['closing'] = $position['opening'] + $position['receipts'] - $position['payments']
                + $position['transfers_in'] - $position['transfers_out'];

            $hasFigures = $position['opening'] || $position['receipts'] || $position['payments']
                || $position['transfers_in'] || $position['transfers_out'] || $position['closing'];
            if (!$fund['is_active'] && !$hasFigures) {
                continue;
            }

            $positions[(int) $fund['id']] = $position;
        }

        return $positions;
    }

    /**
     * Fund types that have at least one fund in the report, in standard order
     */
    private function fundTypesUsed(array $positions): array
    {
        $used = [];
        foreach ($positions as $position) {
            $used[$position['fund']['fund_type']] = true;
        }

        $types = array_values(array_filter(FundService::FUND_TYPES, fn($t) => isset($used[$t])));

        // Always show the two standard columns, even if one is empty
        foreach (['unrestricted', 'restricted'] as $standard) {
            if (!in_array($standard, $types, true)) {
                $types[] = $standard;
            }
        }

        return array_values(array_filter(FundService::FUND_TYPES, fn($t) => in_array($t, $types, true)));
    }

    /**
     * Totals per fund type (and 'total') from fund positions, converted to pounds
     */
    private function balanceTotalsByType(array $positions, array $fundTypes): array
    {
        $keys = ['opening', 'receipts', 'payments', 'transfers_in', 'transfers_out', 'closing'];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = array_fill_keys($fundTypes, 0) + ['total' => 0];
        }

        foreach ($positions as $position) {
            $type = $position['fund']['fund_type'];
            foreach ($keys as $key) {
                $totals[$key][$type] = ($totals[$key][$type] ?? 0) + $position[$key];
                $totals[$key]['total'] += $position[$key];
            }
        }

        foreach ($totals as $key => $byType) {
            $totals[$key] = array_map(fn($p) => $p / 100, $byType);
        }

        $totals['transfers_net'] = [];
        $totals['net'] = [];
        foreach (array_merge($fundTypes, ['total']) as $type) {
            $totals['transfers_net'][$type] = round($totals['transfers_in'][$type] - $totals['transfers_out'][$type], 2);
            $totals['net'][$type] = round($totals['receipts'][$type] - $totals['payments'][$type], 2);
        }

        return $totals;
    }

    /**
     * Cash book position at the end of the period, for reconciling against fund balances
     */
    public function cashPosition(int $charityId, string $asOfDate, float $totalFunds): ?array
    {
        if ($this->cashBookService === null) {
            return null;
        }

        $books = [];
        $totalPence = 0;
        foreach ($this->cashBookService->getCashBooks($charityId, false) as $book) {
            $balance = $this->cashBookService->calculateBalance((int) $book['id'], $asOfDate);
            $pence = Money::toPence($balance['current_balance']);
            if (!$book['is_active'] && $pence === 0) {
                continue;
            }
            $books[] = ['cash_book' => $book, 'balance' => $pence / 100];
            $totalPence += $pence;
        }

        if (!$books) {
            return null;
        }

        return [
            'cash_books' => $books,
            'total' => $totalPence / 100,
            'total_funds' => $totalFunds,
            'difference' => ($totalPence - Money::toPence($totalFunds)) / 100,
            'unassigned_count' => $this->cashBookService->countUnassignedTransactions($charityId),
        ];
    }

    // =========================================================================
    // Reports
    // =========================================================================

    /**
     * Receipts and Payments account: reporting categories by fund type, with opening
     * balances, transfers between funds and closing balances.
     */
    public function receiptsAndPayments(int $charityId, string $dateFrom, string $dateTo): array
    {
        $positions = $this->fundPositions($charityId, $dateFrom, $dateTo);
        $fundTypes = $this->fundTypesUsed($positions);
        $fundTypeOf = array_map(fn($p) => $p['fund']['fund_type'], $positions);

        $sides = ['receipt' => [], 'payment' => []];
        foreach ($this->categoryFundTotals($charityId, $dateFrom, $dateTo) as $row) {
            $rcId = $row['reporting_category_id'];
            $type = $fundTypeOf[$row['fund_id']] ?? 'unrestricted';
            $side = &$sides[$row['side']];

            $side[$rcId] ??= [
                'id' => $rcId,
                'name' => $row['reporting_category_name'],
                'display_order' => $row['rc_display_order'],
                'amounts' => array_fill_keys($fundTypes, 0),
                'total' => 0,
            ];
            $side[$rcId]['amounts'][$type] = ($side[$rcId]['amounts'][$type] ?? 0) + $row['pence'];
            $side[$rcId]['total'] += $row['pence'];
            unset($side);
        }

        $totals = $this->balanceTotalsByType($positions, $fundTypes);

        $report = [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'fund_types' => $fundTypes,
            'fund_type_labels' => FundService::FUND_TYPE_LABELS,
            'receipts' => $this->finishCategoryRows($sides['receipt']),
            'payments' => $this->finishCategoryRows($sides['payment']),
            'totals' => $totals,
            'cash' => $this->cashPosition($charityId, $dateTo, $totals['closing']['total']),
        ];

        return $report + $this->legacyTotals($totals);
    }

    /**
     * Detailed Receipts and Payments: as receiptsAndPayments() with operational categories
     */
    public function detailedReceiptsAndPayments(int $charityId, string $dateFrom, string $dateTo): array
    {
        $positions = $this->fundPositions($charityId, $dateFrom, $dateTo);
        $fundTypes = $this->fundTypesUsed($positions);
        $fundTypeOf = array_map(fn($p) => $p['fund']['fund_type'], $positions);

        $sides = ['receipt' => [], 'payment' => []];
        foreach ($this->categoryFundTotals($charityId, $dateFrom, $dateTo) as $row) {
            $rcId = $row['reporting_category_id'];
            $ocId = $row['operational_category_id'];
            $type = $fundTypeOf[$row['fund_id']] ?? 'unrestricted';
            $side = &$sides[$row['side']];

            $side[$rcId] ??= [
                'id' => $rcId,
                'name' => $row['reporting_category_name'],
                'display_order' => $row['rc_display_order'],
                'amounts' => array_fill_keys($fundTypes, 0),
                'total' => 0,
                'operational' => [],
            ];
            $side[$rcId]['operational'][$ocId] ??= [
                'id' => $ocId,
                'name' => $row['operational_category_name'],
                'display_order' => $row['oc_display_order'],
                'amounts' => array_fill_keys($fundTypes, 0),
                'total' => 0,
            ];

            $side[$rcId]['amounts'][$type] += $row['pence'];
            $side[$rcId]['total'] += $row['pence'];
            $side[$rcId]['operational'][$ocId]['amounts'][$type] += $row['pence'];
            $side[$rcId]['operational'][$ocId]['total'] += $row['pence'];
            unset($side);
        }

        $totals = $this->balanceTotalsByType($positions, $fundTypes);

        $report = [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'fund_types' => $fundTypes,
            'fund_type_labels' => FundService::FUND_TYPE_LABELS,
            'receipts' => $this->finishCategoryRows($sides['receipt']),
            'payments' => $this->finishCategoryRows($sides['payment']),
            'totals' => $totals,
            'cash' => $this->cashPosition($charityId, $dateTo, $totals['closing']['total']),
        ];

        return $report + $this->legacyTotals($totals);
    }

    /**
     * Sort category rows by display order, convert pence to pounds and drop empty rows
     */
    private function finishCategoryRows(array $rows): array
    {
        $finish = function (array $row): array {
            $row['amounts'] = array_map(fn($p) => $p / 100, $row['amounts']);
            $row['total'] = $row['total'] / 100;
            // Backwards-compatible keys
            $row['unrestricted'] = $row['amounts']['unrestricted'] ?? 0.0;
            $row['restricted'] = $row['amounts']['restricted'] ?? 0.0;
            $row['endowment'] = $row['amounts']['endowment'] ?? 0.0;
            return $row;
        };

        $result = [];
        foreach ($rows as $row) {
            if ($row['total'] === 0) {
                continue;
            }
            if (isset($row['operational'])) {
                $ops = array_values(array_filter($row['operational'], fn($op) => $op['total'] !== 0));
                usort($ops, fn($a, $b) => [$a['display_order'], $a['name']] <=> [$b['display_order'], $b['name']]);
                $row['operational'] = array_map($finish, $ops);
            }
            $result[] = $finish($row);
        }

        usort($result, fn($a, $b) => [$a['display_order'], $a['name']] <=> [$b['display_order'], $b['name']]);

        return $result;
    }

    /**
     * Flat total keys kept for templates/callers written before fund-type columns
     */
    private function legacyTotals(array $totals): array
    {
        $legacy = [];
        foreach (['receipts', 'payments'] as $key) {
            foreach (FundService::FUND_TYPES as $type) {
                $legacy["total_{$key}_{$type}"] = $totals[$key][$type] ?? 0.0;
            }
            $legacy["total_{$key}"] = $totals[$key]['total'];
        }

        return $legacy;
    }

    /**
     * Generate Fund Summary report
     *
     * When dateFrom is provided, opening is the balance at the close of the previous day and
     * the activity covers dateFrom..asOfDate. Otherwise opening is each fund's opening balance
     * and the activity is everything up to asOfDate. Either way balance is the closing balance.
     */
    public function fundSummary(int $charityId, ?string $asOfDate = null, ?string $dateFrom = null): array
    {
        $funds = $this->fundService->getAllBalances($charityId, $asOfDate, $dateFrom);

        $keys = ['opening', 'receipts', 'payments', 'transfers_in', 'transfers_out', 'balance'];
        $totals = ['total' => array_fill_keys($keys, 0)];
        foreach (FundService::FUND_TYPES as $type) {
            $totals[$type] = array_fill_keys($keys, 0);
        }

        foreach ($funds as $fund) {
            $type = $fund['fund_type'];
            foreach ($keys as $key) {
                $pence = Money::toPence($fund[$key] ?? 0);
                $totals[$type][$key] += $pence;
                $totals['total'][$key] += $pence;
            }
        }

        foreach ($totals as $type => $values) {
            $totals[$type] = array_map(fn($p) => $p / 100, $values);
        }

        return [
            'date_from' => $dateFrom,
            'as_of_date' => $asOfDate ?? date('Y-m-d'),
            'funds' => $funds,
            'totals' => $totals,
        ];
    }

    /**
     * Fund-by-fund R&P breakdown (for OSCR accounts): one column per fund
     */
    public function fundByFundBreakdown(int $charityId, string $dateFrom, string $dateTo): array
    {
        return $this->fundByFund($charityId, $dateFrom, $dateTo, false);
    }

    /**
     * Fund-by-fund breakdown with operational categories as rows
     */
    public function fundByFundOperationalBreakdown(int $charityId, string $dateFrom, string $dateTo): array
    {
        return $this->fundByFund($charityId, $dateFrom, $dateTo, true);
    }

    private function fundByFund(int $charityId, string $dateFrom, string $dateTo, bool $operational): array
    {
        $positions = $this->fundPositions($charityId, $dateFrom, $dateTo);
        $funds = array_map(fn($p) => $p['fund'], array_values($positions));
        $fundIds = array_keys($positions);

        $categories = ['receipt' => [], 'payment' => []];
        foreach ($this->categoryFundTotals($charityId, $dateFrom, $dateTo) as $row) {
            $key = $operational ? $row['operational_category_id'] : $row['reporting_category_id'];
            $side = &$categories[$row['side']];

            $side[$key] ??= [
                'id' => $key,
                'name' => $operational ? $row['operational_category_name'] : $row['reporting_category_name'],
                'reporting_category_name' => $row['reporting_category_name'],
                'sort' => $operational
                    ? [$row['rc_display_order'], $row['reporting_category_name'], $row['oc_display_order'], $row['operational_category_name']]
                    : [$row['rc_display_order'], $row['reporting_category_name']],
                'funds' => array_fill_keys($fundIds, 0),
                'total' => 0,
            ];
            $side[$key]['funds'][$row['fund_id']] = ($side[$key]['funds'][$row['fund_id']] ?? 0) + $row['pence'];
            $side[$key]['total'] += $row['pence'];
            unset($side);
        }

        $finish = function (array $rows): array {
            $rows = array_values(array_filter($rows, fn($r) => $r['total'] !== 0));
            usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);
            return array_map(function ($r) {
                unset($r['sort']);
                $r['funds'] = array_map(fn($p) => $p / 100, $r['funds']);
                $r['total'] = $r['total'] / 100;
                return $r;
            }, $rows);
        };

        $fundTotals = [];
        $grand = array_fill_keys(['opening', 'receipts', 'payments', 'transfers_in', 'transfers_out', 'closing'], 0);
        foreach ($positions as $fundId => $position) {
            $fundTotals[$fundId] = ['fund' => $position['fund']];
            foreach (array_keys($grand) as $key) {
                $fundTotals[$fundId][$key] = $position[$key] / 100;
                $grand[$key] += $position[$key];
            }
        }

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'funds' => $funds,
            'fund_totals' => $fundTotals,
            'receipt_categories' => $finish($categories['receipt']),
            'payment_categories' => $finish($categories['payment']),
            'grand_totals' => array_map(fn($p) => $p / 100, $grand),
        ];
    }

    /**
     * Category Analysis: totals and transaction counts per category for a period
     */
    public function categoryAnalysis(int $charityId, string $dateFrom, string $dateTo, ?string $type = null): array
    {
        $categories = [];
        $counts = $this->transactionCountsByCategory($charityId, $dateFrom, $dateTo);

        foreach ($this->categoryFundTotals($charityId, $dateFrom, $dateTo) as $row) {
            if ($type && $row['side'] !== $type) {
                continue;
            }
            $rcId = $row['reporting_category_id'];
            $ocId = $row['operational_category_id'];

            $categories[$rcId] ??= [
                'id' => $rcId,
                'name' => $row['reporting_category_name'],
                'type' => $row['side'],
                'display_order' => $row['rc_display_order'],
                'total' => 0,
                'count' => $counts['reporting'][$rcId] ?? 0,
                'operational' => [],
            ];
            $categories[$rcId]['operational'][$ocId] ??= [
                'id' => $ocId,
                'name' => $row['operational_category_name'],
                'display_order' => $row['oc_display_order'],
                'total' => 0,
                'transaction_count' => $counts['operational'][$ocId] ?? 0,
            ];
            $categories[$rcId]['total'] += $row['pence'];
            $categories[$rcId]['operational'][$ocId]['total'] += $row['pence'];
        }

        foreach ($categories as &$cat) {
            $ops = array_values($cat['operational']);
            usort($ops, fn($a, $b) => [$a['display_order'], $a['name']] <=> [$b['display_order'], $b['name']]);
            foreach ($ops as &$op) {
                $op['percentage'] = $cat['total'] > 0 ? round($op['total'] / $cat['total'] * 100, 1) : 0.0;
                $op['total'] = $op['total'] / 100;
            }
            unset($op);
            $cat['operational'] = $ops;
            $cat['total'] = $cat['total'] / 100;
        }
        unset($cat);

        $categories = array_values($categories);
        usort($categories, fn($a, $b) => [$a['type'], $a['display_order'], $a['name']] <=> [$b['type'], $b['display_order'], $b['name']]);

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'categories' => $categories,
        ];
    }

    /**
     * Distinct transaction counts per category (a split transaction counts once per category)
     */
    private function transactionCountsByCategory(int $charityId, string $dateFrom, string $dateTo): array
    {
        $rows = $this->db->query(
            "SELECT tce.operational_category_id, oc.reporting_category_id, t.id as transaction_id
             FROM transaction_category_entries tce
             JOIN transactions t ON t.id = tce.transaction_id
             JOIN funds f ON f.id = tce.fund_id
             JOIN operational_categories oc ON oc.id = tce.operational_category_id
             WHERE t.charity_id = ? AND t.is_deleted = FALSE
               AND t.transaction_type IN ('receipt', 'payment')
               AND t.transaction_date >= ? AND t.transaction_date <= ?
               AND (f.opening_balance_date IS NULL OR t.transaction_date > f.opening_balance_date)
             GROUP BY tce.operational_category_id, oc.reporting_category_id, t.id",
            [$charityId, $dateFrom, $dateTo]
        );

        $operational = [];
        $reporting = [];
        foreach ($rows as $row) {
            $operational[(int) $row['operational_category_id']][(int) $row['transaction_id']] = true;
            $reporting[(int) $row['reporting_category_id']][(int) $row['transaction_id']] = true;
        }

        return [
            'operational' => array_map('count', $operational),
            'reporting' => array_map('count', $reporting),
        ];
    }

    /**
     * Export transactions to CSV format
     *
     * Text cells that a spreadsheet would treat as a formula are prefixed with an apostrophe.
     */
    public function exportTransactionsCsv(array $transactions): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Date', 'Type', 'Description', 'Amount', 'Fund', 'Category', 'Donor', 'Reference', 'Gift Aid'], ',', '"', '');

        foreach ($transactions as $t) {
            fputcsv($handle, [
                $t['transaction_date'],
                $t['transaction_type'],
                self::csvText($t['description'] ?? ''),
                Money::normalise($t['amount']),
                self::csvText($t['fund_name'] ?? ''),
                self::csvText($t['category_name'] ?? ''),
                self::csvText($t['donor_name'] ?? ''),
                self::csvText($t['reference'] ?? ''),
                $t['giftaid_status'] ?? '',
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralise spreadsheet formula injection in a text cell
     */
    private static function csvText(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
