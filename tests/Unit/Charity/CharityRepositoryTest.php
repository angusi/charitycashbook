<?php

declare(strict_types=1);

namespace Tests\Unit\Charity;

use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Core\DatabaseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CharityRepositoryTest extends TestCase
{
    private CharityRepository $repository;
    private MockObject&DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->repository = new CharityRepository($this->db);
    }

    // ========================================
    // findById tests
    // ========================================

    public function testFindByIdReturnsCharityWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'name' => 'Test Charity',
            'registration_number' => '12345',
            'session_timeout_hours' => 24,
            'session_timeout_minutes' => 1440,
        ];

        $this->db->method('queryOne')
            ->with($this->stringContains('SELECT'), [1])
            ->willReturn($expected);

        $result = $this->repository->findById(1);

        $this->assertSame($expected, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // ========================================
    // getUserMembership tests
    // ========================================

    public function testGetUserMembershipReturnsDataWhenExists(): void
    {
        $expected = ['charity_id' => 1, 'user_id' => 42, 'role' => 'admin', 'is_active' => true];

        $this->db->method('queryOne')
            ->with($this->stringContains('charity_users'), [1, 42])
            ->willReturn($expected);

        $result = $this->repository->getUserMembership(1, 42);

        $this->assertSame($expected, $result);
    }

    public function testGetUserMembershipReturnsNullWhenNotMember(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->repository->getUserMembership(1, 999);

        $this->assertNull($result);
    }

    // ========================================
    // updateSettings tests
    // ========================================

    public function testUpdateSettingsCallsUpdate(): void
    {
        $data = ['name' => 'Updated Name', 'session_timeout_hours' => 48];

        $this->db->expects($this->once())
            ->method('update')
            ->with('charities', $data, 'id = ?', [1]);

        $this->repository->updateSettings(1, $data);
    }

    public function testUpdateIsAliasForUpdateSettings(): void
    {
        $data = ['name' => 'Updated Name'];

        $this->db->expects($this->once())
            ->method('update')
            ->with('charities', $data, 'id = ?', [42]);

        $this->repository->update(42, $data);
    }

    // ========================================
    // getAllowedEmails tests
    // ========================================

    public function testGetAllowedEmailsReturnsPatterns(): void
    {
        $expected = [
            ['id' => 1, 'email_pattern' => '@example.com', 'pattern_type' => 'domain'],
            ['id' => 2, 'email_pattern' => 'user@test.com', 'pattern_type' => 'exact'],
        ];

        $this->db->method('query')->willReturn($expected);

        $result = $this->repository->getAllowedEmails(1);

        $this->assertSame($expected, $result);
    }

    public function testGetAllowedEmailsReturnsEmptyWhenNoPatterns(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->repository->getAllowedEmails(1);

        $this->assertSame([], $result);
    }

    // ========================================
    // addAllowedEmail tests
    // ========================================

    public function testAddAllowedEmailInsertsPattern(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('charity_allowed_emails', [
                'charity_id' => 1,
                'email_pattern' => '@example.com',
                'pattern_type' => 'domain',
            ])
            ->willReturn(42);

        $result = $this->repository->addAllowedEmail(1, '@example.com', 'domain');

        $this->assertSame(42, $result);
    }

    // ========================================
    // removeAllowedEmail tests
    // ========================================

    public function testRemoveAllowedEmailDeletesPattern(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM charity_allowed_emails'),
                [5, 1]
            );

        $this->repository->removeAllowedEmail(1, 5);
    }

    // ========================================
    // getAllowedProviders tests
    // ========================================

    public function testGetAllowedProvidersReturnsProviders(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Google', 'provider_type' => 'google'],
            ['id' => 2, 'name' => 'Microsoft', 'provider_type' => 'azure'],
        ];

        $this->db->method('query')
            ->with($this->stringContains('JOIN charity_oauth_providers'), [1])
            ->willReturn($expected);

        $result = $this->repository->getAllowedProviders(1);

        $this->assertSame($expected, $result);
    }

    // ========================================
    // setAllowedProviders tests
    // ========================================

    public function testSetAllowedProvidersRemovesExistingAndInsertsNew(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM charity_oauth_providers'),
                [1]
            );

        $this->db->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function ($table, $data) {
                $this->assertSame('charity_oauth_providers', $table);
                $this->assertSame(1, $data['charity_id']);
                return 1;
            });

        $this->repository->setAllowedProviders(1, [10, 20]);
    }

    public function testSetAllowedProvidersHandlesEmptyArray(): void
    {
        $this->db->expects($this->once())->method('execute');
        $this->db->expects($this->never())->method('insert');

        $this->repository->setAllowedProviders(1, []);
    }

    // ========================================
    // getUsers tests
    // ========================================

    public function testGetUsersReturnsCharityUsers(): void
    {
        $expected = [
            ['id' => 1, 'display_name' => 'John', 'role' => 'admin'],
            ['id' => 2, 'display_name' => 'Jane', 'role' => 'viewer'],
        ];

        $this->db->method('query')->willReturn($expected);

        $result = $this->repository->getUsers(1);

        $this->assertSame($expected, $result);
    }

    // ========================================
    // updateUserRole tests
    // ========================================

    public function testUpdateUserRoleExecutesCorrectQuery(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE charity_users SET role'),
                ['treasurer', 1, 42]
            );

        $this->repository->updateUserRole(1, 42, 'treasurer');
    }

    // ========================================
    // deactivateUser tests
    // ========================================

    public function testDeactivateUserSetsIsActiveFalse(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = FALSE'),
                [1, 42]
            );

        $this->repository->deactivateUser(1, 42);
    }

    // ========================================
    // activateUser tests
    // ========================================

    public function testActivateUserSetsIsActiveTrue(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('is_active = TRUE'),
                [1, 42]
            );

        $this->repository->activateUser(1, 42);
    }

    // ========================================
    // addUser tests
    // ========================================

    public function testAddUserInsertsNewMembership(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('charity_users', [
                'charity_id' => 1,
                'user_id' => 42,
                'role' => 'viewer',
                'is_active' => true,
            ]);

        $this->repository->addUser(1, 42, 'viewer');
    }

    // ========================================
    // updateLastAuthenticated tests
    // ========================================

    public function testUpdateLastAuthenticatedWithoutDisplayName(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('last_authenticated_at = NOW()'),
                    $this->logicalNot($this->stringContains('display_name'))
                ),
                [1, 42]
            );

        $this->repository->updateLastAuthenticated(1, 42);
    }

    public function testUpdateLastAuthenticatedWithDisplayName(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('last_authenticated_at = NOW()'),
                    $this->stringContains('display_name')
                ),
                ['John Doe', 1, 42]
            );

        $this->repository->updateLastAuthenticated(1, 42, 'John Doe');
    }

    // ========================================
    // updateUserDisplayName tests
    // ========================================

    public function testUpdateUserDisplayNameExecutesCorrectQuery(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('display_name'),
                ['New Name', 1, 42]
            );

        $this->repository->updateUserDisplayName(1, 42, 'New Name');
    }

    // ========================================
    // getUserDisplayName tests
    // ========================================

    public function testGetUserDisplayNameReturnsCoalescedName(): void
    {
        $this->db->method('queryValue')
            ->with(
                $this->stringContains('COALESCE(cu.display_name, u.display_name)'),
                [1, 42]
            )
            ->willReturn('John Doe');

        $result = $this->repository->getUserDisplayName(1, 42);

        $this->assertSame('John Doe', $result);
    }

    public function testGetUserDisplayNameReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryValue')->willReturn(null);

        $result = $this->repository->getUserDisplayName(1, 999);

        $this->assertNull($result);
    }
}
