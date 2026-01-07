<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\ServiceAdmin;

use CharityCashbook\ServiceAdmin\ServiceMetricsService;
use CharityCashbook\Core\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ServiceMetricsServiceTest extends TestCase
{
    private ServiceMetricsService $service;
    private MockObject $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->service = new ServiceMetricsService($this->db);
    }

    // ===========================================
    // getSecurityAlerts() tests
    // ===========================================

    public function testGetSecurityAlertsReturnsEmptyArrayWhenNoIssues(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getSecurityAlerts();

        $this->assertEquals([], $result);
    }

    public function testGetSecurityAlertsDetectsBruteForceIps(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['ip_address' => '192.168.1.1', 'failed_count' => 10]],
                [], // targeted accounts
                [] // activity spikes
            );

        $result = $this->service->getSecurityAlerts();

        $this->assertCount(1, $result);
        $this->assertEquals('brute_force_ip', $result[0]['type']);
        $this->assertStringContainsString('192.168.1.1', $result[0]['message']);
    }

    public function testGetSecurityAlertsHighSeverityForManyAttempts(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['ip_address' => '192.168.1.1', 'failed_count' => 25]],
                [],
                []
            );

        $result = $this->service->getSecurityAlerts();

        $this->assertEquals('high', $result[0]['severity']);
    }

    public function testGetSecurityAlertsMediumSeverityForFewerAttempts(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['ip_address' => '192.168.1.1', 'failed_count' => 5]],
                [],
                []
            );

        $result = $this->service->getSecurityAlerts();

        $this->assertEquals('medium', $result[0]['severity']);
    }

    public function testGetSecurityAlertsDetectsTargetedAccounts(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [], // suspicious IPs
                [['oauth_email' => 'test@example.com', 'failed_count' => 8]],
                []
            );

        $result = $this->service->getSecurityAlerts();

        $this->assertCount(1, $result);
        $this->assertEquals('targeted_account', $result[0]['type']);
    }

    public function testGetSecurityAlertsDetectsActivitySpikes(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [],
                [],
                [['id' => 1, 'name' => 'Test Charity', 'today_actions' => 150, 'avg_daily_actions' => 20]]
            );

        $result = $this->service->getSecurityAlerts();

        $this->assertCount(1, $result);
        $this->assertEquals('activity_spike', $result[0]['type']);
        $this->assertEquals('low', $result[0]['severity']);
    }

    // ===========================================
    // getStorageMetrics() tests
    // ===========================================

    public function testGetStorageMetricsReturnsTableCounts(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['cnt' => '100']);

        $result = $this->service->getStorageMetrics();

        $this->assertArrayHasKey('charities', $result);
        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertArrayHasKey('donors', $result);
        $this->assertArrayHasKey('members', $result);
        $this->assertArrayHasKey('audit_log', $result);

        $this->assertEquals(100, $result['charities']['count']);
        $this->assertEquals('Charities', $result['charities']['label']);
    }

    public function testGetStorageMetricsHandlesNullCount(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['cnt' => null]);

        $result = $this->service->getStorageMetrics();

        $this->assertEquals(0, $result['charities']['count']);
    }

    // ===========================================
    // getActivityMetrics() tests
    // ===========================================

    public function testGetActivityMetricsReturnsAllMetricTypes(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['date' => '2024-01-01', 'successful' => 10, 'failed' => 2]],
                [['date' => '2024-01-01', 'count' => 50]],
                [['date' => '2024-01-01', 'count' => 5]]
            );

        $result = $this->service->getActivityMetrics();

        $this->assertArrayHasKey('logins', $result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertArrayHasKey('active_users', $result);
    }

    // ===========================================
    // getCharityLeaderboard() tests
    // ===========================================

    public function testGetCharityLeaderboardReturnsAllCategories(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->getCharityLeaderboard();

        $this->assertArrayHasKey('most_active', $result);
        $this->assertArrayHasKey('by_transactions', $result);
        $this->assertArrayHasKey('by_users', $result);
        $this->assertArrayHasKey('newest', $result);
        $this->assertArrayHasKey('dormant', $result);
    }

    public function testGetCharityLeaderboardReturnsCharityData(): void
    {
        $this->db->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'name' => 'Active Charity', 'action_count' => 100]],
                [],
                [],
                [],
                []
            );

        $result = $this->service->getCharityLeaderboard();

        $this->assertCount(1, $result['most_active']);
        $this->assertEquals('Active Charity', $result['most_active'][0]['name']);
    }

    // ===========================================
    // getDashboardStats() tests
    // ===========================================

    public function testGetDashboardStatsReturnsAllStats(): void
    {
        $this->db->method('queryValue')
            ->willReturn(42);

        $result = $this->service->getDashboardStats();

        $this->assertArrayHasKey('charities_active', $result);
        $this->assertArrayHasKey('users_total', $result);
        $this->assertArrayHasKey('sessions_active', $result);
        $this->assertArrayHasKey('logins_today', $result);
        $this->assertArrayHasKey('logins_failed_today', $result);
        $this->assertArrayHasKey('transactions_week', $result);
        $this->assertArrayHasKey('active_users_today', $result);

        $this->assertEquals(42, $result['charities_active']);
    }

    // ===========================================
    // getLoginsByIp() tests
    // ===========================================

    public function testGetLoginsByIpReturnsLogins(): void
    {
        $expected = [
            ['ip_address' => '192.168.1.1', 'oauth_email' => 'test@example.com'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                ['192.168.1.1', 24]
            )
            ->willReturn($expected);

        $result = $this->service->getLoginsByIp('192.168.1.1');

        $this->assertEquals($expected, $result);
    }

    public function testGetLoginsByIpAcceptsCustomHours(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                ['192.168.1.1', 48]
            )
            ->willReturn([]);

        $this->service->getLoginsByIp('192.168.1.1', 48);
    }

    // ===========================================
    // getLoginsByEmail() tests
    // ===========================================

    public function testGetLoginsByEmailReturnsLogins(): void
    {
        $expected = [
            ['oauth_email' => 'test@example.com', 'success' => true],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                ['test@example.com', 24]
            )
            ->willReturn($expected);

        $result = $this->service->getLoginsByEmail('test@example.com');

        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getUserByEmail() tests
    // ===========================================

    public function testGetUserByEmailFindsFromIdentities(): void
    {
        $expected = ['id' => 1, 'display_name' => 'Test User'];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls($expected);

        $result = $this->service->getUserByEmail('test@example.com');

        $this->assertEquals($expected, $result);
    }

    public function testGetUserByEmailFallsToPrimaryEmail(): void
    {
        $expected = ['id' => 1, 'display_name' => 'Test User'];

        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                null, // identity not found
                $expected // primary email found
            );

        $result = $this->service->getUserByEmail('test@example.com');

        $this->assertEquals($expected, $result);
    }

    public function testGetUserByEmailReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getUserByEmail('unknown@example.com');

        $this->assertNull($result);
    }

    // ===========================================
    // getUserSessions() tests
    // ===========================================

    public function testGetUserSessionsReturnsActiveSessions(): void
    {
        $expected = [
            ['id' => 1, 'ip_address' => '192.168.1.1'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($expected);

        $result = $this->service->getUserSessions(1);

        $this->assertEquals($expected, $result);
    }

    // ===========================================
    // getFailedLoginIps() tests
    // ===========================================

    public function testGetFailedLoginIpsReturnsAggregatedData(): void
    {
        $expected = [
            ['ip_address' => '192.168.1.1', 'failed_attempts' => 10],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                [24, 3]
            )
            ->willReturn($expected);

        $result = $this->service->getFailedLoginIps();

        $this->assertEquals($expected, $result);
    }

    public function testGetFailedLoginIpsAcceptsCustomParameters(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                [48, 5]
            )
            ->willReturn([]);

        $this->service->getFailedLoginIps(48, 5);
    }

    // ===========================================
    // getFailedLoginEmails() tests
    // ===========================================

    public function testGetFailedLoginEmailsReturnsAggregatedData(): void
    {
        $expected = [
            ['oauth_email' => 'test@example.com', 'failed_attempts' => 5],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                [24, 3]
            )
            ->willReturn($expected);

        $result = $this->service->getFailedLoginEmails();

        $this->assertEquals($expected, $result);
    }
}
