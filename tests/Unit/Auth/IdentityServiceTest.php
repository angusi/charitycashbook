<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Auth;

use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\AuthenticationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class IdentityServiceTest extends TestCase
{
    private IdentityService $service;
    private MockObject $db;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new IdentityService($this->db, $this->logger);
    }

    // ===========================================
    // resolveIdentity() tests
    // ===========================================

    public function testResolveIdentityReturnsExistingUser(): void
    {
        $identity = ['id' => 1, 'user_id' => 5, 'oauth_email' => 'test@example.com'];
        $user = ['id' => 5, 'display_name' => 'Test User', 'is_active' => true];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($identity, $user);

        $result = $this->service->resolveIdentity(1, 'subject123', 'test@example.com');

        $this->assertEquals('existing_user', $result['status']);
        $this->assertEquals($user, $result['user']);
        $this->assertEquals($identity, $result['identity']);
    }

    public function testResolveIdentityThrowsForInactiveUser(): void
    {
        $identity = ['id' => 1, 'user_id' => 5, 'oauth_email' => 'test@example.com'];
        $user = ['id' => 5, 'display_name' => 'Test User', 'is_active' => false];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($identity, $user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User account is inactive');

        $this->service->resolveIdentity(1, 'subject123', 'test@example.com');
    }

    public function testResolveIdentityLinksExistingUserByEmail(): void
    {
        $user = ['id' => 5, 'display_name' => 'Test User', 'is_active' => true, 'primary_email' => 'test@example.com'];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                null, // No identity found
                $user // User found by email
            );

        $this->db->expects($this->once())
            ->method('insert')
            ->with('user_identities', $this->callback(function ($data) {
                return $data['user_id'] === 5
                    && $data['oauth_provider_id'] === 1
                    && $data['oauth_subject'] === 'subject123';
            }));

        $result = $this->service->resolveIdentity(1, 'subject123', 'test@example.com');

        $this->assertEquals('existing_user', $result['status']);
        $this->assertEquals($user, $result['user']);
    }

    public function testResolveIdentityThrowsForInactiveUserByEmail(): void
    {
        $user = ['id' => 5, 'is_active' => false];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                null, // No identity
                $user // User found by email but inactive
            );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User account is inactive');

        $this->service->resolveIdentity(1, 'subject123', 'test@example.com');
    }

    public function testResolveIdentityThrowsForUnauthorisedEmail(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $this->db->method('query')
            ->willReturn([]); // No charities authorise this email

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Email address not authorised for any charity');

        $this->service->resolveIdentity(1, 'subject123', 'unknown@example.com');
    }

    public function testResolveIdentityReturnsNewIdentityStatus(): void
    {
        $charities = [
            ['id' => 1, 'name' => 'Test Charity', 'allow_account_linking' => true],
        ];

        $this->db->method('queryOne')
            ->willReturn(null);

        $this->db->method('query')
            ->willReturn($charities);

        $result = $this->service->resolveIdentity(1, 'subject123', 'new@example.com');

        $this->assertEquals('new_identity', $result['status']);
        $this->assertEquals('new@example.com', $result['email']);
        $this->assertEquals('subject123', $result['oauth_subject']);
        $this->assertEquals(1, $result['provider_id']);
        $this->assertEquals($charities, $result['charities']);
        $this->assertTrue($result['can_link']);
    }

    public function testResolveIdentityDetectsLinkingNotAllowed(): void
    {
        $charities = [
            ['id' => 1, 'name' => 'Test Charity', 'allow_account_linking' => false],
        ];

        $this->db->method('queryOne')
            ->willReturn(null);

        $this->db->method('query')
            ->willReturn($charities);

        $result = $this->service->resolveIdentity(1, 'subject123', 'new@example.com');

        $this->assertFalse($result['can_link']);
    }

    // ===========================================
    // createUser() tests
    // ===========================================

    public function testCreateUserReturnsUser(): void
    {
        $this->db->method('insert')
            ->willReturnOnConsecutiveCalls(10, 20, 30); // user_id, identity_id, charity_user_id

        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'name' => 'Test Charity'],
            ]);

        $this->db->method('queryValue')
            ->willReturn(0); // No existing users in charity

        $expectedUser = ['id' => 10, 'display_name' => 'Test User'];
        $this->db->method('queryOne')
            ->willReturn($expectedUser);

        $result = $this->service->createUser(
            'Test User',
            'test@example.com',
            1,
            'subject123',
            'test@example.com'
        );

        $this->assertEquals($expectedUser, $result);
    }

    public function testCreateUserFirstUserBecomesAdmin(): void
    {
        $insertedData = [];

        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData[$table] = $data;
                return 1;
            });

        $this->db->method('query')
            ->willReturn([['id' => 1, 'name' => 'Test Charity']]);

        $this->db->method('queryValue')
            ->willReturn(0); // No existing users

        $this->db->method('queryOne')
            ->willReturn(['id' => 1]);

        $this->service->createUser('Test', 'test@example.com', 1, 'sub', 'test@example.com');

        $this->assertEquals('admin', $insertedData['charity_users']['role']);
    }

    public function testCreateUserSubsequentUserBecomesStandard(): void
    {
        $insertedData = [];

        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData[$table] = $data;
                return 1;
            });

        $this->db->method('query')
            ->willReturn([['id' => 1, 'name' => 'Test Charity']]);

        $this->db->method('queryValue')
            ->willReturn(1); // One existing user

        $this->db->method('queryOne')
            ->willReturn(['id' => 1]);

        $this->service->createUser('Test', 'test@example.com', 1, 'sub', 'test@example.com');

        $this->assertEquals('standard', $insertedData['charity_users']['role']);
    }

    public function testCreateUserRollsBackOnException(): void
    {
        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('insert')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);

        $this->service->createUser('Test', 'test@example.com', 1, 'sub', 'test@example.com');
    }

    // ===========================================
    // findIdentity() tests
    // ===========================================

    public function testFindIdentityReturnsIdentity(): void
    {
        $expected = ['id' => 1, 'user_id' => 5, 'oauth_email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('oauth_provider_id = ? AND oauth_subject = ?'),
                [1, 'subject123']
            )
            ->willReturn($expected);

        $result = $this->service->findIdentity(1, 'subject123');
        $this->assertEquals($expected, $result);
    }

    public function testFindIdentityReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->findIdentity(1, 'nonexistent');
        $this->assertNull($result);
    }

    // ===========================================
    // findIdentityForUser() tests
    // ===========================================

    public function testFindIdentityForUserReturnsIdentity(): void
    {
        $expected = ['id' => 1, 'user_id' => 5];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($expected);

        $result = $this->service->findIdentityForUser(5, 1, 'test@example.com');
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getIdentitiesForUser() tests
    // ===========================================

    public function testGetIdentitiesForUserReturnsArray(): void
    {
        $expected = [
            ['id' => 1, 'oauth_email' => 'test@google.com', 'provider_name' => 'Google'],
            ['id' => 2, 'oauth_email' => 'test@microsoft.com', 'provider_name' => 'Microsoft'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getIdentitiesForUser(1);
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getUser() tests
    // ===========================================

    public function testGetUserReturnsUser(): void
    {
        $expected = ['id' => 1, 'display_name' => 'Test User'];

        $this->db->method('queryOne')
            ->willReturn($expected);

        $result = $this->service->getUser(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetUserReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getUser(999);
        $this->assertNull($result);
    }

    // ===========================================
    // getUserByEmail() tests
    // ===========================================

    public function testGetUserByEmailReturnsUser(): void
    {
        $expected = ['id' => 1, 'primary_email' => 'test@example.com'];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('primary_email = ?'),
                ['test@example.com']
            )
            ->willReturn($expected);

        $result = $this->service->getUserByEmail('test@example.com');
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // findCharitiesForEmail() tests
    // ===========================================

    public function testFindCharitiesForEmailQueriesEmailAndDomain(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                ['user@example.com', '@example.com']
            )
            ->willReturn([]);

        $this->service->findCharitiesForEmail('user@example.com');
    }

    public function testFindCharitiesForEmailReturnsCharities(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Test Charity'],
        ];

        $this->db->method('query')
            ->willReturn($expected);

        $result = $this->service->findCharitiesForEmail('user@example.com');
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // isEmailAuthorisedForCharity() tests
    // ===========================================

    public function testIsEmailAuthorisedForCharityReturnsTrue(): void
    {
        $this->db->method('queryValue')
            ->willReturn(1);

        $result = $this->service->isEmailAuthorisedForCharity('user@example.com', 1);
        $this->assertTrue($result);
    }

    public function testIsEmailAuthorisedForCharityReturnsFalse(): void
    {
        $this->db->method('queryValue')
            ->willReturn(0);

        $result = $this->service->isEmailAuthorisedForCharity('user@example.com', 1);
        $this->assertFalse($result);
    }

    public function testIsEmailAuthorisedForCharityChecksEmailAndDomain(): void
    {
        $this->db->expects($this->once())
            ->method('queryValue')
            ->with(
                $this->anything(),
                [1, 'user@example.com', '@example.com']
            )
            ->willReturn(0);

        $this->service->isEmailAuthorisedForCharity('user@example.com', 1);
    }

    // ===========================================
    // updateDisplayName() tests
    // ===========================================

    public function testUpdateDisplayNameExecutesUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE users SET display_name = ?'),
                ['New Name', 1]
            );

        $this->service->updateDisplayName(1, 'New Name');
    }

    // ===========================================
    // getUserCharities() tests
    // ===========================================

    public function testGetUserCharitiesReturnsActiveCharities(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Active Charity', 'role' => 'admin'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('cu.is_active = TRUE'),
                    $this->stringContains('c.is_active = TRUE')
                ),
                [1]
            )
            ->willReturn($expected);

        $result = $this->service->getUserCharities(1);
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getUserSuspendedCharities() tests
    // ===========================================

    public function testGetUserSuspendedCharitiesReturnsSuspendedOnly(): void
    {
        $expected = [
            ['id' => 2, 'name' => 'Suspended Charity', 'role' => 'standard'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('cu.is_active = TRUE'),
                    $this->stringContains('c.is_active = FALSE')
                ),
                [1]
            )
            ->willReturn($expected);

        $result = $this->service->getUserSuspendedCharities(1);
        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // addIdentityToUser() tests
    // ===========================================

    public function testAddIdentityToUserReturnsIdentityId(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('user_identities', $this->callback(function ($data) {
                return $data['user_id'] === 5
                    && $data['oauth_provider_id'] === 1
                    && $data['oauth_subject'] === 'subject123'
                    && $data['oauth_email'] === 'test@example.com'
                    && $data['is_verified'] === true;
            }))
            ->willReturn(42);

        $this->logger->expects($this->once())->method('auth');

        $result = $this->service->addIdentityToUser(5, 1, 'subject123', 'test@example.com');

        $this->assertEquals(42, $result);
    }

    // ===========================================
    // addUserToCharityIfNotMember() tests
    // ===========================================

    public function testAddUserToCharityIfNotMemberReturnsFalseIfAlreadyMember(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['id' => 1]); // Already a member

        $result = $this->service->addUserToCharityIfNotMember(5, 1);

        $this->assertFalse($result);
    }

    public function testAddUserToCharityIfNotMemberAddsAsAdmin(): void
    {
        $this->db->method('queryOne')->willReturn(null); // Not a member
        $this->db->method('queryValue')->willReturn(0); // No existing users

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $this->logger->expects($this->once())->method('auth');

        $result = $this->service->addUserToCharityIfNotMember(5, 1);

        $this->assertTrue($result);
        $this->assertEquals('admin', $insertedData['role']);
    }

    public function testAddUserToCharityIfNotMemberAddsAsStandard(): void
    {
        $this->db->method('queryOne')->willReturn(null); // Not a member
        $this->db->method('queryValue')->willReturn(3); // 3 existing users

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                $insertedData = $data;
                return 1;
            });

        $result = $this->service->addUserToCharityIfNotMember(5, 1);

        $this->assertTrue($result);
        $this->assertEquals('standard', $insertedData['role']);
    }
}
