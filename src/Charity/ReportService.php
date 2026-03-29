<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service for generating reports
 */
class ReportService
{
    private DatabaseInterface $db;
    private FundService $fundService;
    private CategoryService $categoryService;

    public function __construct(
        DatabaseInterface $db,
        FundService $fundService,
        CategoryService $categoryService
    ) {
        $this->db = $db;
        $this->fundService = $fundService;
        $this->categoryService = $categoryService;
    }

    /**
     * Generate Receipts and Payments report
     * Returns data structured for the R&P template with unrestricted/restricted breakdown
     */
    public function receiptsAndPayments(
        int $charityId,
        string $dateFrom,
        string $dateTo,
        ?int $fundId = null
    ): array {
        $funds = $fundId 
            ? [$this->fundService->get($fundId, $charityId)]
            : $this->fundService->getAll($charityId);

        $reportingCategories = $this->categoryService->getReportingCategories($charityId);
        
        // Initialize category totals with unrestricted/restricted breakdown
        $receipts = [];
        $payments = [];
        foreach ($reportingCategories as $cat) {
            $entry = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'unrestricted' => 0.0,
                'restricted' => 0.0,
                'total' => 0.0,
            ];
            if ($cat['category_type'] === 'receipt') {
                $receipts[$cat['id']] = $entry;
            } else {
                $payments[$cat['id']] = $entry;
            }
        }

        $totalReceiptsUnrestricted = 0.0;
        $totalReceiptsRestricted = 0.0;
        $totalPaymentsUnrestricted = 0.0;
        $totalPaymentsRestricted = 0.0;

        foreach ($funds as $fund) {
            $fundReport = $this->generateFundReport(
                $charityId,
                $fund,
                $reportingCategories,
                $dateFrom,
                $dateTo
            );

            $isRestricted = ($fund['fund_type'] === 'restricted');

            // Accumulate receipts by category
            foreach ($fundReport['receipts'] as $catId => $data) {
                if (isset($receipts[$catId])) {
                    if ($isRestricted) {
                        $receipts[$catId]['restricted'] += $data['amount'];
                        $totalReceiptsRestricted += $data['amount'];
                    } else {
                        $receipts[$catId]['unrestricted'] += $data['amount'];
                        $totalReceiptsUnrestricted += $data['amount'];
                    }
                    $receipts[$catId]['total'] += $data['amount'];
                }
            }

            // Accumulate payments by category
            foreach ($fundReport['payments'] as $catId => $data) {
                if (isset($payments[$catId])) {
                    if ($isRestricted) {
                        $payments[$catId]['restricted'] += $data['amount'];
                        $totalPaymentsRestricted += $data['amount'];
                    } else {
                        $payments[$catId]['unrestricted'] += $data['amount'];
                        $totalPaymentsUnrestricted += $data['amount'];
                    }
                    $payments[$catId]['total'] += $data['amount'];
                }
            }
        }

