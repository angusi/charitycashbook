<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Auth;

use CharityCashbook\Auth\AccountLinkingService;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\AuthenticationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AccountLinkingServiceTest extends TestCase
{
    private AccountLinkingService $service;
    private MockObject $db;
    private MockObject $logger;
    private MockObject $identityService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->identityService = $this->createMock(IdentityService::class);

        $this->service = new AccountLinkingService(
            $this->db,
            $this->logger,
            $this->identityService
        );
    }

    // ===========================================
    // createPendingLink() tests
    // ===========================================

    public function testCreatePendingLinkReturnsToken(): void
    {
        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->db->method('insert')->willReturn(1);

        $result = $this->service->createPendingLink(1, 1, 'subject123', 'test@example.com');

        $this->assertIsString($result);
        $this->assertEquals(64, strlen($result)); // 32 bytes = 64 hex chars
    }

    public function testCreatePendingLinkThrowsWhenIdentityAlreadyLinked(): void
    {
        $this->identityService->method('findIdentity')
            ->willReturn(['id' => 1, 'user_id' => 2]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('This OAuth account is already linked to another user');

        $this->service->createPendingLink(1, 1, 'subject123', 'test@example.com');
    }

    public function testCreatePendingLinkDeletesExistingPending(): void
    {
        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM pending_identity_links'),
                [1, 1, 'subject123']
            );

        $this->db->method('insert')->willReturn(1);

        $this->service->createPendingLink(1, 1, 'subject123', 'test@example.com');
    }

    public function testCreatePendingLinkInsertsPendingLink(): void
    {
        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('pending_identity_links', $this->callback(function ($data) {
                return $data['existing_user_id'] === 1
                    && $data['new_oauth_provider_id'] === 1
                    && $data['new_oauth_subject'] === 'subject123'
                    && $data['new_oauth_email'] === 'test@example.com'
                    && isset($data['confirmation_token'])
                    && isset($data['expires_at']);
            }))
            ->willReturn(1);

        $this->service->createPendingLink(1, 1, 'subject123', 'test@example.com');
    }

    // ===========================================
    // confirmLink() tests
    // ===========================================

    public function testConfirmLinkReturnsUser(): void
    {
        $pending = [
            'id' => 1,
            'existing_user_id' => 1,
            'new_oauth_provider_id' => 2,
            'new_oauth_subject' => 'subject456',
            'new_oauth_email' => 'new@example.com',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];

        $this->db->method('queryOne')
            ->willReturn($pending);

        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->identityService->method('findCharitiesForEmail')
            ->willReturn([]);

        $expectedUser = ['id' => 1, 'display_name' => 'Test User'];
        $this->identityService->method('getUser')
            ->willReturn($expectedUser);

        $this->db->method('insert')->willReturn(1);

        $result = $this->service->confirmLink('validtoken');

        $this->assertEquals($expectedUser, $result);
    }

    public function testConfirmLinkThrowsForInvalidToken(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired link confirmation token');

        $this->service->confirmLink('invalidtoken');
    }

    public function testConfirmLinkThrowsForExpiredToken(): void
    {
        $pending = [
            'id' => 1,
            'expires_at' => date('Y-m-d H:i:s', time() - 3600), // expired
        ];

        $this->db->method('queryOne')
            ->willReturn($pending);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Link confirmation token has expired');

        $this->service->confirmLink('expiredtoken');
    }

    public function testConfirmLinkThrowsWhenIdentityAlreadyLinked(): void
    {
        $pending = [
            'id' => 1,
            'existing_user_id' => 1,
            'new_oauth_provider_id' => 2,
            'new_oauth_subject' => 'subject456',
            'new_oauth_email' => 'new@example.com',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];

        $this->db->method('queryOne')
            ->willReturn($pending);

        // Identity is now linked to another user
        $this->identityService->method('findIdentity')
            ->willReturn(['id' => 5, 'user_id' => 99]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('This OAuth account has already been linked');

        $this->service->confirmLink('validtoken');
    }

    public function testConfirmLinkAddsUserToCharities(): void
    {
        $pending = [
            'id' => 1,
            'existing_user_id' => 1,
            'new_oauth_provider_id' => 2,
            'new_oauth_subject' => 'subject456',
            'new_oauth_email' => 'new@example.com',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                $pending, // First call for pending link
                null // User doesn't have access to charity yet
            );

        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->identityService->method('findCharitiesForEmail')
            ->willReturn([
                ['id' => 10, 'name' => 'Test Charity'],
            ]);

        $this->identityService->method('getUser')
            ->willReturn(['id' => 1]);

        // Should insert user_identities and charity_users
        $this->db->expects($this->exactly(2))
            ->method('insert');

        $this->service->confirmLink('validtoken');
    }

    public function testConfirmLinkRollsBackOnException(): void
    {
        $pending = [
            'id' => 1,
            'existing_user_id' => 1,
            'new_oauth_provider_id' => 2,
            'new_oauth_subject' => 'subject456',
            'new_oauth_email' => 'new@example.com',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];

        $this->db->method('queryOne')
            ->willReturn($pending);

        $this->identityService->method('findIdentity')
            ->willReturn(null);

        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->db->method('insert')
            ->willThrowException(new \Exception('DB error'));

        $this->db->expects($this->once())
            ->method('rollback');

        $this->expectException(\Exception::class);
        $this->service->confirmLink('validtoken');
    }

    // ===========================================
    // cancelPendingLink() tests
    // ===========================================

    public function testCancelPendingLinkDeletesRecord(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM pending_identity_links'),
                [1, 'token123']
            );

        $this->service->cancelPendingLink(1, 'token123');
    }

    // ===========================================
    // getPendingLinks() tests
    // ===========================================

    public function testGetPendingLinksReturnsArray(): void
    {
        $expected = [
            ['id' => 1, 'new_oauth_email' => 'test@example.com', 'provider_name' => 'Google'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('expires_at > NOW()'),
                [1]
            )
            ->willReturn($expected);

        $result = $this->service->getPendingLinks(1);
        $this->assertEquals($expected, $result);
    }

    public function testGetPendingLinksReturnsEmptyArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getPendingLinks(1);
        $this->assertEquals([], $result);
    }

    // ===========================================
    // removeIdentity() tests
    // ===========================================

    public function testRemoveIdentitySucceeds(): void
    {
        $this->db->method('queryValue')
            ->willReturn(2); // User has 2 identities

        $this->db->method('queryOne')
            ->willReturn([
                'id' => 5,
                'user_id' => 1,
                'oauth_email' => 'test@example.com',
            ]);

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM user_identities WHERE id = ?'),
                [5]
            );

        $this->service->removeIdentity(1, 5);
    }

    public function testRemoveIdentityThrowsWhenOnlyOneIdentity(): void
    {
        $this->db->method('queryValue')
            ->willReturn(1); // User has only 1 identity

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Cannot remove the only login method');

        $this->service->removeIdentity(1, 5);
    }

    public function testRemoveIdentityThrowsWhenIdentityNotFound(): void
    {
        $this->db->method('queryValue')
            ->willReturn(2);

        $this->db->method('queryOne')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Identity not found');

        $this->service->removeIdentity(1, 999);
    }

    // ===========================================
    // cleanExpiredLinks() tests
    // ===========================================

    public function testCleanExpiredLinksReturnsCount(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('DELETE FROM pending_identity_links WHERE expires_at < NOW()'))
            ->willReturn(5);

        $result = $this->service->cleanExpiredLinks();
        $this->assertEquals(5, $result);
    }

    // ===========================================
    // sendConfirmationEmail() tests
    // ===========================================

    public function testSendConfirmationEmailLogsMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Link confirmation email sent',
                $this->callback(function ($context) {
                    return $context['to'] === 'user@example.com'
                        && $context['new_email'] === 'new@example.com';
                })
            );

        // Note: We can't easily test mail() function without mocking it
        // In a real test we'd use a mail service interface
        @$this->service->sendConfirmationEmail(
            'user@example.com',
            'token123',
            'new@example.com',
            'https://example.com'
        );
    }
}
