<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\ReportService;
use CharityCashbook\Charity\TransactionService;

/**
 * The accounts must add up, for any date range:
 * - in every fund-type column: opening + receipts - payments + transfers = closing
 * - one period's closing balances are the next period's opening balances
 * - every report (R&P, detailed, fund-by-fund, category analysis, fund summary) agrees
 * - cash in the cash books equals the funds when every transaction is assigned
 *
 * The data deliberately includes the cases that used to break this: split transactions,
 * transfers, a hidden category, a deactivated fund, an endowment fund, a transaction on
 * an opening-balance date, and a deleted transaction.
 *
 * Requires MariaDB - set DB_HOST env var to run these tests.
 */
class ReportInvariantsIntegrationTest extends BaseIntegrationTest
{
    use LedgerFixtures;

    private const PERIODS = [
        ['2025-04-01', '2026-03-31'],
        ['2026-04-01', '2027-03-31'],
        ['2025-05-15', '2025-12-31'],
    ];

    private int $charityId;
    private array $fund = [];
    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = $this->getService(ReportService::class);
        $this->buildLedger();
    }

    private function buildLedger(): void
    {
        $this->charityId = $this->makeCharity();
        $userId = $this->makeUser($this->charityId);
        $tx = $this->getService(TransactionService::class);

        $this->fund['general'] = $this->makeFund($this->charityId, 'unrestricted', ['opening_balance' => '1000.00', 'opening_balance_date' => '2025-03-31']);
        $this->fund['roof'] = $this->makeFund($this->charityId, 'restricted');
        $this->fund['endowment'] = $this->makeFund($this->charityId, 'endowment', ['opening_balance' => '5000.00', 'opening_balance_date' => '2025-03-31']);
        $this->fund['project'] = $this->makeFund($this->charityId, 'restricted');

        $donations = $this->makeCategory($this->charityId, 'receipt', true);
        $grants = $this->makeCategory($this->charityId, 'receipt');
        $costs = $this->makeCategory($this->charityId, 'payment');
        $supplies = $this->makeCategory($this->charityId, 'payment');

        // The endowment is invested, not in the bank
        $bank = $this->makeCashBook($this->charityId, '1000.00', '2025-03-31');

        $base = ['description' => 'Test', 'cash_book_id' => $bank];

        // On the opening-balance date: already in the opening balances, so ignored everywhere
        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-03-31', 'amount' => '50.00',
            'operational_category_id' => $donations['operational'], 'fund_id' => $this->fund['general'],
        ]);

        // A receipt split across categories AND funds
        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-05-01', 'amount' => '150.00',
            'lines' => [
                ['operational_category_id' => $grants['operational'], 'fund_id' => $this->fund['roof'], 'amount' => '100.00'],
                ['operational_category_id' => $donations['operational'], 'fund_id' => $this->fund['general'], 'amount' => '30.00'],
                ['operational_category_id' => $donations['operational'], 'fund_id' => $this->fund['roof'], 'amount' => '20.00'],
            ],
        ]);

        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-06-01', 'amount' => '40.00',
            'operational_category_id' => $costs['operational'], 'fund_id' => $this->fund['general'],
        ]);

        // Transfer between funds: no cash moves
        $tx->create($this->charityId, $userId, [
            'transaction_type' => 'transfer', 'transaction_date' => '2025-07-01', 'amount' => '25.00', 'description' => 'To roof',
            'fund_entries' => [
                ['fund_id' => $this->fund['general'], 'amount' => '-25.00'],
                ['fund_id' => $this->fund['roof'], 'amount' => '25.00'],
            ],
        ]);

        // A project fund that receives and spends money, then is deactivated
        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-07-15', 'amount' => '10.00',
            'operational_category_id' => $donations['operational'], 'fund_id' => $this->fund['project'],
        ]);
        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-08-01', 'amount' => '10.00',
            'operational_category_id' => $supplies['operational'], 'fund_id' => $this->fund['project'],
        ]);

        // A deleted transaction never counts
        $deleted = $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2025-09-01', 'amount' => '999.00',
            'operational_category_id' => $grants['operational'], 'fund_id' => $this->fund['general'],
        ]);
        $tx->delete($deleted, $this->charityId, $userId);

        // Next financial year
        $tx->create($this->charityId, $userId, $base + [
            'transaction_date' => '2026-05-01', 'amount' => '70.00',
            'operational_category_id' => $donations['operational'], 'fund_id' => $this->fund['general'],
        ]);

        // Hide a category that has history, and deactivate the (now empty) project fund
        $this->getService(CategoryService::class)->deactivateReportingCategory($supplies['reporting'], $this->charityId);
        $this->getService(FundService::class)->deactivate($this->fund['project'], $this->charityId);
    }

    public function testEveryColumnOfTheReceiptsAndPaymentsAccountBalances(): void
    {
        foreach (self::PERIODS as [$from, $to]) {
            $t = $this->reports->receiptsAndPayments($this->charityId, $from, $to)['totals'];

            foreach (array_keys($t['closing']) as $column) {
                $this->assertEqualsWithDelta(
                    $t['closing'][$column],
                    $t['opening'][$column] + $t['receipts'][$column] - $t['payments'][$column] + $t['transfers_net'][$column],
                    0.001,
                    "$from to $to, column $column"
                );
            }
            $this->assertEqualsWithDelta(0.0, $t['transfers_net']['total'], 0.001, 'Transfers between funds net to zero');
        }
    }

    public function testKnownFiguresForTheFirstYear(): void
    {
        $report = $this->reports->receiptsAndPayments($this->charityId, '2025-04-01', '2026-03-31');
        $t = $report['totals'];

        // Receipts: 150 split + 10 project (the 50 on the opening date and the deleted 999 are excluded)
        $this->assertEqualsWithDelta(160.0, $t['receipts']['total'], 0.001);
        $this->assertEqualsWithDelta(30.0, $t['receipts']['unrestricted'], 0.001);   // 30 to the general fund
        $this->assertEqualsWithDelta(130.0, $t['receipts']['restricted'], 0.001);    // 100 + 20 roof + 10 project
        // Payments: 40 costs + 10 supplies (hidden category, deactivated fund - still reported)
        $this->assertEqualsWithDelta(50.0, $t['payments']['total'], 0.001);

        $this->assertEqualsWithDelta(6000.0, $t['opening']['total'], 0.001);
        $this->assertEqualsWithDelta(965.0, $t['closing']['unrestricted'], 0.001);   // 1000 + 30 - 40 - 25
        $this->assertEqualsWithDelta(145.0, $t['closing']['restricted'], 0.001);     // 120 + 25 + (10 - 10)
        $this->assertEqualsWithDelta(5000.0, $t['closing']['endowment'], 0.001);
        $this->assertEqualsWithDelta(6110.0, $t['closing']['total'], 0.001);

        $paymentCategories = array_column($report['payments'], 'name');
        $this->assertCount(2, $paymentCategories, 'The hidden category is still reported');
    }

    public function testClosingBalancesCarryForward(): void
    {
        $year1 = $this->reports->receiptsAndPayments($this->charityId, '2025-04-01', '2026-03-31')['totals'];
        $year2 = $this->reports->receiptsAndPayments($this->charityId, '2026-04-01', '2027-03-31')['totals'];

        foreach (array_keys($year1['closing']) as $column) {
            $this->assertEqualsWithDelta($year1['closing'][$column], $year2['opening'][$column] ?? 0.0, 0.001, $column);
        }
    }

    public function testAllReportsAgree(): void
    {
        foreach (self::PERIODS as [$from, $to]) {
            $rp = $this->reports->receiptsAndPayments($this->charityId, $from, $to);
            $detailed = $this->reports->detailedReceiptsAndPayments($this->charityId, $from, $to);
            $byFund = $this->reports->fundByFundBreakdown($this->charityId, $from, $to);
            $byFundOp = $this->reports->fundByFundOperationalBreakdown($this->charityId, $from, $to);
            $receiptAnalysis = $this->reports->categoryAnalysis($this->charityId, $from, $to, 'receipt');
            $paymentAnalysis = $this->reports->categoryAnalysis($this->charityId, $from, $to, 'payment');
            $summary = $this->reports->fundSummary($this->charityId, $to, $from);

            $label = "$from to $to";
            $this->assertEquals($rp['totals'], $detailed['totals'], $label);

            foreach ([$byFund, $byFundOp] as $breakdown) {
                $this->assertEqualsWithDelta($rp['totals']['receipts']['total'], $breakdown['grand_totals']['receipts'], 0.001, $label);
                $this->assertEqualsWithDelta($rp['totals']['payments']['total'], $breakdown['grand_totals']['payments'], 0.001, $label);
                $this->assertEqualsWithDelta($rp['totals']['closing']['total'], $breakdown['grand_totals']['closing'], 0.001, $label);
                $this->assertEqualsWithDelta(
                    $breakdown['grand_totals']['receipts'],
                    array_sum(array_column($breakdown['receipt_categories'], 'total')),
                    0.001,
                    "$label: category rows add up to the total"
                );
            }

            $this->assertEqualsWithDelta($rp['totals']['receipts']['total'], array_sum(array_column($receiptAnalysis['categories'], 'total')), 0.001, $label);
            $this->assertEqualsWithDelta($rp['totals']['payments']['total'], array_sum(array_column($paymentAnalysis['categories'], 'total')), 0.001, $label);

            $this->assertEqualsWithDelta($rp['totals']['opening']['total'], $summary['totals']['total']['opening'], 0.001, $label);
            $this->assertEqualsWithDelta($rp['totals']['closing']['total'], $summary['totals']['total']['balance'], 0.001, $label);
        }
    }

    public function testCashEqualsFundsWhenEverythingIsAssigned(): void
    {
        $cash = $this->reports->receiptsAndPayments($this->charityId, '2025-04-01', '2026-03-31')['cash'];

        $this->assertNotNull($cash);
        // Bank: 1000 + 160 - 50 = 1110, which is every fund except the invested endowment
        $this->assertEqualsWithDelta(1110.0, $cash['total'], 0.001);
        $this->assertEqualsWithDelta(6110.0, $cash['total_funds'], 0.001);
        $this->assertEqualsWithDelta(-5000.0, $cash['difference'], 0.001, 'The difference is exactly the invested endowment');
    }

    public function testDashboardYearToDateTotalsRespectTheDateRange(): void
    {
        $totals = $this->getService(TransactionService::class)->getTotalsByCategory($this->charityId, '2026-04-01', '2027-03-31');

        $receipts = array_sum(array_map(
            fn($r) => $r['category_type'] === 'receipt' ? (float) $r['total'] : 0,
            $totals
        ));
        $this->assertEqualsWithDelta(70.0, $receipts, 0.001, 'Only the second year\'s £70 receipt counts');
    }
}