        // Filter out categories with zero totals and convert to indexed array
        $receipts = array_values(array_filter($receipts, fn($r) => $r['total'] > 0));
        $payments = array_values(array_filter($payments, fn($p) => $p['total'] > 0));

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'receipts' => $receipts,
            'payments' => $payments,
            'total_receipts_unrestricted' => $totalReceiptsUnrestricted,
            'total_receipts_restricted' => $totalReceiptsRestricted,
            'total_receipts' => $totalReceiptsUnrestricted + $totalReceiptsRestricted,
            'total_payments_unrestricted' => $totalPaymentsUnrestricted,
            'total_payments_restricted' => $totalPaymentsRestricted,
            'total_payments' => $totalPaymentsUnrestricted + $totalPaymentsRestricted,
        ];
    }

    /**
     * Generate detailed Receipts and Payments report with operational category breakdown
     * Shows reporting categories with their operational categories underneath
     *
     * Pro-rates category amounts based on fund allocation for split transactions,
     * consistent with generateFundReport() and fundSummary() calculations.
     */
    public function detailedReceiptsAndPayments(
        int $charityId,
        string $dateFrom,
        string $dateTo
    ): array {
        // Query transactions grouped by operational category and fund type
        // Pro-rates category amounts based on fund allocation (handles split transactions)
        $sql = "SELECT
                    rc.id as reporting_category_id,
                    rc.name as reporting_category_name,
                    rc.category_type,
                    rc.display_order as rc_display_order,
                    oc.id as operational_category_id,
                    oc.name as operational_category_name,
                    oc.display_order as oc_display_order,
                    f.fund_type,
                    COALESCE(SUM(
                        ABS(tce.amount) * (ABS(tfe.amount) / NULLIF(
                            (SELECT SUM(ABS(tfe2.amount)) FROM transaction_fund_entries tfe2 WHERE tfe2.transaction_id = t.id),
                            0
                        ))
                    ), 0) as total
                FROM reporting_categories rc
                JOIN operational_categories oc ON oc.reporting_category_id = rc.id
                LEFT JOIN transaction_category_entries tce ON tce.operational_category_id = oc.id
                LEFT JOIN transactions t ON t.id = tce.transaction_id
                    AND t.charity_id = ?
                    AND t.is_deleted = FALSE
                    AND t.transaction_date >= ?
                    AND t.transaction_date <= ?
                LEFT JOIN transaction_fund_entries tfe ON tfe.transaction_id = t.id
                LEFT JOIN funds f ON f.id = tfe.fund_id
                WHERE rc.charity_id = ? AND rc.is_active = TRUE AND oc.is_active = TRUE
                GROUP BY rc.id, rc.name, rc.category_type, rc.display_order,
                         oc.id, oc.name, oc.display_order, f.fund_type
                ORDER BY rc.category_type, rc.display_order, oc.display_order";

        $rows = $this->db->query($sql, [$charityId, $dateFrom, $dateTo, $charityId]);

        // Build structured data
        $receipts = [];
        $payments = [];
        $totalReceiptsUnrestricted = 0.0;
        $totalReceiptsRestricted = 0.0;
        $totalPaymentsUnrestricted = 0.0;
        $totalPaymentsRestricted = 0.0;

        foreach ($rows as $row) {
            $rcId = $row['reporting_category_id'];
            $ocId = $row['operational_category_id'];
            $isReceipt = ($row['category_type'] === 'receipt');
            $target = $isReceipt ? 'receipts' : 'payments';
            $amount = (float) $row['total'];
            $fundType = $row['fund_type'] ?? 'unrestricted';

            // Get reference to correct array
            $categories = &${$target};

            // Initialize reporting category if needed
            if (!isset($categories[$rcId])) {
                $categories[$rcId] = [
                    'id' => $rcId,
                    'name' => $row['reporting_category_name'],
                    'display_order' => $row['rc_display_order'],
                    'unrestricted' => 0.0,
                    'restricted' => 0.0,
                    'total' => 0.0,
                    'operational' => [],
                ];
            }

            // Initialize operational category if needed
            if (!isset($categories[$rcId]['operational'][$ocId])) {
                $categories[$rcId]['operational'][$ocId] = [
                    'id' => $ocId,
                    'name' => $row['operational_category_name'],
                    'display_order' => $row['oc_display_order'],
                    'unrestricted' => 0.0,
                    'restricted' => 0.0,
                    'total' => 0.0,
                ];
            }

            // Add amounts
            if ($fundType === 'restricted') {
                $categories[$rcId]['operational'][$ocId]['restricted'] += $amount;
                $categories[$rcId]['restricted'] += $amount;
                if ($isReceipt) {
                    $totalReceiptsRestricted += $amount;
                } else {
                    $totalPaymentsRestricted += $amount;
                }
            } else {
                $categories[$rcId]['operational'][$ocId]['unrestricted'] += $amount;
                $categories[$rcId]['unrestricted'] += $amount;
                if ($isReceipt) {
                    $totalReceiptsUnrestricted += $amount;
                } else {
                    $totalPaymentsUnrestricted += $amount;
                }
            }
            $categories[$rcId]['operational'][$ocId]['total'] += $amount;
            $categories[$rcId]['total'] += $amount;
        }

        // Convert operational arrays to indexed arrays and filter empty categories
        $processCategories = function($categories) {
            $result = [];
            foreach ($categories as $cat) {
                // Convert operational to indexed array sorted by display_order
                $ops = array_values($cat['operational']);
                usort($ops, fn($a, $b) => $a['display_order'] <=> $b['display_order']);
                // Filter out operational categories with no transactions
                $ops = array_filter($ops, fn($op) => $op['total'] > 0);
                $cat['operational'] = array_values($ops);
                
                // Only include reporting category if it has transactions
                if ($cat['total'] > 0) {
                    $result[] = $cat;
                }
            }
            // Sort by display_order
            usort($result, fn($a, $b) => $a['display_order'] <=> $b['display_order']);
            return $result;
        };

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'receipts' => $processCategories($receipts),
            'payments' => $processCategories($payments),
            'total_receipts_unrestricted' => $totalReceiptsUnrestricted,
            'total_receipts_restricted' => $totalReceiptsRestricted,
            'total_receipts' => $totalReceiptsUnrestricted + $totalReceiptsRestricted,
            'total_payments_unrestricted' => $totalPaymentsUnrestricted,
            'total_payments_restricted' => $totalPaymentsRestricted,
            'total_payments' => $totalPaymentsUnrestricted + $totalPaymentsRestricted,
        ];
    }

    /**
     * Generate report data for a single fund
     */
    private function generateFundReport(
        int $charityId,
        array $fund,
        array $reportingCategories,
        string $dateFrom,
        string $dateTo
    ): array {
        // Calculate opening balance (balance before period start)
        $priorBalance = $this->calculateBalanceAsOf($charityId, $fund['id'], $dateFrom, true, $fund);
        $opening = (float) $fund['opening_balance'] + $priorBalance['receipts'] - $priorBalance['payments']
                   + $priorBalance['transfers_in'] - $priorBalance['transfers_out'];

        // Get transfers for this fund during the period
        $periodTransfers = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN tfe.amount > 0 THEN tfe.amount ELSE 0 END), 0) as transfers_in,
                COALESCE(SUM(CASE WHEN tfe.amount < 0 THEN ABS(tfe.amount) ELSE 0 END), 0) as transfers_out
             FROM transaction_fund_entries tfe
             JOIN transactions t ON t.id = tfe.transaction_id
             WHERE t.charity_id = ? AND tfe.fund_id = ? AND t.transaction_type = 'transfer'
                   AND t.is_deleted = FALSE
                   AND t.transaction_date >= ? AND t.transaction_date <= ?",
            [$charityId, $fund['id'], $dateFrom, $dateTo]
        );
        $transfersIn = (float) ($periodTransfers['transfers_in'] ?? 0);
        $transfersOut = (float) ($periodTransfers['transfers_out'] ?? 0);

        // Get category totals for the period using entries tables
        // This properly handles split transactions by summing amounts from entries
        $categoryTotals = $this->db->query(
            "SELECT rc.id as category_id, rc.name, rc.category_type,
                    COALESCE(SUM(
                        CASE WHEN tfe.fund_id = ? THEN
                            -- Pro-rate category amount based on fund allocation
                            ABS(tce.amount) * (ABS(tfe.amount) / NULLIF(
                                (SELECT SUM(ABS(tfe2.amount)) FROM transaction_fund_entries tfe2 WHERE tfe2.transaction_id = t.id),
                                0
                            ))
                        ELSE 0 END
                    ), 0) as total
             FROM reporting_categories rc
             LEFT JOIN operational_categories oc ON oc.reporting_category_id = rc.id
             LEFT JOIN transaction_category_entries tce ON tce.operational_category_id = oc.id
             LEFT JOIN transactions t ON t.id = tce.transaction_id
                 AND t.charity_id = ?
                 AND t.is_deleted = FALSE
                 AND t.transaction_date >= ?
                 AND t.transaction_date <= ?
             LEFT JOIN transaction_fund_entries tfe ON tfe.transaction_id = t.id
             WHERE rc.charity_id = ? AND rc.is_active = TRUE
             GROUP BY rc.id, rc.name, rc.category_type
             ORDER BY rc.category_type, rc.display_order",
            [$fund['id'], $charityId, $dateFrom, $dateTo, $charityId]
        );

        $receipts = [];
        $payments = [];
        $receiptsTotal = 0;
        $paymentsTotal = 0;

        foreach ($categoryTotals as $row) {
            $data = [
                'name' => $row['name'],
                'amount' => (float) $row['total'],
            ];

            if ($row['category_type'] === 'receipt') {
                $receipts[$row['category_id']] = $data;
                $receiptsTotal += $data['amount'];
            } else {
                $payments[$row['category_id']] = $data;
                $paymentsTotal += $data['amount'];
            }
        }

        $closing = $opening + $receiptsTotal - $paymentsTotal + $transfersIn - $transfersOut;

        return [
            'fund_id' => $fund['id'],
            'fund_name' => $fund['name'],
            'fund_type' => $fund['fund_type'],
            'opening' => $opening,
            'receipts' => $receipts,
            'receipts_total' => $receiptsTotal,
            'payments' => $payments,
            'payments_total' => $paymentsTotal,
            'transfers_in' => $transfersIn,
            'transfers_out' => $transfersOut,
            'net_movement' => $receiptsTotal - $paymentsTotal + $transfersIn - $transfersOut,
            'closing' => $closing,
        ];
    }

    /**
     * Calculate balance as of a date using fund entries table
     * Only sums transactions from opening_balance_date onwards to avoid double-counting
     */
    private function calculateBalanceAsOf(
        int $charityId,
        int $fundId,
        string $date,
        bool $before = false,
        ?array $fund = null
    ): array {
        $operator = $before ? '<' : '<=';

        // Get fund to check opening_balance_date (if not already provided)
        if ($fund === null) {
            $fund = $this->fundService->get($fundId, $charityId);
        }

        $dateConditions = ["t.transaction_date $operator ?"];
        $params = [$charityId, $fundId, $date];

        // Only apply opening_balance_date filter if there's actually an opening balance
        // (opening balance already includes transactions up to that date)
        if ($fund && !empty($fund['opening_balance_date']) && (float)($fund['opening_balance'] ?? 0) != 0) {
            $dateConditions[] = 't.transaction_date > ?';
            $params[] = $fund['opening_balance_date'];
        }

        $dateCondition = implode(' AND ', $dateConditions);

        // Use transaction_fund_entries to properly handle split transactions
        // Track receipts, payments, and transfers separately
        $result = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN t.transaction_type = 'receipt' THEN ABS(tfe.amount) ELSE 0 END), 0) as receipts,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'payment' THEN ABS(tfe.amount) ELSE 0 END), 0) as payments,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount > 0 THEN tfe.amount ELSE 0 END), 0) as transfers_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'transfer' AND tfe.amount < 0 THEN ABS(tfe.amount) ELSE 0 END), 0) as transfers_out
             FROM transaction_fund_entries tfe
             JOIN transactions t ON t.id = tfe.transaction_id
             WHERE t.charity_id = ? AND tfe.fund_id = ? AND t.is_deleted = FALSE
             AND ($dateCondition)",
            $params
        );

        return [
            'receipts' => (float) ($result['receipts'] ?? 0),
            'payments' => (float) ($result['payments'] ?? 0),
            'transfers_in' => (float) ($result['transfers_in'] ?? 0),
            'transfers_out' => (float) ($result['transfers_out'] ?? 0),
        ];
    }

    /**
     * Generate Fund Summary report
     *
     * When dateFrom is provided, shows period-specific activity (comparable to R&P reports).
     * When dateFrom is null, shows cumulative balance from opening balance date.
     *
     * @param int $charityId The charity ID
     * @param string|null $asOfDate End date (defaults to today)
     * @param string|null $dateFrom Start date for period-specific report
     */
    public function fundSummary(int $charityId, ?string $asOfDate = null, ?string $dateFrom = null): array
    {
        $funds = $this->fundService->getAllBalances($charityId, $asOfDate, $dateFrom);
        
        $totals = [
            'unrestricted' => ['opening' => 0, 'receipts' => 0, 'payments' => 0, 'transfers_in' => 0, 'transfers_out' => 0, 'balance' => 0],
            'restricted' => ['opening' => 0, 'receipts' => 0, 'payments' => 0, 'transfers_in' => 0, 'transfers_out' => 0, 'balance' => 0],
            'total' => ['opening' => 0, 'receipts' => 0, 'payments' => 0, 'transfers_in' => 0, 'transfers_out' => 0, 'balance' => 0],
        ];

        foreach ($funds as $fund) {
            $type = $fund['fund_type'];
            $totals[$type]['opening'] += $fund['opening'] ?? 0;
            $totals[$type]['receipts'] += $fund['receipts'] ?? 0;
            $totals[$type]['payments'] += $fund['payments'] ?? 0;
            $totals[$type]['transfers_in'] += $fund['transfers_in'] ?? 0;
            $totals[$type]['transfers_out'] += $fund['transfers_out'] ?? 0;
            $totals[$type]['balance'] += $fund['balance'] ?? 0;

            $totals['total']['opening'] += $fund['opening'] ?? 0;
            $totals['total']['receipts'] += $fund['receipts'] ?? 0;
            $totals['total']['payments'] += $fund['payments'] ?? 0;
            $totals['total']['transfers_in'] += $fund['transfers_in'] ?? 0;
            $totals['total']['transfers_out'] += $fund['transfers_out'] ?? 0;
            $totals['total']['balance'] += $fund['balance'] ?? 0;
        }

        return [
            'date_from' => $dateFrom,
            'as_of_date' => $asOfDate ?? date('Y-m-d'),
            'funds' => $funds,
            'totals' => $totals,
        ];
    }

    /**
     * Generate fund-by-fund R&P breakdown (for OSCR accounts)
     * Shows each fund as a separate column with category breakdowns
     */
    public function fundByFundBreakdown(
        int $charityId,
        string $dateFrom,
        string $dateTo
    ): array {
        $funds = $this->fundService->getAll($charityId);
        $reportingCategories = $this->categoryService->getReportingCategories($charityId);

        // Initialize category data
        $receiptCategories = [];
        $paymentCategories = [];
        foreach ($reportingCategories as $cat) {
            $entry = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'funds' => [],
                'total' => 0.0,
            ];
            // Initialize each fund column
            foreach ($funds as $fund) {
                $entry['funds'][$fund['id']] = 0.0;
            }
            if ($cat['category_type'] === 'receipt') {
                $receiptCategories[$cat['id']] = $entry;
            } else {
                $paymentCategories[$cat['id']] = $entry;
            }
        }

        // Initialize fund totals
        $fundTotals = [];
        foreach ($funds as $fund) {
            $fundTotals[$fund['id']] = [
                'fund' => $fund,
                'opening' => 0.0,
                'receipts' => 0.0,
                'payments' => 0.0,
                'transfers_in' => 0.0,
                'transfers_out' => 0.0,
                'closing' => 0.0,
            ];
        }

        // Generate each fund report and populate category data
        foreach ($funds as $fund) {
            $fundReport = $this->generateFundReport(
                $charityId,
                $fund,
                $reportingCategories,
                $dateFrom,
                $dateTo
            );

            $fundTotals[$fund['id']]['opening'] = $fundReport['opening'];
            $fundTotals[$fund['id']]['receipts'] = $fundReport['receipts_total'];
            $fundTotals[$fund['id']]['payments'] = $fundReport['payments_total'];
            $fundTotals[$fund['id']]['transfers_in'] = $fundReport['transfers_in'];
            $fundTotals[$fund['id']]['transfers_out'] = $fundReport['transfers_out'];
            $fundTotals[$fund['id']]['closing'] = $fundReport['closing'];

            // Populate receipt categories
            foreach ($fundReport['receipts'] as $catId => $data) {
                if (isset($receiptCategories[$catId])) {
                    $receiptCategories[$catId]['funds'][$fund['id']] = $data['amount'];
                    $receiptCategories[$catId]['total'] += $data['amount'];
                }
            }

            // Populate payment categories
            foreach ($fundReport['payments'] as $catId => $data) {
                if (isset($paymentCategories[$catId])) {
                    $paymentCategories[$catId]['funds'][$fund['id']] = $data['amount'];
                    $paymentCategories[$catId]['total'] += $data['amount'];
                }
            }
        }

        // Calculate grand totals
        $grandTotals = [
            'opening' => 0.0,
            'receipts' => 0.0,
            'payments' => 0.0,
            'transfers_in' => 0.0,
            'transfers_out' => 0.0,
            'closing' => 0.0,
        ];
        foreach ($fundTotals as $ft) {
            $grandTotals['opening'] += $ft['opening'];
            $grandTotals['receipts'] += $ft['receipts'];
            $grandTotals['payments'] += $ft['payments'];
            $grandTotals['transfers_in'] += $ft['transfers_in'];
            $grandTotals['transfers_out'] += $ft['transfers_out'];
            $grandTotals['closing'] += $ft['closing'];
        }

        // Filter out empty categories
        $receiptCategories = array_values(array_filter($receiptCategories, fn($c) => $c['total'] > 0));
        $paymentCategories = array_values(array_filter($paymentCategories, fn($c) => $c['total'] > 0));

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'funds' => $funds,
            'fund_totals' => $fundTotals,
            'receipt_categories' => $receiptCategories,
            'payment_categories' => $paymentCategories,
            'grand_totals' => $grandTotals,
        ];
    }

    /**
     * Generate fund-by-fund breakdown with operational category detail
     * Shows each fund as a column with operational categories listed
     */
    public function fundByFundOperationalBreakdown(
        int $charityId,
        string $dateFrom,
        string $dateTo
    ): array {
        $funds = $this->fundService->getAll($charityId);
        $operationalCategories = $this->categoryService->getOperationalCategories($charityId);

        // Group operational categories by type
        $receiptCategories = [];
        $paymentCategories = [];
        foreach ($operationalCategories as $cat) {
            $entry = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'reporting_category_name' => $cat['reporting_category_name'] ?? '',
                'funds' => [],
                'total' => 0.0,
            ];
            foreach ($funds as $fund) {
                $entry['funds'][$fund['id']] = 0.0;
            }
            if ($cat['category_type'] === 'receipt') {
                $receiptCategories[$cat['id']] = $entry;
            } else {
                $paymentCategories[$cat['id']] = $entry;
            }
        }

        // Query all amounts by operational category and fund
        // Pro-rates when a transaction is split across multiple funds
        $sql = "SELECT
                    oc.id as category_id,
                    rc.category_type,
                    tfe.fund_id,
                    COALESCE(SUM(
                        ABS(tce.amount) * (ABS(tfe.amount) / NULLIF(
                            (SELECT SUM(ABS(tfe2.amount)) FROM transaction_fund_entries tfe2 WHERE tfe2.transaction_id = t.id),
                            0
                        ))
                    ), 0) as total
                FROM operational_categories oc
                JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
                LEFT JOIN transaction_category_entries tce ON tce.operational_category_id = oc.id
                LEFT JOIN transactions t ON t.id = tce.transaction_id
                    AND t.charity_id = ?
                    AND t.is_deleted = FALSE
                    AND t.transaction_date >= ?
                    AND t.transaction_date <= ?
                LEFT JOIN transaction_fund_entries tfe ON tfe.transaction_id = t.id
                WHERE oc.charity_id = ? AND oc.is_active = TRUE
                GROUP BY oc.id, rc.category_type, tfe.fund_id";

        $rows = $this->db->query($sql, [$charityId, $dateFrom, $dateTo, $charityId]);

        // Populate category amounts
        foreach ($rows as $row) {
            $catId = $row['category_id'];
            $fundId = $row['fund_id'];
            $amount = (float) $row['total'];

            if ($fundId === null) {
                continue;
            }

            if ($row['category_type'] === 'receipt' && isset($receiptCategories[$catId])) {
                $receiptCategories[$catId]['funds'][$fundId] = $amount;
                $receiptCategories[$catId]['total'] += $amount;
            } elseif ($row['category_type'] === 'payment' && isset($paymentCategories[$catId])) {
                $paymentCategories[$catId]['funds'][$fundId] = $amount;
                $paymentCategories[$catId]['total'] += $amount;
            }
        }

        // Calculate fund totals
        $fundTotals = [];
        foreach ($funds as $fund) {
            $receiptsTotal = 0.0;
            $paymentsTotal = 0.0;
            foreach ($receiptCategories as $cat) {
                $receiptsTotal += $cat['funds'][$fund['id']];
            }
            foreach ($paymentCategories as $cat) {
                $paymentsTotal += $cat['funds'][$fund['id']];
            }

            // Get opening balance
            $priorBalance = $this->calculateBalanceAsOf($charityId, $fund['id'], $dateFrom, true, $fund);
            $opening = (float) $fund['opening_balance'] + $priorBalance['receipts'] - $priorBalance['payments']
                       + $priorBalance['transfers_in'] - $priorBalance['transfers_out'];

            // Get transfers for this fund during the period
            $periodTransfers = $this->db->queryOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN tfe.amount > 0 THEN tfe.amount ELSE 0 END), 0) as transfers_in,
                    COALESCE(SUM(CASE WHEN tfe.amount < 0 THEN ABS(tfe.amount) ELSE 0 END), 0) as transfers_out
                 FROM transaction_fund_entries tfe
                 JOIN transactions t ON t.id = tfe.transaction_id
                 WHERE t.charity_id = ? AND tfe.fund_id = ? AND t.transaction_type = 'transfer'
                       AND t.is_deleted = FALSE
                       AND t.transaction_date >= ? AND t.transaction_date <= ?",
                [$charityId, $fund['id'], $dateFrom, $dateTo]
            );
            $transfersIn = (float) $periodTransfers['transfers_in'];
            $transfersOut = (float) $periodTransfers['transfers_out'];

            $fundTotals[$fund['id']] = [
                'fund' => $fund,
                'opening' => $opening,
                'receipts' => $receiptsTotal,
                'payments' => $paymentsTotal,
                'transfers_in' => $transfersIn,
                'transfers_out' => $transfersOut,
                'closing' => $opening + $receiptsTotal - $paymentsTotal + $transfersIn - $transfersOut,
            ];
        }

        // Calculate grand totals
        $grandTotals = [
            'opening' => 0.0,
            'receipts' => 0.0,
            'payments' => 0.0,
            'transfers_in' => 0.0,
            'transfers_out' => 0.0,
            'closing' => 0.0,
        ];
        foreach ($fundTotals as $ft) {
            $grandTotals['opening'] += $ft['opening'];
            $grandTotals['receipts'] += $ft['receipts'];
            $grandTotals['payments'] += $ft['payments'];
            $grandTotals['transfers_in'] += $ft['transfers_in'];
            $grandTotals['transfers_out'] += $ft['transfers_out'];
            $grandTotals['closing'] += $ft['closing'];
        }

        // Filter out empty categories and convert to indexed arrays
        $receiptCategories = array_values(array_filter($receiptCategories, fn($c) => $c['total'] > 0));
        $paymentCategories = array_values(array_filter($paymentCategories, fn($c) => $c['total'] > 0));

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'funds' => $funds,
            'fund_totals' => $fundTotals,
            'receipt_categories' => $receiptCategories,
            'payment_categories' => $paymentCategories,
            'grand_totals' => $grandTotals,
        ];
    }

    /**
     * Generate Category Analysis report
     * Uses category entries table to properly handle split transactions
     */
    public function categoryAnalysis(
        int $charityId,
        string $dateFrom,
        string $dateTo,
        ?string $type = null
    ): array {
        $sql = "SELECT
                    rc.id as reporting_category_id,
                    rc.name as reporting_category_name,
                    rc.category_type,
                    oc.id as operational_category_id,
                    oc.name as operational_category_name,
                    COALESCE(SUM(ABS(tce.amount)), 0) as total,
                    COUNT(DISTINCT t.id) as transaction_count
                FROM reporting_categories rc
                JOIN operational_categories oc ON oc.reporting_category_id = rc.id
                LEFT JOIN transaction_category_entries tce ON tce.operational_category_id = oc.id
                LEFT JOIN transactions t ON t.id = tce.transaction_id
                    AND t.charity_id = ?
                    AND t.is_deleted = FALSE
                    AND t.transaction_date >= ?
                    AND t.transaction_date <= ?
                WHERE rc.charity_id = ? AND rc.is_active = TRUE AND oc.is_active = TRUE";

        $params = [$charityId, $dateFrom, $dateTo, $charityId];

        if ($type) {
            $sql .= " AND rc.category_type = ?";
            $params[] = $type;
        }

        $sql .= " GROUP BY rc.id, rc.name, rc.category_type, oc.id, oc.name
                  ORDER BY rc.category_type, rc.display_order, oc.display_order";

        $rows = $this->db->query($sql, $params);

        // Group by reporting category
        $categories = [];
        foreach ($rows as $row) {
            $rcId = $row['reporting_category_id'];
            
            if (!isset($categories[$rcId])) {
                $categories[$rcId] = [
                    'id' => $rcId,
                    'name' => $row['reporting_category_name'],
                    'type' => $row['category_type'],
                    'total' => 0,
                    'count' => 0,
                    'operational' => [],
                ];
            }

            $categories[$rcId]['total'] += (float) $row['total'];
            $categories[$rcId]['count'] += (int) $row['transaction_count'];
            $categories[$rcId]['operational'][] = [
                'id' => $row['operational_category_id'],
                'name' => $row['operational_category_name'],
                'total' => (float) $row['total'],
                'transaction_count' => (int) $row['transaction_count'],
            ];
        }

        // Calculate percentages
        foreach ($categories as &$cat) {
            if ($cat['total'] > 0) {
                foreach ($cat['operational'] as &$op) {
                    $op['percentage'] = round(($op['total'] / $cat['total']) * 100, 1);
                }
            }
        }

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'categories' => array_values($categories),
        ];
    }

    /**
     * Generate Gift Aid report
     */
    public function giftAidReport(
        int $charityId,
        string $dateFrom,
        string $dateTo,
        ?string $status = null
    ): array {
        $sql = "SELECT t.*, d.name as donor_name, d.address_line1, d.address_postcode,
                       gc.id as claim_id, gc.status as claim_status, gc.submitted_at
                FROM transactions t
                LEFT JOIN donors d ON d.id = t.donor_id
                LEFT JOIN giftaid_claims gc ON gc.id = t.giftaid_claim_id
                WHERE t.charity_id = ?
                AND t.is_deleted = FALSE
                AND t.transaction_type = 'receipt'
                AND t.giftaid_status != 'not_applicable'
                AND t.transaction_date >= ?
                AND t.transaction_date <= ?";

        $params = [$charityId, $dateFrom, $dateTo];

        if ($status) {
            $sql .= " AND t.giftaid_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY t.giftaid_status, t.transaction_date";

        $transactions = $this->db->query($sql, $params);

        // Calculate totals by status
        $totals = [
            'eligible' => ['count' => 0, 'amount' => 0, 'giftaid' => 0],
            'gasds' => ['count' => 0, 'amount' => 0, 'giftaid' => 0],
            'claimed' => ['count' => 0, 'amount' => 0, 'giftaid' => 0],
            'not_eligible' => ['count' => 0, 'amount' => 0],
        ];

        foreach ($transactions as $t) {
            $status = $t['giftaid_status'];
            if (isset($totals[$status])) {
                $totals[$status]['count']++;
                $totals[$status]['amount'] += (float) $t['amount'];
                if (isset($totals[$status]['giftaid'])) {
                    $totals[$status]['giftaid'] += (float) $t['amount'] * 0.25;
                }
            }
        }

        return [
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'transactions' => $transactions,
            'totals' => $totals,
        ];
    }

    /**
     * Export transactions to CSV format
     */
    public function exportTransactionsCsv(array $transactions): string
    {
        $csv = "Date,Type,Description,Amount,Fund,Category,Donor,Reference,Gift Aid\n";

        foreach ($transactions as $t) {
            $csv .= sprintf(
                "%s,%s,\"%s\",%.2f,\"%s\",\"%s\",\"%s\",\"%s\",%s\n",
                $t['transaction_date'],
                $t['transaction_type'],
                str_replace('"', '""', $t['description']),
                $t['amount'],
                str_replace('"', '""', $t['fund_name']),
                str_replace('"', '""', $t['category_name']),
                str_replace('"', '""', $t['donor_name'] ?? ''),
                str_replace('"', '""', $t['reference'] ?? ''),
                $t['giftaid_status']
            );
        }

        return $csv;
    }
}
