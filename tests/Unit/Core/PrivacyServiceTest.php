<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\PrivacyService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PrivacyServiceTest extends TestCase
{
    private PrivacyService $service;
    private MockObject $db;
    private MockObject $logger;
    private string $tempPath;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->tempPath = sys_get_temp_dir();

        $this->service = new PrivacyService(
            $this->db,
            $this->logger,
            $this->tempPath
        );
    }

    // ===========================================
    // findDonorData() tests
    // ===========================================

    public function testFindDonorDataReturnsEmptyWhenDonorNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->findDonorData(1, 1);

        $this->assertNull($result['donor']);
        $this->assertEquals([], $result['declarations']);
        $this->assertEquals([], $result['donations']);
    }

    public function testFindDonorDataReturnsAllRelatedData(): void
    {
        $donor = ['id' => 1, 'name' => 'John Smith', 'charity_id' => 1];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                $donor, // donor record
                null // linked member
            );

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1]], // declarations
                [['id' => 2]], // donations
                [], // transactions_mentioned
                [], // access_log
                [] // audit_log
            );

        $result = $this->service->findDonorData(1, 1);

        $this->assertEquals($donor, $result['donor']);
        $this->assertIsArray($result['declarations']);
        $this->assertIsArray($result['donations']);
    }

    public function testFindDonorDataSearchesTransactionDescriptions(): void
    {
        $donor = ['id' => 1, 'name' => 'John Smith', 'charity_id' => 1];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($donor, null);

        $this->db->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'description LIKE') !== false) {
                    // Verify donor name is searched in transaction descriptions
                    $this->assertStringContainsString('John Smith', $params[1]);
                }
                return [];
            });

        $this->service->findDonorData(1, 1);
    }

    // ===========================================
    // findMemberData() tests
    // ===========================================

    public function testFindMemberDataReturnsEmptyWhenMemberNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->findMemberData(1, 1);

        $this->assertNull($result['member']);
        $this->assertEquals([], $result['trustee_history']);
    }

    public function testFindMemberDataReturnsAllRelatedData(): void
    {
        $member = ['id' => 1, 'name' => 'Jane Doe', 'charity_id' => 1, 'donor_id' => null];

        $this->db->method('queryOne')
            ->willReturn($member);

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1]], // trustee_history
                [], // membership_dues
                [] // audit_log
            );

        $result = $this->service->findMemberData(1, 1);

        $this->assertEquals($member, $result['member']);
        $this->assertIsArray($result['trustee_history']);
    }

    public function testFindMemberDataIncludesLinkedDonor(): void
    {
        $member = ['id' => 1, 'name' => 'Jane Doe', 'charity_id' => 1, 'donor_id' => 5];
        $linkedDonor = ['id' => 5, 'name' => 'Jane Doe'];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                $member,
                $linkedDonor
            );

        $this->db->method('query')->willReturn([]);

        $result = $this->service->findMemberData(1, 1);

        $this->assertEquals($linkedDonor, $result['linked_donor']);
    }

    // ===========================================
    // exportDonorData() tests
    // ===========================================

    public function testExportDonorDataThrowsWhenDonorNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Donor not found');

        $this->service->exportDonorData(999, 1);
    }

    /**
     * @requires extension zip
     */
    public function testExportDonorDataCreatesZipFile(): void
    {
        $donor = ['id' => 1, 'name' => 'John Smith', 'charity_id' => 1];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($donor, null);

        $this->db->method('query')->willReturn([]);

        $result = $this->service->exportDonorData(1, 1);

        $this->assertFileExists($result);
        $this->assertStringEndsWith('.zip', $result);

        // Cleanup
        @unlink($result);
    }

    // ===========================================
    // exportMemberData() tests
    // ===========================================

    public function testExportMemberDataThrowsWhenMemberNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Member not found');

        $this->service->exportMemberData(999, 1);
    }

    /**
     * @requires extension zip
     */
    public function testExportMemberDataCreatesZipFile(): void
    {
        $member = ['id' => 1, 'name' => 'Jane Doe', 'charity_id' => 1, 'donor_id' => null];

        $this->db->method('queryOne')->willReturn($member);
        $this->db->method('query')->willReturn([]);

        $result = $this->service->exportMemberData(1, 1);

        $this->assertFileExists($result);
        $this->assertStringEndsWith('.zip', $result);

        // Cleanup
        @unlink($result);
    }

    // ===========================================
    // canDeleteDonorData() tests
    // ===========================================

    public function testCanDeleteDonorDataReturnsTrueWhenNoRetention(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_claim' => null], // No Gift Aid claims
                ['last_donation' => null] // No donations
            );

        $result = $this->service->canDeleteDonorData(1, 1);

        $this->assertTrue($result['can_delete']);
        $this->assertEquals([], $result['reasons']);
    }

    public function testCanDeleteDonorDataReturnsFalseForRecentGiftAidClaim(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_claim' => date('Y-m-d')], // Recent claim
                ['last_donation' => null]
            );

        $result = $this->service->canDeleteDonorData(1, 1);

        $this->assertFalse($result['can_delete']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertNotNull($result['earliest_deletion_date']);
    }

    public function testCanDeleteDonorDataReturnsFalseForRecentDonation(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_claim' => null],
                ['last_donation' => date('Y-m-d')] // Recent donation
            );

        $result = $this->service->canDeleteDonorData(1, 1);

        $this->assertFalse($result['can_delete']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testCanDeleteDonorDataReturnsTrueAfterRetentionPeriod(): void
    {
        $oldDate = date('Y-m-d', strtotime('-8 years'));

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_claim' => $oldDate],
                ['last_donation' => $oldDate]
            );

        $result = $this->service->canDeleteDonorData(1, 1);

        $this->assertTrue($result['can_delete']);
    }

    // ===========================================
    // canDeleteMemberData() tests
    // ===========================================

    public function testCanDeleteMemberDataReturnsTrueWhenNoRetention(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_date' => null], // No dues
                ['donor_id' => null] // No linked donor
            );

        $result = $this->service->canDeleteMemberData(1, 1);

        $this->assertTrue($result['can_delete']);
    }

    public function testCanDeleteMemberDataReturnsFalseForRecentDues(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_date' => date('Y-m-d')], // Recent dues
                ['donor_id' => null]
            );

        $result = $this->service->canDeleteMemberData(1, 1);

        $this->assertFalse($result['can_delete']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testCanDeleteMemberDataChecksLinkedDonor(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['last_date' => null], // No dues
                ['donor_id' => 5], // Has linked donor
                ['last_claim' => date('Y-m-d')], // Donor has recent claim
                ['last_donation' => null]
            );

        $result = $this->service->canDeleteMemberData(1, 1);

        $this->assertFalse($result['can_delete']);
        $this->assertContains('Linked donor record has retention requirements', $result['reasons']);
    }

    // ===========================================
    // anonymiseDonorData() tests
    // ===========================================

    public function testAnonymiseDonorDataUpdatesDonorRecord(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE donors SET'),
                $this->callback(function ($params) {
                    return strpos($params[0], 'Anonymised Donor #1') !== false;
                })
            );

        $this->db->method('queryOne')->willReturn(['name' => 'John Smith']);
        $this->db->method('insert')->willReturn(1);

        $result = $this->service->anonymiseDonorData(1, 1, 1);

        $this->assertContains('Donor record anonymised', $result);
    }

    public function testAnonymiseDonorDataCreatesAuditLog(): void
    {
        $this->db->method('queryOne')->willReturn(['name' => 'John']);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('audit_log', $this->callback(function ($data) {
                return $data['action'] === 'donor_anonymised'
                    && $data['entity_type'] === 'donor';
            }))
            ->willReturn(1);

        $this->service->anonymiseDonorData(1, 1, 1);
    }

    // ===========================================
    // anonymiseMemberData() tests
    // ===========================================

    public function testAnonymiseMemberDataUpdatesMemberRecord(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'UPDATE members SET') !== false) {
                    $this->assertStringContainsString('Anonymised Member #1', $params[0]);
                }
                return 1;
            });

        $this->db->method('insert')->willReturn(1);

        $result = $this->service->anonymiseMemberData(1, 1, 1);

        $this->assertContains('Member record anonymised', $result);
        $this->assertContains('Trustee history notes cleared', $result);
    }

    // ===========================================
    // findUserData() tests
    // ===========================================

    public function testFindUserDataReturnsEmptyWhenUserNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->findUserData(1);

        $this->assertNull($result['user']);
        $this->assertEquals([], $result['identities']);
    }

    public function testFindUserDataReturnsAllRelatedData(): void
    {
        $user = ['id' => 1, 'display_name' => 'Test User', 'primary_email' => 'test@example.com'];

        $this->db->method('queryOne')->willReturn($user);

        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1]], // identities
                [['id' => 2]], // charity_access
                [], // sessions
                [], // login_history
                [], // audit_log_created
                [] // donor_access_log
            );

        $result = $this->service->findUserData(1);

        $this->assertEquals($user, $result['user']);
        $this->assertIsArray($result['identities']);
        $this->assertIsArray($result['charity_access']);
    }

    // ===========================================
    // exportUserData() tests
    // ===========================================

    public function testExportUserDataThrowsWhenUserNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->service->exportUserData(999);
    }

    /**
     * @requires extension zip
     */
    public function testExportUserDataCreatesZipFile(): void
    {
        $user = ['id' => 1, 'display_name' => 'Test', 'primary_email' => 'test@example.com'];

        $this->db->method('queryOne')->willReturn($user);
        $this->db->method('query')->willReturn([]);

        $result = $this->service->exportUserData(1);

        $this->assertFileExists($result);
        $this->assertStringEndsWith('.zip', $result);

        // Cleanup
        @unlink($result);
    }

    // ===========================================
    // anonymiseUserData() tests
    // ===========================================

    public function testAnonymiseUserDataUpdatesAndDeletes(): void
    {
        $expectedCalls = 0;

        $this->db->expects($this->exactly(6))
            ->method('execute')
            ->willReturn(1);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('audit_log', $this->anything())
            ->willReturn(1);

        $result = $this->service->anonymiseUserData(1, 99);

        $this->assertContains('User account anonymised and deactivated', $result);
        $this->assertContains('OAuth identities removed', $result);
        $this->assertContains('Sessions deleted', $result);
        $this->assertContains('Charity access removed', $result);
        $this->assertContains('Audit log entries anonymised', $result);
        $this->assertContains('Donor access log entries anonymised', $result);
    }

    // ===========================================
    // searchAllCharities() tests
    // ===========================================

    public function testSearchAllCharitiesSearchesDonorsMembersUsers(): void
    {
        $this->db->expects($this->exactly(3))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'name' => 'John']], // donors
                [['id' => 2, 'name' => 'Jane']], // members
                [['id' => 3, 'display_name' => 'Admin']] // users
            );

        $result = $this->service->searchAllCharities('test@example.com');

        $this->assertCount(1, $result['donors']);
        $this->assertCount(1, $result['members']);
        $this->assertCount(1, $result['users']);
    }

    public function testSearchAllCharitiesUsesWildcards(): void
    {
        $this->db->expects($this->exactly(3))
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function ($params) {
                    return strpos($params[0], '%') !== false;
                })
            )
            ->willReturn([]);

        $this->service->searchAllCharities('test');
    }
}
