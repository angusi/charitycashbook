<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use CharityCashbook\Auth\Session;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SessionManagerTest extends TestCase
{
    private SessionManager $sessionManager;
    private MockObject&DatabaseInterface $db;
    private MockObject&Logger $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->sessionManager = new SessionManager($this->db, $this->logger, 30);
    }

    // ========================================
    // create tests
    // ========================================

    public function testCreateGeneratesTokenAndStoresSession(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['display_name' => 'John Doe', 'is_super_admin' => false]);

        $insertedData = null;
        $this->db->expects($this->once())
            ->method('insert')
            ->with('sessions', $this->callback(function ($data) use (&$insertedData) {
                $insertedData = $data;
                return true;
            }))
            ->willReturn(1);

        $this->logger->expects($this->once())
            ->method('auth')
            ->with('Session created', ['user_id' => 42]);

        $token = $this->sessionManager->create(42);

        $this->assertNotEmpty($token);
        $this->assertSame(42, $insertedData['user_id']);
        $this->assertSame('{}', $insertedData['charity_sessions']);
        $this->assertNotEmpty($insertedData['token_hash']);
        $this->assertNotEmpty($insertedData['expires_at']);
    }

    public function testCreateTokenHasCorrectLength(): void
    {
        $this->db->method('queryOne')->willReturn(['display_name' => 'Test', 'is_super_admin' => false]);
        $this->db->method('insert')->willReturn(1);

        $result = $this->sessionManager->create(1);

        // create() returns array with token and id
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('id', $result);
        // Base64 encoded 64 bytes = ~88 characters
        $this->assertGreaterThan(80, strlen($result['token']));
        $this->assertEquals(1, $result['id']);
    }

    // ========================================
    // validate tests
    // ========================================

    public function testValidateReturnsNullWhenNoCookie(): void
    {
        // No cookie set
        $result = $this->sessionManager->validate();

        $this->assertNull($result);
    }

    public function testValidateReturnsNullWhenSessionNotFound(): void
    {
        $_COOKIE['charity_cashbook_session'] = 'some_token';

        $this->db->method('queryOne')->willReturn(null);

        $result = $this->sessionManager->validate();

        $this->assertNull($result);
    }

    public function testValidateReturnsSessionWhenValid(): void
    {
        $_COOKIE['charity_cashbook_session'] = 'valid_token';

        $sessionData = [
            'id' => 1,
            'user_id' => 42,
            'display_name' => 'John Doe',
            'is_super_admin' => true,
            'charity_sessions' => '{}',
            'created_at' => '2024-01-01 10:00:00',
            'last_active_at' => '2024-01-01 12:00:00',
        ];

        $this->db->method('queryOne')->willReturn($sessionData);
        $this->db->method('execute'); // Update last_active_at

        $result = $this->sessionManager->validate();

        $this->assertInstanceOf(Session::class, $result);
        $this->assertSame(42, $result->getUserId());
        $this->assertTrue($result->isSuperAdmin());
    }

    public function testValidateUpdatesLastActiveTimestamp(): void
    {
        $_COOKIE['charity_cashbook_session'] = 'valid_token';

        $sessionData = [
            'id' => 99,
            'user_id' => 1,
            'display_name' => 'Test',
            'is_super_admin' => false,
            'charity_sessions' => '{}',
        ];

        $this->db->method('queryOne')->willReturn($sessionData);

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE sessions SET last_active_at'),
                [99]
            );

        $this->sessionManager->validate();
    }

    // ========================================
    // checkCharitySession tests
    // ========================================

    public function testCheckCharitySessionReturnsFalseWhenNoSession(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => '{}',
        ]);

        $result = $this->sessionManager->checkCharitySession($session, 42, 24);

        $this->assertFalse($result);
    }

    public function testCheckCharitySessionReturnsTrueWhenNotExpired(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => json_encode([
                '42' => [
                    'authenticated_at' => time() - 3600, // 1 hour ago
                    'authenticated_via_identity_id' => 5,
                ],
            ]),
        ]);

        $result = $this->sessionManager->checkCharitySession($session, 42, 24); // 24 hour timeout

        $this->assertTrue($result);
    }

    public function testCheckCharitySessionReturnsFalseWhenExpired(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => json_encode([
                '42' => [
                    'authenticated_at' => time() - 90000, // 25 hours ago
                    'authenticated_via_identity_id' => 5,
                ],
            ]),
        ]);

        $result = $this->sessionManager->checkCharitySession($session, 42, 24); // 24 hour timeout

        $this->assertFalse($result);
    }

    public function testCheckCharitySessionReturnsFalseWhenNoIdentityTracked(): void
    {
        // Session without authenticated_via_identity_id should be considered invalid
        // This forces re-auth for legacy sessions that don't track which identity was used
        $session = new Session([
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => json_encode([
                '42' => [
                    'authenticated_at' => time() - 3600, // 1 hour ago, not expired
                    // Note: no authenticated_via_identity_id
                ],
            ]),
        ]);

        $result = $this->sessionManager->checkCharitySession($session, 42, 24); // 24 hour timeout

        $this->assertFalse($result);
    }

    // ========================================
    // refreshCharitySession tests
    // ========================================

    public function testRefreshCharitySessionUpdatesSession(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['charity_sessions' => '{}']);

        $capturedJson = null;
        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE sessions SET charity_sessions'),
                $this->callback(function ($params) use (&$capturedJson) {
                    $capturedJson = $params[0];
                    return $params[1] === 1;
                })
            );

        $this->sessionManager->refreshCharitySession(1, 42, 'admin', 5, 24);

        $decoded = json_decode($capturedJson, true);
        $this->assertArrayHasKey('42', $decoded);
        $this->assertSame('admin', $decoded['42']['role']);
        $this->assertSame(5, $decoded['42']['authenticated_via_identity_id']);
        $this->assertSame(24, $decoded['42']['timeout_hours']);
    }

    public function testRefreshCharitySessionDoesNothingWhenSessionNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);
        $this->db->expects($this->never())->method('execute');

        $this->sessionManager->refreshCharitySession(999, 42, 'admin', 5, 24);
    }

    // ========================================
    // invalidateCharitySession tests
    // ========================================

    public function testInvalidateCharitySessionRemovesFromAllUserSessions(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'charity_sessions' => '{"42":{"role":"admin"},"43":{"role":"viewer"}}'],
                ['id' => 2, 'charity_sessions' => '{"42":{"role":"treasurer"}}'],
            ]);

        $executeCalls = [];
        $this->db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function ($sql, $params) use (&$executeCalls) {
                $executeCalls[] = $params;
                return 1;
            });

        $this->logger->expects($this->once())
            ->method('auth')
            ->with('Charity session invalidated', ['user_id' => 100, 'charity_id' => 42]);

        $this->sessionManager->invalidateCharitySession(100, 42);

        // Session 1 should still have charity 43
        $decoded1 = json_decode($executeCalls[0][0], true);
        $this->assertArrayNotHasKey('42', $decoded1);
        $this->assertArrayHasKey('43', $decoded1);

        // Session 2 should be empty
        $decoded2 = json_decode($executeCalls[1][0], true);
        $this->assertArrayNotHasKey('42', $decoded2);
    }

    public function testInvalidateCharitySessionSkipsSessionsWithoutCharity(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'charity_sessions' => '{"99":{"role":"admin"}}'],
            ]);

        $this->db->expects($this->never())->method('execute');

        $this->sessionManager->invalidateCharitySession(100, 42);
    }

    // ========================================
    // invalidateAllCharitySessions tests
    // ========================================

    public function testInvalidateAllCharitySessionsRemovesCharityFromAllSessions(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['id' => 1, 'charity_sessions' => '{"42":{"role":"admin"}}'],
            ]);

        $this->db->expects($this->once())->method('execute');

        $this->logger->expects($this->once())
            ->method('auth')
            ->with('All charity sessions invalidated', ['charity_id' => 42]);

        $this->sessionManager->invalidateAllCharitySessions(42);
    }

    // ========================================
    // destroy tests
    // ========================================

    public function testDestroyDeletesSessionAndClearsCookie(): void
    {
        $_COOKIE['charity_cashbook_session'] = 'token_to_delete';

        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM sessions WHERE token_hash = ?', $this->anything());

        $this->logger->expects($this->once())
            ->method('auth')
            ->with('Session destroyed');

        $this->sessionManager->destroy();
    }

    public function testDestroyHandlesNoCookie(): void
    {
        // No cookie set
        unset($_COOKIE['charity_cashbook_session']);

        $this->db->expects($this->never())->method('execute');

        $this->sessionManager->destroy();
    }

    // ========================================
    // destroyAllForUser tests
    // ========================================

    public function testDestroyAllForUserDeletesAllUserSessions(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM sessions WHERE user_id = ?', [42]);

        $this->logger->expects($this->once())
            ->method('auth')
            ->with('All sessions destroyed for user', ['user_id' => 42]);

        $this->sessionManager->destroyAllForUser(42);
    }

    // ========================================
    // getSessionsForUser tests
    // ========================================

    public function testGetSessionsForUserReturnsActiveSessions(): void
    {
        $expected = [
            ['id' => 1, 'created_at' => '2024-01-01', 'last_active_at' => '2024-01-01', 'ip_address' => '1.2.3.4', 'user_agent' => 'Firefox'],
        ];

        $this->db->method('query')
            ->with($this->stringContains('WHERE user_id = ?'), [42])
            ->willReturn($expected);

        $result = $this->sessionManager->getSessionsForUser(42);

        $this->assertSame($expected, $result);
    }

    // ========================================
    // cleanExpiredSessions tests
    // ========================================

    public function testCleanExpiredSessionsDeletesExpiredSessions(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM sessions WHERE expires_at < NOW()')
            ->willReturn(5);

        $count = $this->sessionManager->cleanExpiredSessions();

        $this->assertSame(5, $count);
    }
}
