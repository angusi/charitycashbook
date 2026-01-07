<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class GiftAidServiceTest extends TestCase
{
    private GiftAidService $service;
    private MockObject $db;
    private MockObject $logger;
    private MockObject $auditService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new GiftAidService(
            $this->db,
            $this->logger,
            $this->auditService
        );
    }

    // ===========================================
    // getClaims() tests
    // ===========================================

    public function testGetClaimsReturnsArray(): void
    {
        $charityId = 1;
        $expected = [
            ['id' => 1, 'status' => 'draft', 'created_by_name' => 'John'],
            ['id' => 2, 'status' => 'submitted', 'created_by_name' => 'Jane'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getClaims($charityId);
        $this->assertEquals($expected, $result);
    }

    public function testGetClaimsReturnsEmptyArray(): void
    {
        $this->db->method('query')->willReturn([]);
        $result = $this->service->getClaims(1);
        $this->assertEquals([], $result);
    }

    // ===========================================
    // getClaim() tests
    // ===========================================

    public function testGetClaimReturnsClaimDetails(): void
    {
        $expected = [
            'id' => 1,
            'charity_id' => 1,
            'status' => 'draft',
            'total_donations' => '100.00',
            'created_by_name' => 'John',
        ];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($expected);

        $result = $this->service->getClaim(1, 1);
        $this->assertEquals($expected, $result);
    }

    public function testGetClaimReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);
        $result = $this->service->getClaim(999, 1);
        $this->assertNull($result);
    }

    // ===========================================
    // getClaimTransactions() tests
    // ===========================================

    public function testGetClaimTransactionsReturnsTransactions(): void
    {
        $expected = [
            ['id' => 1, 'amount' => '50.00', 'donor_name' => 'Donor 1'],
            ['id' => 2, 'amount' => '30.00', 'donor_name' => 'Donor 2'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getClaimTransactions(1, 1);
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getEligibleTransactions() tests
    // ===========================================

    public function testGetEligibleTransactionsReturnsAll(): void
    {
        $expected = [
            ['id' => 1, 'giftaid_status' => 'eligible', 'donor_name' => 'Test'],
            ['id' => 2, 'giftaid_status' => 'gasds', 'donor_name' => null],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getEligibleTransactions(1, false);
        $this->assertEquals($expected, $result);
    }

    public function testGetEligibleTransactionsUnclaimedOnly(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('giftaid_claim_id IS NULL'))
            ->willReturn([]);

        $result = $this->service->getEligibleTransactions(1, true);
        $this->assertEquals([], $result);
    }

    // ===========================================
    // calculateClaimableAmount() tests
    // ===========================================

    public function testCalculateClaimableAmountReturnsCorrectAmount(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['total' => '400.00']);

        $result = $this->service->calculateClaimableAmount(1);
        // 400 * 0.25 = 100
        $this->assertEquals(100.0, $result);
    }

    public function testCalculateClaimableAmountReturnsZeroForNoTransactions(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['total' => '0']);

        $result = $this->service->calculateClaimableAmount(1);
        $this->assertEquals(0.0, $result);
    }

    // ===========================================
    // getGasdsUsed() tests
    // ===========================================

    public function testGetGasdsUsedReturnsAmount(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn(['total' => '500.00']);

        $result = $this->service->getGasdsUsed(1, '2023');
        $this->assertEquals(500.0, $result);
    }

    public function testGetGasdsUsedCalculatesCorrectDateRange(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->anything(),
                $this->callback(function ($params) {
                    // Financial year 2023 starts 2023-04-06, ends 2024-04-05
                    return $params[1] === '2023-04-06' && $params[2] === '2024-04-05';
                })
            )
            ->willReturn(['total' => '0']);

        $this->service->getGasdsUsed(1, '2023');
    }

    // ===========================================
    // getFinancialYear() tests
    // ===========================================

    public function testGetFinancialYearBeforeApril6(): void
    {
        // March 2024 should be financial year 2023
        $result = $this->service->getFinancialYear('2024-03-15');
        $this->assertEquals('2023', $result);
    }

    public function testGetFinancialYearOnApril6(): void
    {
        // April 6, 2024 should be financial year 2024
        $result = $this->service->getFinancialYear('2024-04-06');
        $this->assertEquals('2024', $result);
    }

    public function testGetFinancialYearAfterApril6(): void
    {
        // July 2024 should be financial year 2024
        $result = $this->service->getFinancialYear('2024-07-15');
        $this->assertEquals('2024', $result);
    }

    public function testGetFinancialYearOnApril5(): void
    {
        // April 5, 2024 should be financial year 2023
        $result = $this->service->getFinancialYear('2024-04-05');
        $this->assertEquals('2023', $result);
    }

    // ===========================================
    // getSummary() tests
    // ===========================================

    public function testGetSummaryReturnsCorrectStructure(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'eligible_total' => '400.00',
                'claimed_total' => '200.00',
                'gasds_total' => '100.00',
                'eligible_count' => '4',
                'claimed_count' => '2',
            ]);

        $result = $this->service->getSummary(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(400.0, $result['eligible_total']);
        $this->assertEquals(100.0, $result['eligible_giftaid']); // 400 * 0.25
        $this->assertEquals(200.0, $result['claimed_total']);
        $this->assertEquals(50.0, $result['claimed_giftaid']); // 200 * 0.25
        $this->assertEquals(100.0, $result['gasds_total']);
        $this->assertEquals(4, $result['eligible_count']);
        $this->assertEquals(2, $result['claimed_count']);
    }

    // ===========================================
    // calculateEligibleTotals() tests
    // ===========================================

    public function testCalculateEligibleTotalsReturnsCorrectStructure(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                // First call: eligible totals
                [
                    'standard_donations' => '1000.00',
                    'gasds_donations' => '200.00',
                    'standard_count' => '10',
                    'gasds_count' => '5',
                ],
                // Second call: charity year end
                [
                    'year_end_day' => '31',
                    'year_end_month' => '3',
                ]
            );
        
        $this->db->method('queryValue')->willReturn('0');

        $result = $this->service->calculateEligibleTotals(1, '2024-01-01', '2024-12-31');

        $this->assertEquals(1000.0, $result['standard_donations']);
        $this->assertEquals(250.0, $result['standard_giftaid']); // 1000 * 0.25
        $this->assertEquals(10, $result['standard_count']);
        $this->assertEquals(200.0, $result['gasds_donations']);
        $this->assertEquals(5, $result['gasds_count']);
        $this->assertArrayHasKey('gasds_claimable', $result);
        $this->assertArrayHasKey('gasds_giftaid', $result);
        $this->assertArrayHasKey('total_giftaid', $result);
    }

    public function testCalculateEligibleTotalsAppliesGasdsCap(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                // Very large GASDS donations
                [
                    'standard_donations' => '100.00',
                    'gasds_donations' => '10000.00', // Over £8k cap
                    'standard_count' => '1',
                    'gasds_count' => '20',
                ],
                [
                    'year_end_day' => '31',
                    'year_end_month' => '3',
                ]
            );
        
        $this->db->method('queryValue')->willReturn('0');

        $result = $this->service->calculateEligibleTotals(1, '2024-01-01', '2024-12-31');

        // Standard gift aid = 100 * 0.25 = 25
        // Matching cap = 25 * 10 = 250 (10x regular Gift Aid)
        // Absolute cap = 2000
        // Effective cap = min(2000, 250) = 250
        // GASDS claim remaining = 250 - 0 = 250
        // Potential GASDS claim = 10000 * 0.25 = 2500
        // Claimable GASDS claim = min(2500, 250) = 250
        $this->assertEquals(250.0, $result['gasds_matching_cap']);
        $this->assertEquals(250.0, $result['gasds_claim_remaining']);
    }

    // ===========================================
    // createClaim() tests
    // ===========================================

    public function testCreateClaimSucceeds(): void
    {
        $charityId = 1;
        $userId = 1;
        $periodStart = '2024-01-01';
        $periodEnd = '2024-03-31';
        $transactionIds = [1, 2, 3];

        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'amount' => '100.00', 'giftaid_status' => 'eligible'],
                ['id' => 2, 'amount' => '50.00', 'giftaid_status' => 'eligible'],
                ['id' => 3, 'amount' => '30.00', 'giftaid_status' => 'gasds'],
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');
        
        $this->db->method('insert')
            ->willReturn(99);

        $this->db->expects($this->once())
            ->method('commit');

        $this->auditService->expects($this->once())
            ->method('log');

        $result = $this->service->createClaim($charityId, $userId, $periodStart, $periodEnd, $transactionIds);
        $this->assertEquals(99, $result);
    }

    public function testCreateClaimThrowsValidationExceptionForIneligibleTransactions(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'amount' => '100.00', 'giftaid_status' => 'eligible'],
                // Only returns 1 transaction instead of 2
            ]);

        $this->expectException(ValidationException::class);

        $this->service->createClaim(1, 1, '2024-01-01', '2024-03-31', [1, 2]);
    }

    public function testCreateClaimRollsBackOnException(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'amount' => '100.00', 'giftaid_status' => 'eligible'],
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('insert')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->createClaim(1, 1, '2024-01-01', '2024-03-31', [1]);
    }

    // ===========================================
    // updateClaim() tests
    // ===========================================

    public function testUpdateClaimSucceeds(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
                'charity_id' => 1,
            ]);

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                // Current transactions
                [['transaction_id' => 1]],
                // New transactions validation
                [
                    ['id' => 2, 'amount' => '100.00', 'giftaid_status' => 'eligible'],
                ]
            );

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->expects($this->once())
            ->method('commit');

        $this->auditService->expects($this->once())
            ->method('log');

        $this->service->updateClaim(1, 1, 1, '2024-01-01', '2024-06-30', [2]);
    }

    public function testUpdateClaimThrowsExceptionForNonexistentClaim(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->service->updateClaim(999, 1, 1, '2024-01-01', '2024-06-30', [1]);
    }

    public function testUpdateClaimThrowsExceptionForNonDraftClaim(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'submitted', // Not draft
            ]);

        $this->expectException(ValidationException::class);
        $this->service->updateClaim(1, 1, 1, '2024-01-01', '2024-06-30', [1]);
    }

    public function testUpdateClaimThrowsExceptionForEmptyTransactions(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->expectException(ValidationException::class);
        $this->service->updateClaim(1, 1, 1, '2024-01-01', '2024-06-30', []);
    }

    public function testUpdateClaimRollsBackOnException(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['transaction_id' => 1]],
                [['id' => 2, 'amount' => '100.00', 'giftaid_status' => 'eligible']]
            );

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('execute')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->updateClaim(1, 1, 1, '2024-01-01', '2024-06-30', [2]);
    }

    // ===========================================
    // submitClaim() tests
    // ===========================================

    public function testSubmitClaimSucceeds(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->expects($this->once())
            ->method('commit');

        $this->auditService->expects($this->once())
            ->method('log');

        $this->service->submitClaim(1, 1, 1, 'HMRC-REF-123');
    }

    public function testSubmitClaimThrowsExceptionForNonexistentClaim(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->service->submitClaim(999, 1, 1);
    }

    public function testSubmitClaimThrowsExceptionForNonDraftClaim(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'submitted',
            ]);

        $this->expectException(ValidationException::class);
        $this->service->submitClaim(1, 1, 1);
    }

    public function testSubmitClaimRollsBackOnException(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('execute')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->submitClaim(1, 1, 1);
    }

    // ===========================================
    // unsubmitClaim() tests
    // ===========================================

    public function testUnsubmitClaimSucceeds(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'submitted',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->expects($this->once())
            ->method('commit');

        $this->auditService->expects($this->once())
            ->method('log');

        $this->service->unsubmitClaim(1, 1, 1);
    }

    public function testUnsubmitClaimThrowsExceptionForNonexistentClaim(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->service->unsubmitClaim(999, 1, 1);
    }

    public function testUnsubmitClaimThrowsExceptionForNonSubmittedClaim(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->expectException(ValidationException::class);
        $this->service->unsubmitClaim(1, 1, 1);
    }

    public function testUnsubmitClaimRollsBackOnException(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'submitted',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('execute')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->unsubmitClaim(1, 1, 1);
    }

    // ===========================================
    // cancelClaim() tests
    // ===========================================

    public function testCancelClaimSucceeds(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->expects($this->once())
            ->method('commit');

        $this->auditService->expects($this->once())
            ->method('log');

        $this->service->cancelClaim(1, 1, 1);
    }

    public function testCancelClaimThrowsExceptionForNonexistentClaim(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->service->cancelClaim(999, 1, 1);
    }

    public function testCancelClaimThrowsExceptionForNonDraftClaim(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'submitted',
            ]);

        $this->expectException(ValidationException::class);
        $this->service->cancelClaim(1, 1, 1);
    }

    public function testCancelClaimRollsBackOnException(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'status' => 'draft',
            ]);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('execute')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->cancelClaim(1, 1, 1);
    }

    // ===========================================
    // generateClaimReport() tests
    // ===========================================

    public function testGenerateClaimReportReturnsEmptyForNonexistentClaim(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->generateClaimReport(999, 1);
        $this->assertEquals([], $result);
    }

    public function testGenerateClaimReportReturnsCorrectStructure(): void
    {
        $this->db->method('queryOne')
            ->willReturn([
                'id' => 1,
                'total_donations' => '200.00',
                'total_giftaid' => '50.00',
                'gasds_donations' => '100.00',
                'gasds_giftaid' => '25.00',
                'items' => [
                    [
                        'transaction_date' => '2024-01-15',
                        'amount' => '100.00',
                        'description' => 'Donation 1',
                        'giftaid_status' => 'eligible',
                        'donor_id' => 1,
                        'donor_name' => 'John Smith',
                        'address_line1' => '123 Main St',
                        'address_postcode' => 'AB1 2CD',
                    ],
                    [
                        'transaction_date' => '2024-01-20',
                        'amount' => '50.00',
                        'description' => 'Cash collection',
                        'giftaid_status' => 'gasds',
                        'donor_id' => null,
                        'donor_name' => null,
                        'address_line1' => null,
                        'address_postcode' => null,
                    ],
                ],
            ]);

        $result = $this->service->generateClaimReport(1, 1);

        $this->assertArrayHasKey('claim', $result);
        $this->assertArrayHasKey('standard_donations', $result);
        $this->assertArrayHasKey('gasds_donations', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('200.00', $result['summary']['standard_total']);
        $this->assertEquals('50.00', $result['summary']['standard_giftaid']);
    }
}
