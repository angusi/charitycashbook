<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\MemberService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

class MemberServiceTest extends TestCase
{
    private MemberService $service;
    private DatabaseInterface $db;
    private Logger $logger;
    private AuditService $auditService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->auditService = $this->createMock(AuditService::class);
        
        $this->service = new MemberService(
            $this->db,
            $this->logger,
            $this->auditService
        );
    }

    // getAll tests
    public function testGetAllReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John Doe'],
        ]);

        $result = $this->service->getAll(1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetAllFiltersActiveOnly(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('m.is_active = TRUE'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getAll(1, ['active_only' => true]);
    }

    public function testGetAllFiltersTrusteesOnly(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('m.is_trustee = TRUE'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getAll(1, ['trustees_only' => true]);
    }

    public function testGetAllFiltersByMembershipType(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('m.membership_type = ?'),
                $this->callback(fn($params) => in_array('Premium', $params))
            )
            ->willReturn([]);

        $this->service->getAll(1, ['membership_type' => 'Premium']);
    }

    public function testGetAllSearchesByNameEmailOrNumber(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('m.name LIKE ?'),
                    $this->stringContains('m.email LIKE ?'),
                    $this->stringContains('m.member_number LIKE ?')
                ),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getAll(1, ['search' => 'john']);
    }

    // getById tests
    public function testGetByIdReturnsMember(): void
    {
        $member = ['id' => 1, 'name' => 'John Doe'];
        $this->db->method('queryOne')->willReturn($member);

        $result = $this->service->getById(1);

        $this->assertIsArray($result);
        $this->assertEquals('John Doe', $result['name']);
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getById(999);

        $this->assertNull($result);
    }

    // get (with charityId validation) tests
    public function testGetReturnsMemberForMatchingCharity(): void
    {
        $member = ['id' => 1, 'name' => 'John Doe', 'charity_id' => 5];
        $this->db->method('queryOne')->willReturn($member);

        $result = $this->service->get(1, 5);

        $this->assertIsArray($result);
        $this->assertEquals('John Doe', $result['name']);
    }

    public function testGetReturnsNullWhenMemberNotInCharity(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->get(1, 999);

        $this->assertNull($result);
    }

    public function testGetQueriesWithCharityIdParameter(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('m.charity_id = ?'),
                [1, 5]
            )
            ->willReturn(['id' => 1]);

        $this->service->get(1, 5);
    }

    // create tests
    public function testCreateReturnsId(): void
    {
        $this->db->method('queryOne')->willReturn(null); // No duplicate
        $this->db->method('insert')->willReturn(5);
        $this->logger->expects($this->once())->method('info');

        $result = $this->service->create(1, ['name' => 'New Member']);

        $this->assertEquals(5, $result);
    }

    public function testCreateThrowsValidationExceptionForMissingName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, []);
    }

    public function testCreateThrowsValidationExceptionForDuplicateMemberNumber(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1]); // Duplicate exists

        $this->expectException(ValidationException::class);

        $this->service->create(1, ['name' => 'Test', 'member_number' => 'M001']);
    }

    // update tests
    public function testUpdateSucceeds(): void
    {
        $member = ['id' => 1, 'name' => 'John', 'charity_id' => 1];
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($member, null); // Member exists, no duplicate
        
        $this->db->expects($this->once())->method('execute');
        $this->logger->expects($this->once())->method('info');

        $this->service->update(1, ['name' => 'John Updated']);
    }

    public function testUpdateThrowsExceptionForNonexistentMember(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->update(999, ['name' => 'Test']);
    }

    public function testUpdateThrowsExceptionForDuplicateMemberNumber(): void
    {
        $member = ['id' => 1, 'name' => 'John', 'charity_id' => 1];
        $duplicate = ['id' => 2];
        
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($member, $duplicate);

        $this->expectException(ValidationException::class);

        $this->service->update(1, ['name' => 'John', 'member_number' => 'M002']);
    }

    // updateAddress tests
    public function testUpdateAddressExecutesUpdate(): void
    {
        $this->db->expects($this->once())->method('execute');

        $this->service->updateAddress(1, [
            'address_line1' => '123 Street',
            'address_postcode' => 'AB1 2CD',
        ]);
    }

    // getTrustees tests
    public function testGetTrusteesReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'Trustee One', 'current_trustee_role' => 'Chair'],
        ]);

        $result = $this->service->getTrustees(1);

        $this->assertIsArray($result);
    }

    // getTrusteeHistory tests
    public function testGetTrusteeHistoryReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'role' => 'Chair', 'start_date' => '2020-01-01'],
        ]);

        $result = $this->service->getTrusteeHistory(1);

        $this->assertIsArray($result);
    }

    // addTrusteeRole tests
    public function testAddTrusteeRoleReturnsId(): void
    {
        $this->db->method('insert')->willReturn(10);

        $result = $this->service->addTrusteeRole(1, 1, [
            'role' => 'Chair',
            'start_date' => '2025-01-01',
        ]);

        $this->assertEquals(10, $result);
    }

    public function testAddTrusteeRoleUpdatesMemberRecord(): void
    {
        $this->db->expects($this->once())->method('execute');
        $this->db->method('insert')->willReturn(1);

        $this->service->addTrusteeRole(1, 1, [
            'role' => 'Chair',
            'start_date' => '2025-01-01',
        ]);
    }

    // getTrusteeRole tests
    public function testGetTrusteeRoleReturnsRole(): void
    {
        $role = ['id' => 1, 'role' => 'Chair', 'member_name' => 'John'];
        $this->db->method('queryOne')->willReturn($role);

        $result = $this->service->getTrusteeRole(1);

        $this->assertIsArray($result);
        $this->assertEquals('Chair', $result['role']);
    }

    public function testGetTrusteeRoleReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getTrusteeRole(999);

        $this->assertNull($result);
    }

    // updateTrusteeRole tests
    public function testUpdateTrusteeRoleExecutesUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE member_trustee_roles'),
                $this->anything()
            );
        $this->db->method('queryOne')->willReturn(null); // No current role

        $this->service->updateTrusteeRole(1, [
            'role' => 'Treasurer',
            'start_date' => '2025-01-01',
        ]);
    }

    public function testUpdateTrusteeRoleUpdatesMemberWhenCurrent(): void
    {
        // First call for the main update, second for member update
        $callCount = 0;
        $this->db->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return 1;
            });

        // Role lookup returns a current role (no end date)
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'member_id' => 5,
            'end_date' => null,
        ]);

        $this->service->updateTrusteeRole(1, [
            'role' => 'Secretary',
            'start_date' => '2025-01-01',
            'end_date' => '', // Empty = still current
        ]);

        $this->assertEquals(2, $callCount); // Both updates executed
    }

    public function testUpdateTrusteeRolePassesCorrectData(): void
    {
        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                if (strpos($sql, 'UPDATE member_trustee_roles') !== false) {
                    $executedParams = $params;
                }
                return 1;
            });
        $this->db->method('queryOne')->willReturn(null);

        $this->service->updateTrusteeRole(1, [
            'role' => 'Trustee',
            'start_date' => '2024-06-01',
            'end_date' => '2025-05-31',
            'notes' => 'Annual term',
        ]);

        $this->assertEquals('Trustee', $executedParams[0]);
        $this->assertEquals('2024-06-01', $executedParams[1]);
        $this->assertEquals('2025-05-31', $executedParams[2]);
        $this->assertEquals('Annual term', $executedParams[3]);
        $this->assertEquals(1, $executedParams[4]); // roleId
    }

    // endTrusteeRole tests
    public function testEndTrusteeRoleUpdatesRole(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['member_id' => 1], // Role lookup
                ['cnt' => 0] // No other current roles
            );
        
        $this->db->expects($this->exactly(2))->method('execute');

        $this->service->endTrusteeRole(1, '2025-12-31');
    }

    public function testEndTrusteeRoleDoesNotUpdateMemberIfOtherRolesExist(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['member_id' => 1],
                ['cnt' => 1] // Other current roles exist
            );
        
        // Should only execute once (the role update, not the member update)
        $this->db->expects($this->once())->method('execute');

        $this->service->endTrusteeRole(1, '2025-12-31');
    }

    // getAllTrusteeHistory tests
    public function testGetAllTrusteeHistoryReturnsArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getAllTrusteeHistory(1);

        $this->assertIsArray($result);
    }

    // getMembershipTypes tests
    public function testGetMembershipTypesReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'Standard'],
        ]);

        $result = $this->service->getMembershipTypes(1);

        $this->assertIsArray($result);
    }

    // createMembershipType tests
    public function testCreateMembershipTypeReturnsId(): void
    {
        $this->db->method('insert')->willReturn(5);

        $result = $this->service->createMembershipType(1, ['name' => 'Premium']);

        $this->assertEquals(5, $result);
    }

    public function testCreateMembershipTypeConvertsEmptyStringFeeToZero(): void
    {
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createMembershipType(1, ['name' => 'Free Member', 'annual_fee' => '']);

        $this->assertEquals(0.0, $insertedData['annual_fee']);
    }

    public function testCreateMembershipTypeConvertsNullFeeToZero(): void
    {
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createMembershipType(1, ['name' => 'Free Member', 'annual_fee' => null]);

        $this->assertEquals(0.0, $insertedData['annual_fee']);
    }

    public function testCreateMembershipTypePreservesValidFee(): void
    {
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createMembershipType(1, ['name' => 'Premium', 'annual_fee' => '25.50']);

        $this->assertEquals(25.50, $insertedData['annual_fee']);
    }

    // updateMembershipType tests
    public function testUpdateMembershipTypeExecutesUpdate(): void
    {
        $this->db->expects($this->once())->method('execute');

        $this->service->updateMembershipType(1, ['name' => 'Updated Type']);
    }

    public function testUpdateMembershipTypeConvertsEmptyStringFeeToZero(): void
    {
        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->updateMembershipType(1, ['name' => 'Free Member', 'annual_fee' => '']);

        $this->assertEquals(0.0, $executedParams[2]); // annual_fee is 3rd parameter
    }

    public function testUpdateMembershipTypeConvertsNullFeeToZero(): void
    {
        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->updateMembershipType(1, ['name' => 'Free Member', 'annual_fee' => null]);

        $this->assertEquals(0.0, $executedParams[2]);
    }

    public function testUpdateMembershipTypePreservesValidFee(): void
    {
        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->updateMembershipType(1, ['name' => 'Premium', 'annual_fee' => '50.00']);

        $this->assertEquals(50.00, $executedParams[2]);
    }

    // getMemberDues tests
    public function testGetMemberDuesReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'amount' => 50],
        ]);

        $result = $this->service->getMemberDues(1);

        $this->assertIsArray($result);
    }

    // getAllDues tests
    public function testGetAllDuesReturnsArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getAllDues(1);

        $this->assertIsArray($result);
    }

    public function testGetAllDuesFiltersStatus(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('md.status = ?'),
                $this->callback(fn($params) => in_array('paid', $params))
            )
            ->willReturn([]);

        $this->service->getAllDues(1, ['status' => 'paid']);
    }

    public function testGetAllDuesFiltersPeriodLabel(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('md.period_label = ?'),
                $this->callback(fn($params) => in_array('2025', $params))
            )
            ->willReturn([]);

        $this->service->getAllDues(1, ['period_label' => '2025']);
    }

    public function testGetAllDuesFiltersByYear(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('YEAR(md.period_start) = ?'),
                $this->callback(fn($params) => in_array(2024, $params))
            )
            ->willReturn([]);

        $this->service->getAllDues(1, ['year' => 2024]);
    }

    public function testGetAllDuesFiltersByMember(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('md.member_id = ?'),
                $this->callback(fn($params) => in_array(42, $params))
            )
            ->willReturn([]);

        $this->service->getAllDues(1, ['member_id' => 42]);
    }

    // getDuesPeriodLabels tests
    public function testGetDuesPeriodLabelsReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['period_label' => '2025'],
            ['period_label' => '2024'],
        ]);

        $result = $this->service->getDuesPeriodLabels(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    // getDuesStatistics tests
    public function testGetDuesStatisticsReturnsFormattedStats(): void
    {
        $this->db->method('queryOne')->willReturn([
            'total_dues' => 10,
            'paid_count' => 8,
            'unpaid_count' => 2,
            'total_due' => 500.00,
            'total_paid' => 400.00,
        ]);

        $result = $this->service->getDuesStatistics(1);

        $this->assertEquals(10, $result['dues_total']);
        $this->assertEquals(8, $result['dues_paid']);
        $this->assertEquals(2, $result['dues_unpaid']);
        $this->assertEquals(500.00, $result['amount_due']);
        $this->assertEquals(400.00, $result['amount_paid']);
    }

    public function testGetDuesStatisticsWithPeriodLabel(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('period_label = ?'),
                $this->callback(fn($params) => in_array('2025', $params))
            )
            ->willReturn(['total_dues' => 5]);

        $this->service->getDuesStatistics(1, '2025');
    }

    public function testGetDuesStatisticsHandlesNullValues(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getDuesStatistics(1);

        $this->assertEquals(0, $result['dues_total']);
        $this->assertEquals(0.0, $result['amount_due']);
    }

    // getDuesRecord (with charity validation) tests
    public function testGetDuesRecordReturnsDues(): void
    {
        $dues = ['id' => 1, 'amount_due' => 50.00, 'status' => 'pending'];
        $this->db->method('queryOne')->willReturn($dues);

        $result = $this->service->getDuesRecord(1, 5);

        $this->assertIsArray($result);
        $this->assertEquals(50.00, $result['amount_due']);
    }

    public function testGetDuesRecordReturnsNullForWrongCharity(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getDuesRecord(1, 999);

        $this->assertNull($result);
    }

    public function testGetDuesRecordQueriesWithCharityId(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('charity_id = ?'),
                [1, 5]
            )
            ->willReturn(['id' => 1]);

        $this->service->getDuesRecord(1, 5);
    }

    // createDues tests
    public function testCreateDuesReturnsId(): void
    {
        $this->db->method('queryOne')->willReturnOnConsecutiveCalls(
            null, // No existing dues
            ['id' => 1, 'donor_id' => null], // Member lookup
        );
        $this->db->method('insert')->willReturn(10);

        $result = $this->service->createDues(1, 1, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'amount_due' => 50.00,
        ]);

        $this->assertEquals(10, $result);
    }

    public function testCreateDuesThrowsForDuplicatePeriod(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1]); // Existing dues

        $this->expectException(ValidationException::class);

        $this->service->createDues(1, 1, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'amount_due' => 50.00,
        ]);
    }

    public function testCreateDuesSetsStatusToPaidWhenFullyPaid(): void
    {
        $this->db->method('queryOne')->willReturnOnConsecutiveCalls(
            null, // No existing dues
            ['id' => 1, 'donor_id' => null], // Member lookup
        );
        
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createDues(1, 1, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'amount_due' => 50.00,
            'amount_paid' => 50.00,
        ]);

        $this->assertEquals('paid', $insertedData['status']);
    }

    public function testCreateDuesSetsStatusToPartialWhenPartiallyPaid(): void
    {
        $this->db->method('queryOne')->willReturnOnConsecutiveCalls(
            null, // No existing dues
            ['id' => 1, 'donor_id' => null], // Member lookup
        );
        
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createDues(1, 1, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'amount_due' => 50.00,
            'amount_paid' => 25.00,
        ]);

        $this->assertEquals('partial', $insertedData['status']);
    }

    public function testCreateDuesChecksGiftAidEligibility(): void
    {
        $this->db->method('queryOne')->willReturnOnConsecutiveCalls(
            null, // No existing dues
            ['id' => 1, 'donor_id' => 5], // Member with linked donor
            ['id' => 10], // Declaration exists
        );
        
        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->service->createDues(1, 1, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'amount_due' => 50.00,
        ]);

        $this->assertTrue($insertedData['is_giftaid_eligible']);
    }

    // recordDuesPayment tests
    public function testRecordDuesPaymentUpdatesAmount(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
            'amount_paid' => 25.00,
            'amount_due' => 50.00,
        ]);

        $this->db->expects($this->once())->method('execute');

        $this->service->recordDuesPayment(1, 1, 25.00, 100);
    }

    public function testRecordDuesPaymentThrowsForNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->recordDuesPayment(999, 1, 25.00);
    }

    public function testRecordDuesPaymentSetsPaidStatus(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
            'amount_paid' => 25.00,
            'amount_due' => 50.00,
        ]);

        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->recordDuesPayment(1, 1, 25.00); // Total will be 50

        $this->assertEquals('paid', $executedParams[1]);
    }

    public function testRecordDuesPaymentSetsPendingWhenZero(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
            'amount_paid' => 10.00,
            'amount_due' => 50.00,
        ]);

        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->recordDuesPayment(1, 1, -10.00); // Total will be 0

        $this->assertEquals('pending', $executedParams[1]);
    }

    // waiveDues tests
    public function testWaiveDuesUpdatesStatus(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
        ]);
        
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains("status = 'waived'"),
                $this->anything()
            );

        $this->service->waiveDues(1, 1, 'Financial hardship');
    }

    public function testWaiveDuesWithoutReason(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
        ]);
        
        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->waiveDues(1, 1);

        $this->assertEquals('Waived', $executedParams[0]);
    }

    // undoDuesStatus tests
    public function testUndoDuesStatusRevertsToPending(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 1,
            'amount_paid' => 0,
            'amount_due' => 50.00,
        ]);

        $executedParams = null;
        $this->db->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executedParams) {
                $executedParams = $params;
                return 1;
            });

        $this->service->undoDuesStatus(1, 1);

        $this->assertEquals('pending', $executedParams[0]);
    }

    public function testUndoDuesStatusThrowsForNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->undoDuesStatus(999, 1);
    }

    // deleteDues tests
    public function testDeleteDuesDeletesUnpaidRecord(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 5,
            'amount_paid' => 0,
            'transaction_id' => null,
            'status' => 'waived',
        ]);

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM membership_dues'),
                [1, 5]
            );

        $this->service->deleteDues(1, 5);
    }

    public function testDeleteDuesThrowsWhenPaymentRecorded(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 5,
            'amount_paid' => 25.00,
            'transaction_id' => null,
            'status' => 'partial',
        ]);
        $this->db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);

        $this->service->deleteDues(1, 5);
    }

    public function testDeleteDuesThrowsWhenLinkedToTransaction(): void
    {
        $this->db->method('queryOne')->willReturn([
            'id' => 1,
            'charity_id' => 5,
            'amount_paid' => 0,
            'transaction_id' => 42,
            'status' => 'pending',
        ]);
        $this->db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);

        $this->service->deleteDues(1, 5);
    }

    public function testDeleteDuesThrowsForNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->deleteDues(999, 1);
    }

    // getStatistics tests
    public function testGetStatisticsReturnsAllFields(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['count' => 100], // Total members
                ['count' => 5], // Total trustees
                ['total_dues' => 80, 'paid_count' => 70, 'unpaid_count' => 10, 'total_due' => 4000, 'total_paid' => 3500]
            );

        $result = $this->service->getStatistics(1);

        $this->assertEquals(100, $result['total_members']);
        $this->assertEquals(5, $result['total_trustees']);
        $this->assertEquals(80, $result['dues_total']);
    }

    public function testGetStatisticsWithCharityUsesCalculatedPeriod(): void
    {
        $charity = ['membership_year_type' => 'calendar'];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['count' => 10],
                ['count' => 2],
                ['total_dues' => 5]
            );

        $result = $this->service->getStatistics(1, $charity);

        $this->assertEquals(date('Y'), $result['period_label']);
    }

    // searchUnlinkedMembers tests
    public function testSearchUnlinkedMembersReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);

        $result = $this->service->searchUnlinkedMembers(1, 'J');

        $this->assertCount(2, $result);
    }

    // linkToDonor tests
    public function testLinkToDonorExecutesUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('donor_id = ?'),
                [5, 1]
            );

        $this->service->linkToDonor(1, 5);
    }

    // unlinkFromDonor tests
    public function testUnlinkFromDonorExecutesUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('donor_id = NULL'),
                [1]
            );

        $this->service->unlinkFromDonor(1);
    }

    // getByDonorId tests
    public function testGetByDonorIdReturnsMember(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1, 'name' => 'John']);

        $result = $this->service->getByDonorId(5);

        $this->assertEquals('John', $result['name']);
    }

    public function testGetByDonorIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getByDonorId(999);

        $this->assertNull($result);
    }

    // calculateMembershipPeriod tests
    public function testCalculateMembershipPeriodCalendarYear(): void
    {
        $charity = ['membership_year_type' => 'calendar'];

        $result = $this->service->calculateMembershipPeriod($charity);

        $year = date('Y');
        $this->assertEquals("$year-01-01", $result['period_start']);
        $this->assertEquals("$year-12-31", $result['period_end']);
        $this->assertEquals($year, $result['period_label']);
    }

    public function testCalculateMembershipPeriodAnniversaryWithoutJoinDate(): void
    {
        $charity = ['membership_year_type' => 'anniversary'];

        $result = $this->service->calculateMembershipPeriod($charity);

        // Falls back to calendar year
        $year = date('Y');
        $this->assertEquals("$year-01-01", $result['period_start']);
    }

    public function testCalculateMembershipPeriodAnniversaryAfterAnniversary(): void
    {
        $charity = ['membership_year_type' => 'anniversary'];
        // Set join date to earlier this year (already passed) - June 1st
        $joinDate = date('Y') . '-06-01';

        $result = $this->service->calculateMembershipPeriod($charity, $joinDate);

        // Period should span two years
        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_label', $result);
        // The label should contain a hyphen for cross-year periods
        $this->assertStringContainsString('-', $result['period_label']);
    }

    public function testCalculateMembershipPeriodFinancialYear(): void
    {
        $charity = [
            'membership_year_type' => 'financial',
            'year_end_month' => 3,
            'year_end_day' => 31,
        ];

        $result = $this->service->calculateMembershipPeriod($charity);

        // Should return financial year period
        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertArrayHasKey('period_label', $result);
    }

    public function testCalculateMembershipPeriodDefaultsToFinancial(): void
    {
        $charity = []; // No type specified

        $result = $this->service->calculateMembershipPeriod($charity);

        // Defaults to financial year with March 31 end
        $this->assertArrayHasKey('period_start', $result);
    }

    // validateMember tests (via create/update)
    public function testCreateValidatesInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, ['name' => 'Test', 'email' => 'invalid-email']);
    }

    // endTrusteeRole edge case - role not found
    public function testEndTrusteeRoleHandlesMissingRole(): void
    {
        $this->db->method('queryOne')->willReturn(null); // Role not found

        // Should still execute update but not update member
        $this->db->expects($this->once())->method('execute');

        $this->service->endTrusteeRole(999, '2025-12-31');
    }
}
