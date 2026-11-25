<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\CashBookService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\ReportService;
use CharityCashbook\Core\DatabaseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase
{
    private MockObject $db;
    private MockObject $fundService;
    private MockObject $cashBookService;
    private ReportService $service;

    /** @var array<int, array> fund id => fund row */
    private array $funds;
    /** @var array<int, array> fund id => [opening, receipts, payments, transfers_in, transfers_out] in pounds */
    private array $figures;
    /** Rows returned by the category aggregate query */
    private array $categoryRows = [];

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->fundService = $this->createMock(FundService::class);
        $this->cashBookService = $this->createMock(CashBookService::class);

        $this->funds = [
            10 => ['id' => 10, 'charity_id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'is_active' => 1, 'opening_balance' => '1000.00', 'opening_balance_date' => null],
            11 => ['id' => 11, 'charity_id' => 1, 'name' => 'Roof appeal', 'fund_type' => 'restricted', 'is_active' => 1, 'opening_balance' => '0.00', 'opening_balance_date' => null],
            12 => ['id' => 12, 'charity_id' => 1, 'name' => 'Old project', 'fund_type' => 'restricted', 'is_active' => 0, 'opening_balance' => '0.00', 'opening_balance_date' => null],
            13 => ['id' => 13, 'charity_id' => 1, 'name' => 'Closed & empty', 'fund_type' => 'unrestricted', 'is_active' => 0, 'opening_balance' => '0.00', 'opening_balance_date' => null],
        ];
        // opening, receipts, payments, transfers in, transfers out
        $this->figures = [
            10 => [1000.00, 500.00, 300.00, 0.00, 200.00],
            11 => [0.00, 150.00, 0.00, 200.00, 0.00],
            12 => [80.00, 0.00, 80.00, 0.00, 0.00],   // inactive but spent its money this period
            13 => [0.00, 0.00, 0.00, 0.00, 0.00],     // inactive with nothing: left out
        ];

        $this->fundService->method('getAll')->willReturn(array_values($this->funds));
        $this->fundService->method('balanceAt')
            ->willReturnCallback(fn(array $fund) => $this->figures[$fund['id']][0]);
        $this->fundService->method('movement')
            ->willReturnCallback(fn(array $fund) => [
                'receipts' => $this->figures[$fund['id']][1],
                'payments' => $this->figures[$fund['id']][2],
                'transfers_in' => $this->figures[$fund['id']][3],
                'transfers_out' => $this->figures[$fund['id']][4],
            ]);

        $this->categoryRows = [
            $this->row(1, 'Donations', 1, 'Donations', 'receipt', 10, '400.00'),
            $this->row(2, 'Gift Aid', 1, 'Donations', 'receipt', 10, '100.00'),
            $this->row(1, 'Donations', 1, 'Donations', 'receipt', 11, '150.00'),
            $this->row(3, 'Hall hire', 2, 'Premises', 'payment', 10, '300.00'),
            // A hidden category still has its history reported
            $this->row(9, 'Old supplies (hidden)', 3, 'Supplies (hidden)', 'payment', 12, '80.00'),
        ];
        $this->db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'SUM(CASE WHEN t.transaction_type')) {
                return $this->categoryRows;
            }
            return [];
        });

        $this->service = new ReportService(
            $this->db,
            $this->fundService,
            $this->createMock(CategoryService::class),
            $this->cashBookService
        );
    }

    private function row(int $ocId, string $ocName, int $rcId, string $rcName, string $side, int $fundId, string $total): array
    {
        return [
            'operational_category_id' => $ocId,
            'operational_category_name' => $ocName,
            'oc_display_order' => $ocId,
            'reporting_category_id' => $rcId,
            'reporting_category_name' => $rcName,
            'rc_display_order' => $rcId,
            'side' => $side,
            'fund_id' => $fundId,
            'total' => $total,
            'transaction_count' => 2,
        ];
    }

    public function testCategoryTotalsFilterBeforeAggregating(): void
    {
        $captured = null;
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('query')->willReturnCallback(function ($sql, $params) use (&$captured) {
            $captured = [$sql, $params];
            return [];
        });
        $service = new ReportService($db, $this->fundService, $this->createMock(CategoryService::class));

        $service->categoryFundTotals(1, '2026-04-01', '2027-03-31');

        [$sql, $params] = $captured;
        // Every filter is in WHERE (an INNER JOIN query), so rows outside the period can't leak in
        $this->assertStringNotContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('t.is_deleted = FALSE', $sql);
        $this->assertStringContainsString('t.transaction_date >= ?', $sql);
        $this->assertStringContainsString('t.transaction_date <= ?', $sql);
        // Same opening-balance rule as fund balances
        $this->assertStringContainsString('t.transaction_date > f.opening_balance_date', $sql);
        // Hidden categories are not filtered out
        $this->assertStringNotContainsString('is_active', $sql);
        $this->assertSame([1, '2026-04-01', '2027-03-31'], $params);
    }

    public function testReceiptsAndPaymentsColumnsAndTotals(): void
    {
        $report = $this->service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');

        $this->assertSame(['unrestricted', 'restricted'], $report['fund_types']);

        $donations = $report['receipts'][0];
        $this->assertSame('Donations', $donations['name']);
        $this->assertEquals(['unrestricted' => 500.0, 'restricted' => 150.0], $donations['amounts']);
        $this->assertEquals(650.0, $donations['total']);

        $this->assertEquals(650.0, $report['totals']['receipts']['total']);
        $this->assertEquals(380.0, $report['totals']['payments']['total']);
        $this->assertEquals(80.0, $report['totals']['payments']['restricted']);
    }

    public function testHiddenCategoryAndInactiveFundAreIncluded(): void
    {
        $report = $this->service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');

        $paymentNames = array_column($report['payments'], 'name');
        $this->assertContains('Supplies (hidden)', $paymentNames);
        // The inactive fund's opening balance is in the totals
        $this->assertEquals(80.0, $report['totals']['opening']['restricted']);
    }

    public function testEveryColumnBalances(): void
    {
        $report = $this->service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');
        $t = $report['totals'];

        foreach (array_merge($report['fund_types'], ['total']) as $col) {
            $this->assertEqualsWithDelta(
                $t['closing'][$col],
                $t['opening'][$col] + $t['receipts'][$col] - $t['payments'][$col] + $t['transfers_net'][$col],
                0.001,
                "Column $col does not balance"
            );
        }
        // Transfers between funds net to zero overall
        $this->assertEquals(0.0, $t['transfers_net']['total']);
        $this->assertEquals(200.0, $t['transfers_net']['restricted']);
        $this->assertEquals(1350.0, $t['closing']['total']);
    }

    public function testEndowmentColumnAppearsWhenThereIsAnEndowmentFund(): void
    {
        $this->funds[14] = ['id' => 14, 'charity_id' => 1, 'name' => 'Endowment', 'fund_type' => 'endowment', 'is_active' => 1, 'opening_balance' => '5000.00', 'opening_balance_date' => null];
        $this->figures[14] = [5000.00, 0.00, 0.00, 0.00, 0.00];
        $fundService = $this->createMock(FundService::class);
        $fundService->method('getAll')->willReturn(array_values($this->funds));
        $fundService->method('balanceAt')->willReturnCallback(fn(array $fund) => $this->figures[$fund['id']][0]);
        $fundService->method('movement')->willReturnCallback(fn(array $fund) => [
            'receipts' => $this->figures[$fund['id']][1],
            'payments' => $this->figures[$fund['id']][2],
            'transfers_in' => $this->figures[$fund['id']][3],
            'transfers_out' => $this->figures[$fund['id']][4],
        ]);
        $service = new ReportService($this->db, $fundService, $this->createMock(CategoryService::class));

        $report = $service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');

        $this->assertSame(['unrestricted', 'restricted', 'endowment'], $report['fund_types']);
        $this->assertEquals(5000.0, $report['totals']['closing']['endowment']);
    }

    public function testDetailedReportMatchesSummaryTotals(): void
    {
        $summary = $this->service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');
        $detailed = $this->service->detailedReceiptsAndPayments(1, '2026-04-01', '2027-03-31');

        $this->assertEquals($summary['totals'], $detailed['totals']);
        $donations = $detailed['receipts'][0];
        $this->assertSame(['Donations', 'Gift Aid'], array_column($donations['operational'], 'name'));
        $this->assertEquals(550.0, $donations['operational'][0]['total']);
    }

    public function testFundByFundUsesEachLinesFund(): void
    {
        $report = $this->service->fundByFundBreakdown(1, '2026-04-01', '2027-03-31');

        // The empty inactive fund is not a column
        $this->assertSame([10, 11, 12], array_column($report['funds'], 'id'));
        $donations = $report['receipt_categories'][0];
        $this->assertEquals(500.0, $donations['funds'][10]);
        $this->assertEquals(150.0, $donations['funds'][11]);
        $this->assertEquals(1350.0, $report['grand_totals']['closing']);
        $this->assertEquals(1350.0, array_sum(array_column($report['fund_totals'], 'closing')));
    }

    public function testCategoryAnalysisTotalsAndPercentages(): void
    {
        $report = $this->service->categoryAnalysis(1, '2026-04-01', '2027-03-31', 'receipt');

        $this->assertCount(1, $report['categories']);
        $donations = $report['categories'][0];
        $this->assertEquals(650.0, $donations['total']);
        $this->assertEqualsWithDelta(84.6, $donations['operational'][0]['percentage'], 0.05);
        $this->assertEqualsWithDelta(15.4, $donations['operational'][1]['percentage'], 0.05);
    }

    public function testFundSummaryTotalsByType(): void
    {
        $this->fundService->method('getAllBalances')->willReturn([
            ['fund_type' => 'unrestricted', 'opening' => 10.0, 'receipts' => 5.0, 'payments' => 1.0, 'transfers_in' => 0.0, 'transfers_out' => 2.0, 'balance' => 12.0],
            ['fund_type' => 'endowment', 'opening' => 100.0, 'receipts' => 0.0, 'payments' => 0.0, 'transfers_in' => 0.0, 'transfers_out' => 0.0, 'balance' => 100.0],
        ]);

        $report = $this->service->fundSummary(1, '2027-03-31', '2026-04-01');

        $this->assertEquals(112.0, $report['totals']['total']['balance']);
        $this->assertEquals(100.0, $report['totals']['endowment']['balance']);
        $this->assertEquals(110.0, $report['totals']['total']['opening']);
    }

    public function testCashPositionShowsDifferenceFromFunds(): void
    {
        $this->cashBookService->method('getCashBooks')->willReturn([
            ['id' => 20, 'name' => 'Bank', 'is_active' => 1],
            ['id' => 21, 'name' => 'Old tin', 'is_active' => 0],
        ]);
        $this->cashBookService->method('calculateBalance')->willReturnCallback(
            fn(int $id) => ['current_balance' => $id === 20 ? 1250.0 : 0.0]
        );
        $this->cashBookService->method('countUnassignedTransactions')->willReturn(1);

        $report = $this->service->receiptsAndPayments(1, '2026-04-01', '2027-03-31');

        $this->assertCount(1, $report['cash']['cash_books']);
        $this->assertEquals(1250.0, $report['cash']['total']);
        $this->assertEquals(-100.0, $report['cash']['difference']);
        $this->assertSame(1, $report['cash']['unassigned_count']);
    }

    public function testCsvExportEscapesFormulas(): void
    {
        $csv = $this->service->exportTransactionsCsv([[
            'transaction_date' => '2026-05-01',
            'transaction_type' => 'receipt',
            'description' => '=HYPERLINK("http://evil","click")',
            'amount' => '10',
            'fund_name' => 'General',
            'category_name' => '+Donations',
            'donor_name' => '@Bob',
            'reference' => '-1',
            'giftaid_status' => 'eligible',
        ]]);

        $lines = explode("\n", trim($csv));
        $this->assertSame('Date,Type,Description,Amount,Fund,Category,Donor,Reference,"Gift Aid"', $lines[0]);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"",""click"")"', $lines[1]);
        $this->assertStringContainsString("'+Donations", $lines[1]);
        $this->assertStringContainsString("'@Bob", $lines[1]);
        $this->assertStringContainsString("'-1", $lines[1]);
        $this->assertStringContainsString('10.00', $lines[1]);
    }
}
