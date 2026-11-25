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
                'needs_review_count' => '0',
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
    // updateClaim() tests
    // ===========================================


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


    // ===========================================
    // submitClaim() tests
    // ===========================================


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

}
