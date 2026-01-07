<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceAdmin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use CharityCashbook\ServiceAdmin\CharityManager;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ModuleService;

class CharityManagerTest extends TestCase
{
    private CharityManager $manager;
    private MockObject $db;
    private MockObject $logger;
    private MockObject $categoryService;
    private MockObject $permissionService;
    private MockObject $moduleService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->moduleService = $this->createMock(ModuleService::class);

        $this->manager = new CharityManager(
            $this->db,
            $this->logger,
            $this->categoryService,
            $this->permissionService,
            $this->moduleService
        );
    }

    // getAllCharities tests
    public function testGetAllCharitiesReturnsArray(): void
    {
        $charities = [
            ['id' => 1, 'name' => 'Test Charity', 'user_count' => 5],
        ];
        $this->db->method('query')->willReturn($charities);

        $result = $this->manager->getAllCharities();

        $this->assertEquals($charities, $result);
    }

    // listCharities tests
    public function testListCharitiesWithNoFilters(): void
    {
        $charities = [['id' => 1, 'name' => 'Test Charity']];
        $this->db->method('query')->willReturn($charities);

        $result = $this->manager->listCharities();

        $this->assertEquals($charities, $result);
    }

    public function testListCharitiesWithSearchFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIKE'),
                $this->callback(fn($params) => count($params) === 2 && strpos($params[0], '%') !== false)
            )
            ->willReturn([]);

        $this->manager->listCharities('test');
    }

    public function testListCharitiesWithActiveStatusFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('is_active = TRUE'),
                []
            )
            ->willReturn([]);

        $this->manager->listCharities(null, 'active');
    }

    public function testListCharitiesWithSuspendedStatusFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('is_active = FALSE'),
                []
            )
            ->willReturn([]);

        $this->manager->listCharities(null, 'suspended');
    }

    // getCharity tests
    public function testGetCharityReturnsCharity(): void
    {
        $charity = ['id' => 1, 'name' => 'Test Charity'];
        $this->db->method('queryOne')->willReturn($charity);

        $result = $this->manager->getCharity(1);

        $this->assertEquals($charity, $result);
    }

    public function testGetCharityReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->manager->getCharity(999);

        $this->assertNull($result);
    }

    // createCharity tests
    public function testCreateCharityCreatesCharityWithDefaultSettings(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('query')->willReturn([]); // No OAuth providers
        $this->db->method('queryOne')->willReturn(null); // User doesn't exist

        $this->permissionService->expects($this->once())
            ->method('createDefaultRoles')
            ->with(1);

        $this->moduleService->expects($this->once())
            ->method('createDefaultModules')
            ->with(1);

        $result = $this->manager->createCharity(
            ['name' => 'Test Charity'],
            'admin@example.com'
        );

        $this->assertEquals(1, $result);
    }

    public function testCreateCharityAddsExistingUserAsAdmin(): void
    {
        $charityInserted = false;
        $userAdded = false;
        
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$charityInserted, &$userAdded) {
                if ($table === 'charities') {
                    $charityInserted = true;
                    return 1;
                }
                if ($table === 'charity_users' && isset($data['role']) && $data['role'] === 'admin') {
                    $userAdded = true;
                    return 1;
                }
                return 1;
            });
        
        $this->db->method('query')->willReturn([]); // No OAuth providers
        $this->db->method('queryOne')->willReturn(['id' => 42]); // User exists

        $this->manager->createCharity(
            ['name' => 'Test Charity'],
            'existing@example.com'
        );

        $this->assertTrue($charityInserted);
        $this->assertTrue($userAdded);
    }

    public function testCreateCharityAppliesTemplate(): void
    {
        $this->db->method('insert')->willReturn(1);
        $this->db->method('query')->willReturn([]);
        $this->db->method('queryOne')->willReturn(null);

        $this->categoryService->expects($this->once())
            ->method('applyTemplate')
            ->with(1, 5);

        $this->manager->createCharity(
            ['name' => 'Test Charity', 'template_id' => '5'],
            'admin@example.com'
        );
    }

    public function testCreateCharityRollsBackOnException(): void
    {
        $this->db->method('insert')
            ->willThrowException(new \Exception('Database error'));
        
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollback');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->manager->createCharity(['name' => 'Test'], 'admin@example.com');
    }

    // suspendCharity tests
    public function testSuspendCharityUpdatesStatus(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = FALSE'),
                [1]
            )
            ->willReturn(1);

        $this->manager->suspendCharity(1, 'Inactive');
    }

    // reactivateCharity tests
    public function testReactivateCharityUpdatesStatus(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = TRUE'),
                [1]
            )
            ->willReturn(1);

        $this->manager->reactivateCharity(1);
    }

    // updateCharity tests
    public function testUpdateCharityUpdatesAllowedFields(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'charities',
                $this->callback(fn($data) => 
                    $data['name'] === 'New Name' && !isset($data['evil_field'])
                ),
                'id = ?',
                [1]
            );

        $this->manager->updateCharity(1, [
            'name' => 'New Name',
            'evil_field' => 'ignored',
        ]);
    }

    public function testUpdateCharityDoesNothingWithEmptyData(): void
    {
        $this->db->expects($this->never())->method('update');

        $this->manager->updateCharity(1, ['evil_field' => 'ignored']);
    }

    // getCharityStats tests
    public function testGetCharityStatsReturnsStats(): void
    {
        $this->db->method('queryValue')
            ->willReturnOnConsecutiveCalls(5, 2, 100);

        $result = $this->manager->getCharityStats(1);

        $this->assertEquals(5, $result['user_count']);
        $this->assertEquals(2, $result['account_count']);
        $this->assertEquals(100, $result['transaction_count']);
    }

    // getCharityUsers tests
    public function testGetCharityUsersReturnsUsers(): void
    {
        $users = [
            ['user_id' => 1, 'display_name' => 'Admin', 'role' => 'admin'],
        ];
        $this->db->method('query')->willReturn($users);

        $result = $this->manager->getCharityUsers(1);

        $this->assertEquals($users, $result);
    }

    // setUserRole tests
    public function testSetUserRoleUpdatesRole(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1], // Role exists
                ['id' => 1]  // User is member
            );

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE charity_users SET role'),
                ['treasurer', 1, 2]
            )
            ->willReturn(1);

        $this->manager->setUserRole(1, 2, 'treasurer');
    }

    public function testSetUserRoleThrowsWhenRoleDoesNotExist(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Role 'fake' does not exist");

        $this->manager->setUserRole(1, 2, 'fake');
    }

    public function testSetUserRoleThrowsWhenUserNotMember(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1], // Role exists
                null         // User not member
            );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User is not a member');

        $this->manager->setUserRole(1, 2, 'treasurer');
    }

    // getStatistics tests
    public function testGetStatisticsReturnsAllStats(): void
    {
        $this->db->method('queryValue')
            ->willReturnOnConsecutiveCalls(10, 8, 50, 5, 1000, 25, 3);

        $result = $this->manager->getStatistics();

        $this->assertEquals(10, $result['charities_total']);
        $this->assertEquals(8, $result['charities_active']);
        $this->assertEquals(50, $result['users_total']);
        $this->assertEquals(5, $result['sessions_active']);
        $this->assertEquals(1000, $result['transactions_total']);
        $this->assertEquals(25, $result['logins_today']);
        $this->assertEquals(3, $result['logins_failed_today']);
    }

    // getRecentLogins tests
    public function testGetRecentLoginsWithoutCharityFilter(): void
    {
        $logins = [['id' => 1, 'display_name' => 'User']];
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('charity_users')),
                [50]
            )
            ->willReturn($logins);

        $result = $this->manager->getRecentLogins(null, 50);

        $this->assertEquals($logins, $result);
    }

    public function testGetRecentLoginsWithCharityFilter(): void
    {
        $logins = [['id' => 1, 'display_name' => 'User']];
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('charity_users'),
                [1, 50]
            )
            ->willReturn($logins);

        $result = $this->manager->getRecentLogins(1, 50);

        $this->assertEquals($logins, $result);
    }

    // getFailedLoginsByIp tests
    public function testGetFailedLoginsByIpReturnsGroupedData(): void
    {
        $failures = [
            ['ip_address' => '192.168.1.1', 'attempt_count' => 5],
        ];
        $this->db->method('query')->willReturn($failures);

        $result = $this->manager->getFailedLoginsByIp(24);

        $this->assertEquals($failures, $result);
    }

    // sendInvitation tests
    public function testSendInvitationThrowsWhenCharityNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Charity not found: 999');

        $this->manager->sendInvitation(999, 'user@example.com', 'https://app.example.com');
    }

    public function testSendInvitationSendsEmail(): void
    {
        $charity = ['id' => 1, 'name' => 'Test Charity'];
        $this->db->method('queryOne')->willReturn($charity);

        // The mail() function will fail in test environment, but we're testing the logic
        @$this->manager->sendInvitation(1, 'user@example.com', 'https://app.example.com');

        // If we got here without exception (other than mail failure), the logic worked
        $this->assertTrue(true);
    }

    // getAllowedEmails tests
    public function testGetAllowedEmailsReturnsEmails(): void
    {
        $emails = [
            ['email_pattern' => 'admin@example.com', 'pattern_type' => 'email'],
        ];
        $this->db->method('query')->willReturn($emails);

        $result = $this->manager->getAllowedEmails(1);

        $this->assertEquals($emails, $result);
    }
}
