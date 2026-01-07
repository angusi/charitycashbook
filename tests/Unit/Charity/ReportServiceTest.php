<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\ReportService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Core\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ReportServiceTest extends TestCase
{
    private ReportService $service;
    private MockObject $db;
    private MockObject $fundService;
    private MockObject $categoryService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->fundService = $this->createMock(FundService::class);
        $this->categoryService = $this->createMock(CategoryService::class);

        $this->service = new ReportService(
            $this->db,
            $this->fundService,
            $this->categoryService
        );
    }

    // ===========================================
    // receiptsAndPayments() tests
    // ===========================================

    public function testReceiptsAndPaymentsReturnsCorrectStructure(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '0'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([
                ['id' => 1, 'name' => 'Donations', 'category_type' => 'receipt'],
                ['id' => 2, 'name' => 'Expenses', 'category_type' => 'payment'],
            ]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->receiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('receipts', $result);
        $this->assertArrayHasKey('payments', $result);
        $this->assertArrayHasKey('total_receipts', $result);
        $this->assertArrayHasKey('total_payments', $result);
        $this->assertArrayHasKey('total_receipts_unrestricted', $result);
        $this->assertArrayHasKey('total_receipts_restricted', $result);
        $this->assertEquals('2024-01-01', $result['period_start']);
        $this->assertEquals('2024-12-31', $result['period_end']);
    }

    public function testReceiptsAndPaymentsWithSpecificFund(): void
    {
        $this->fundService->expects($this->once())
            ->method('get')
            ->with(1, 1)
            ->willReturn(['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '0']);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->receiptsAndPayments(1, '2024-01-01', '2024-12-31', 1);

        $this->assertIsArray($result);
    }

    public function testReceiptsAndPaymentsAccumulatesUnrestrictedFunds(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '0'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([
                ['id' => 1, 'name' => 'Donations', 'category_type' => 'receipt'],
            ]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([
                ['category_id' => 1, 'name' => 'Donations', 'category_type' => 'receipt', 'total' => '500.00'],
            ]);

        $result = $this->service->receiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(500.0, $result['total_receipts_unrestricted']);
        $this->assertEquals(0.0, $result['total_receipts_restricted']);
    }

    public function testReceiptsAndPaymentsAccumulatesRestrictedFunds(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 2, 'name' => 'Project Fund', 'fund_type' => 'restricted', 'opening_balance' => '0'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([
                ['id' => 1, 'name' => 'Grants', 'category_type' => 'receipt'],
            ]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([
                ['category_id' => 1, 'name' => 'Grants', 'category_type' => 'receipt', 'total' => '1000.00'],
            ]);

        $result = $this->service->receiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(0.0, $result['total_receipts_unrestricted']);
        $this->assertEquals(1000.0, $result['total_receipts_restricted']);
    }

    public function testReceiptsAndPaymentsFiltersEmptyCategories(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '0'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([
                ['id' => 1, 'name' => 'Donations', 'category_type' => 'receipt'],
                ['id' => 2, 'name' => 'Empty Category', 'category_type' => 'receipt'],
            ]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        // Only return data for one category
        $this->db->method('query')
            ->willReturn([
                ['category_id' => 1, 'name' => 'Donations', 'category_type' => 'receipt', 'total' => '100.00'],
            ]);

        $result = $this->service->receiptsAndPayments(1, '2024-01-01', '2024-12-31');

        // Empty categories should be filtered out
        $this->assertCount(1, $result['receipts']);
    }

    // ===========================================
    // detailedReceiptsAndPayments() tests
    // ===========================================

    public function testDetailedReceiptsAndPaymentsReturnsCorrectStructure(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([]);

        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->detailedReceiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('receipts', $result);
        $this->assertArrayHasKey('payments', $result);
        $this->assertArrayHasKey('total_receipts', $result);
        $this->assertArrayHasKey('total_payments', $result);
    }

    public function testDetailedReceiptsAndPaymentsGroupsByCategories(): void
    {
        $this->fundService->method('getAll')->willReturn([]);

        $this->db->method('query')
            ->willReturn([
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'rc_display_order' => 1,
                    'operational_category_id' => 10,
                    'operational_category_name' => 'Regular Donations',
                    'oc_display_order' => 1,
                    'fund_type' => 'unrestricted',
                    'total' => '500.00',
                ],
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'rc_display_order' => 1,
                    'operational_category_id' => 11,
                    'operational_category_name' => 'One-off Donations',
                    'oc_display_order' => 2,
                    'fund_type' => 'unrestricted',
                    'total' => '300.00',
                ],
            ]);

        $result = $this->service->detailedReceiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertCount(1, $result['receipts']);
        $this->assertEquals('Donations', $result['receipts'][0]['name']);
        $this->assertEquals(800.0, $result['receipts'][0]['total']);
        $this->assertCount(2, $result['receipts'][0]['operational']);
    }

    public function testDetailedReceiptsAndPaymentsSeparatesRestrictedUnrestricted(): void
    {
        $this->fundService->method('getAll')->willReturn([]);

        $this->db->method('query')
            ->willReturn([
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'rc_display_order' => 1,
                    'operational_category_id' => 10,
                    'operational_category_name' => 'Regular Donations',
                    'oc_display_order' => 1,
                    'fund_type' => 'unrestricted',
                    'total' => '500.00',
                ],
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'rc_display_order' => 1,
                    'operational_category_id' => 10,
                    'operational_category_name' => 'Regular Donations',
                    'oc_display_order' => 1,
                    'fund_type' => 'restricted',
                    'total' => '200.00',
                ],
            ]);

        $result = $this->service->detailedReceiptsAndPayments(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(500.0, $result['total_receipts_unrestricted']);
        $this->assertEquals(200.0, $result['total_receipts_restricted']);
    }

    // ===========================================
    // fundSummary() tests
    // ===========================================

    public function testFundSummaryReturnsCorrectStructure(): void
    {
        $this->fundService->method('getAllBalances')
            ->willReturn([
                [
                    'id' => 1,
                    'name' => 'General',
                    'fund_type' => 'unrestricted',
                    'opening' => 1000.0,
                    'receipts' => 500.0,
                    'payments' => 200.0,
                    'balance' => 1300.0,
                ],
            ]);

        $result = $this->service->fundSummary(1);

        $this->assertArrayHasKey('as_of_date', $result);
        $this->assertArrayHasKey('funds', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('unrestricted', $result['totals']);
        $this->assertArrayHasKey('restricted', $result['totals']);
        $this->assertArrayHasKey('total', $result['totals']);
    }

    public function testFundSummaryCalculatesTotals(): void
    {
        $this->fundService->method('getAllBalances')
            ->willReturn([
                [
                    'id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted',
                    'opening' => 1000.0, 'receipts' => 500.0, 'payments' => 200.0, 'balance' => 1300.0,
                ],
                [
                    'id' => 2, 'name' => 'Project', 'fund_type' => 'restricted',
                    'opening' => 500.0, 'receipts' => 300.0, 'payments' => 100.0, 'balance' => 700.0,
                ],
            ]);

        $result = $this->service->fundSummary(1);

        $this->assertEquals(1000.0, $result['totals']['unrestricted']['opening']);
        $this->assertEquals(1300.0, $result['totals']['unrestricted']['balance']);
        $this->assertEquals(500.0, $result['totals']['restricted']['opening']);
        $this->assertEquals(700.0, $result['totals']['restricted']['balance']);
        $this->assertEquals(1500.0, $result['totals']['total']['opening']);
        $this->assertEquals(2000.0, $result['totals']['total']['balance']);
    }

    public function testFundSummaryWithAsOfDate(): void
    {
        $this->fundService->expects($this->once())
            ->method('getAllBalances')
            ->with(1, '2024-06-30')
            ->willReturn([]);

        $result = $this->service->fundSummary(1, '2024-06-30');

        $this->assertEquals('2024-06-30', $result['as_of_date']);
    }

    // ===========================================
    // fundByFundBreakdown() tests
    // ===========================================

    public function testFundByFundBreakdownReturnsCorrectStructure(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '0'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->fundByFundBreakdown(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('funds', $result);
        $this->assertArrayHasKey('fund_totals', $result);
        $this->assertArrayHasKey('receipt_categories', $result);
        $this->assertArrayHasKey('payment_categories', $result);
        $this->assertArrayHasKey('grand_totals', $result);
    }

    public function testFundByFundBreakdownCalculatesGrandTotals(): void
    {
        $this->fundService->method('getAll')
            ->willReturn([
                ['id' => 1, 'name' => 'General', 'fund_type' => 'unrestricted', 'opening_balance' => '100'],
                ['id' => 2, 'name' => 'Project', 'fund_type' => 'restricted', 'opening_balance' => '50'],
            ]);

        $this->categoryService->method('getReportingCategories')
            ->willReturn([]);

        $this->db->method('queryOne')
            ->willReturn(['receipts' => '0', 'payments' => '0']);

        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->fundByFundBreakdown(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('opening', $result['grand_totals']);
        $this->assertArrayHasKey('receipts', $result['grand_totals']);
        $this->assertArrayHasKey('payments', $result['grand_totals']);
        $this->assertArrayHasKey('closing', $result['grand_totals']);
    }

    // ===========================================
    // categoryAnalysis() tests
    // ===========================================

    public function testCategoryAnalysisReturnsCorrectStructure(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->categoryAnalysis(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('categories', $result);
    }

    public function testCategoryAnalysisGroupsByReportingCategory(): void
    {
        $this->db->method('query')
            ->willReturn([
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'operational_category_id' => 10,
                    'operational_category_name' => 'Regular',
                    'total' => '500.00',
                    'transaction_count' => '10',
                ],
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'operational_category_id' => 11,
                    'operational_category_name' => 'One-off',
                    'total' => '300.00',
                    'transaction_count' => '5',
                ],
            ]);

        $result = $this->service->categoryAnalysis(1, '2024-01-01', '2024-12-31');

        $this->assertCount(1, $result['categories']);
        $this->assertEquals('Donations', $result['categories'][0]['name']);
        $this->assertEquals(800.0, $result['categories'][0]['total']);
        $this->assertEquals(15, $result['categories'][0]['count']);
        $this->assertCount(2, $result['categories'][0]['operational']);
    }

    public function testCategoryAnalysisCalculatesPercentages(): void
    {
        $this->db->method('query')
            ->willReturn([
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'operational_category_id' => 10,
                    'operational_category_name' => 'Regular',
                    'total' => '800.00',
                    'transaction_count' => '10',
                ],
                [
                    'reporting_category_id' => 1,
                    'reporting_category_name' => 'Donations',
                    'category_type' => 'receipt',
                    'operational_category_id' => 11,
                    'operational_category_name' => 'One-off',
                    'total' => '200.00',
                    'transaction_count' => '5',
                ],
            ]);

        $result = $this->service->categoryAnalysis(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(80.0, $result['categories'][0]['operational'][0]['percentage']);
        $this->assertEquals(20.0, $result['categories'][0]['operational'][1]['percentage']);
    }

    public function testCategoryAnalysisFiltersTypeParameter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('category_type = ?'),
                $this->callback(function ($params) {
                    return $params[4] === 'receipt';
                })
            )
            ->willReturn([]);

        $this->service->categoryAnalysis(1, '2024-01-01', '2024-12-31', 'receipt');
    }

    // ===========================================
    // giftAidReport() tests
    // ===========================================

    public function testGiftAidReportReturnsCorrectStructure(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->giftAidReport(1, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('eligible', $result['totals']);
        $this->assertArrayHasKey('gasds', $result['totals']);
        $this->assertArrayHasKey('claimed', $result['totals']);
        $this->assertArrayHasKey('not_eligible', $result['totals']);
    }

    public function testGiftAidReportCalculatesTotals(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'amount' => '100.00', 'giftaid_status' => 'eligible'],
                ['id' => 2, 'amount' => '50.00', 'giftaid_status' => 'eligible'],
                ['id' => 3, 'amount' => '30.00', 'giftaid_status' => 'gasds'],
                ['id' => 4, 'amount' => '200.00', 'giftaid_status' => 'claimed'],
            ]);

        $result = $this->service->giftAidReport(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(2, $result['totals']['eligible']['count']);
        $this->assertEquals(150.0, $result['totals']['eligible']['amount']);
        $this->assertEquals(37.5, $result['totals']['eligible']['giftaid']); // 150 * 0.25

        $this->assertEquals(1, $result['totals']['gasds']['count']);
        $this->assertEquals(30.0, $result['totals']['gasds']['amount']);
        $this->assertEquals(7.5, $result['totals']['gasds']['giftaid']); // 30 * 0.25

        $this->assertEquals(1, $result['totals']['claimed']['count']);
        $this->assertEquals(200.0, $result['totals']['claimed']['amount']);
    }

    public function testGiftAidReportFiltersStatusParameter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('giftaid_status = ?'),
                $this->callback(function ($params) {
                    return $params[3] === 'eligible';
                })
            )
            ->willReturn([]);

        $this->service->giftAidReport(1, '2024-01-01', '2024-12-31', 'eligible');
    }

    // ===========================================
    // exportTransactionsCsv() tests
    // ===========================================

    public function testExportTransactionsCsvReturnsValidCsv(): void
    {
        $transactions = [
            [
                'transaction_date' => '2024-01-15',
                'transaction_type' => 'receipt',
                'description' => 'Donation',
                'amount' => '100.00',
                'fund_name' => 'General',
                'category_name' => 'Donations',
                'donor_name' => 'John Smith',
                'reference' => 'REF001',
                'giftaid_status' => 'eligible',
            ],
        ];

        $result = $this->service->exportTransactionsCsv($transactions);

        $this->assertStringContainsString('Date,Type,Description,Amount,Fund,Category,Donor,Reference,Gift Aid', $result);
        $this->assertStringContainsString('2024-01-15', $result);
        $this->assertStringContainsString('Donation', $result);
        $this->assertStringContainsString('100.00', $result);
        $this->assertStringContainsString('John Smith', $result);
    }

    public function testExportTransactionsCsvEscapesQuotes(): void
    {
        $transactions = [
            [
                'transaction_date' => '2024-01-15',
                'transaction_type' => 'receipt',
                'description' => 'Test "quoted" text',
                'amount' => '50.00',
                'fund_name' => 'Fund "A"',
                'category_name' => 'Category',
                'donor_name' => null,
                'reference' => null,
                'giftaid_status' => 'not_applicable',
            ],
        ];

        $result = $this->service->exportTransactionsCsv($transactions);

        // Double quotes should be escaped as ""
        $this->assertStringContainsString('Test ""quoted"" text', $result);
        $this->assertStringContainsString('Fund ""A""', $result);
    }

    public function testExportTransactionsCsvHandlesNullValues(): void
    {
        $transactions = [
            [
                'transaction_date' => '2024-01-15',
                'transaction_type' => 'payment',
                'description' => 'Expense',
                'amount' => '25.00',
                'fund_name' => 'General',
                'category_name' => 'Expenses',
                'donor_name' => null,
                'reference' => null,
                'giftaid_status' => 'not_applicable',
            ],
        ];

        $result = $this->service->exportTransactionsCsv($transactions);

        // Should not throw errors for null values
        $this->assertStringContainsString('Expense', $result);
    }

    public function testExportTransactionsCsvReturnsHeaderForEmptyArray(): void
    {
        $result = $this->service->exportTransactionsCsv([]);

        $this->assertStringContainsString('Date,Type,Description,Amount,Fund,Category,Donor,Reference,Gift Aid', $result);
        $this->assertEquals(1, substr_count($result, "\n")); // Only header line
    }
}
