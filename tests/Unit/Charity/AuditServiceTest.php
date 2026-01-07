<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

class AuditServiceTest extends TestCase
{
    private AuditService $service;
    private DatabaseInterface $db;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->service = new AuditService($this->db, $this->logger);
    }

    // log tests
    public function testLogInsertsAuditEntry(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('audit_log', $this->isType('array'));
        
        $this->logger->expects($this->once())->method('audit');

        $this->service->log(1, 1, 'test.action', 'entity', 1, ['key' => 'value']);
    }

    public function testLogHandlesNullDetails(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'audit_log',
                $this->callback(fn($data) => $data['details'] === null)
            );

        $this->service->log(1, 1, 'test.action');
    }

    public function testLogHandlesNullCharityAndUser(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'audit_log',
                $this->callback(fn($data) => 
                    $data['charity_id'] === null && 
                    $data['user_id'] === null
                )
            );

        $this->service->log(null, null, 'system.action');
    }

    // logTransaction tests
    public function testLogTransactionInsertsBothEntries(): void
    {
        $this->db->expects($this->exactly(2))->method('insert');
        $this->logger->expects($this->once())->method('audit');

        $this->service->logTransaction(1, 100, 'created', 1, ['amount' => ['old' => 100, 'new' => 200]]);
    }

    public function testLogTransactionHandlesNullChanges(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('insert');

        $this->service->logTransaction(1, 100, 'deleted', 1, null);
    }

    // logDonorAccess tests
    public function testLogDonorAccessInsertsEntry(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('donor_access_log', $this->isType('array'));

        $this->service->logDonorAccess(1, 1, 1, 'view_address');
    }

    public function testLogDonorAccessSkipsNullDonorId(): void
    {
        $this->db->expects($this->never())->method('insert');

        $this->service->logDonorAccess(1, 1, null, 'view_address');
    }

    // getEntries tests
    public function testGetEntriesReturnsArray(): void
    {
        $entries = [
            ['id' => 1, 'action' => 'test.action'],
        ];
        $this->db->method('query')->willReturn($entries);

        $result = $this->service->getEntries(1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetEntriesAppliesFilters(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DATE(al.created_at) >= ?'),
                    $this->stringContains('DATE(al.created_at) <= ?'),
                    $this->stringContains('al.user_id = ?'),
                    $this->stringContains('al.action = ?'),
                    $this->stringContains('al.entity_type = ?')
                ),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getEntries(1, [
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
            'user_id' => 1,
            'action' => 'test.action',
            'entity_type' => 'transaction',
        ]);
    }

    public function testGetEntriesUsesLimitAndOffset(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIMIT ? OFFSET ?'),
                $this->callback(fn($params) => 
                    $params[count($params) - 2] === 50 &&
                    $params[count($params) - 1] === 10
                )
            )
            ->willReturn([]);

        $this->service->getEntries(1, [], 50, 10);
    }

    // getEntriesCount tests
    public function testGetEntriesCountReturnsInt(): void
    {
        $this->db->method('queryOne')->willReturn(['count' => 42]);

        $result = $this->service->getEntriesCount(1);

        $this->assertEquals(42, $result);
    }

    public function testGetEntriesCountReturnsZeroWhenNull(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getEntriesCount(1);

        $this->assertEquals(0, $result);
    }

    public function testGetEntriesCountAppliesFilters(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DATE(created_at) >= ?'),
                    $this->stringContains('user_id = ?')
                ),
                $this->anything()
            )
            ->willReturn(['count' => 5]);

        $this->service->getEntriesCount(1, [
            'date_from' => '2025-01-01',
            'user_id' => 1,
        ]);
    }

    // getDistinctActions tests
    public function testGetDistinctActionsReturnsArray(): void
    {
        $this->db->method('query')->willReturn([
            ['action' => 'transaction.created'],
            ['action' => 'donor.created'],
        ]);

        $result = $this->service->getDistinctActions(1);

        $this->assertIsArray($result);
        $this->assertContains('transaction.created', $result);
        $this->assertContains('donor.created', $result);
    }

    // getUsersWithActivity tests
    public function testGetUsersWithActivityReturnsArray(): void
    {
        $users = [
            ['id' => 1, 'display_name' => 'John Doe'],
        ];
        $this->db->method('query')->willReturn($users);

        $result = $this->service->getUsersWithActivity(1);

        $this->assertIsArray($result);
    }

    // getCharityLog tests
    public function testGetCharityLogReturnsArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getCharityLog(1);

        $this->assertIsArray($result);
    }

    public function testGetCharityLogAppliesFilters(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('al.created_at >= ?'),
                    $this->stringContains('al.created_at <= ?'),
                    $this->stringContains('al.action LIKE ?')
                ),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getCharityLog(1, [
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
            'action' => 'transaction',
        ]);
    }

    public function testGetCharityLogUsesCustomLimit(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(fn($params) => in_array(100, $params))
            )
            ->willReturn([]);

        $this->service->getCharityLog(1, ['limit' => 100]);
    }

    public function testGetCharityLogUsesDefaultLimit(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIMIT 500'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getCharityLog(1);
    }

    // getTransactionHistory tests
    public function testGetTransactionHistoryReturnsArray(): void
    {
        $history = [
            ['id' => 1, 'action' => 'created'],
        ];
        $this->db->method('query')->willReturn($history);

        $result = $this->service->getTransactionHistory(100);

        $this->assertIsArray($result);
    }

    // getDonorAccessHistory tests
    public function testGetDonorAccessHistoryReturnsArray(): void
    {
        $history = [
            ['id' => 1, 'access_type' => 'view_address'],
        ];
        $this->db->method('query')->willReturn($history);

        $result = $this->service->getDonorAccessHistory(1, 1);

        $this->assertIsArray($result);
    }

    // logLogin tests
    public function testLogLoginInsertsEntry(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('login_history', $this->callback(fn($data) => 
                $data['user_id'] === 1 &&
                $data['oauth_email'] === 'test@example.com' &&
                $data['success'] === true
            ));

        $this->service->logLogin(1, 1, 'test@example.com', true);
    }

    public function testLogLoginHandlesFailure(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('login_history', $this->callback(fn($data) => 
                $data['success'] === false &&
                $data['failure_reason'] === 'Invalid credentials'
            ));

        $this->service->logLogin(null, 1, 'test@example.com', false, 'Invalid credentials');
    }

    // cleanOldLogs tests
    public function testCleanOldLogsDeletesFromAllTables(): void
    {
        $this->db->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(10);

        $result = $this->service->cleanOldLogs(7);

        $this->assertEquals(30, $result);
    }

    public function testCleanOldLogsUsesCorrectCutoffDate(): void
    {
        $expectedCutoff = date('Y-m-d', strtotime('-5 years'));
        
        $this->db->expects($this->exactly(3))
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(fn($params) => $params[0] === $expectedCutoff)
            )
            ->willReturn(0);

        $this->service->cleanOldLogs(5);
    }

    // Additional tests for complete coverage
    public function testLogDonorAccessWithDetails(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('donor_access_log', $this->isType('array'));

        $this->service->logDonorAccess(1, 1, 1, 'export', ['format' => 'csv']);
    }

    public function testGetEntriesCountAppliesDateToFilter(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('DATE(created_at) <= ?'),
                $this->callback(fn($params) => in_array('2025-12-31', $params))
            )
            ->willReturn(['count' => 5]);

        $this->service->getEntriesCount(1, [
            'date_to' => '2025-12-31',
        ]);
    }

    public function testGetEntriesCountAppliesActionFilter(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('action = ?'),
                $this->callback(fn($params) => in_array('test.action', $params))
            )
            ->willReturn(['count' => 3]);

        $this->service->getEntriesCount(1, [
            'action' => 'test.action',
        ]);
    }

    public function testGetCharityLogAppliesUserIdFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('al.user_id = ?'),
                $this->callback(fn($params) => in_array(42, $params))
            )
            ->willReturn([]);

        $this->service->getCharityLog(1, ['user_id' => 42]);
    }

    public function testGetCharityLogAppliesEntityTypeFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('al.entity_type = ?'),
                $this->callback(fn($params) => in_array('transaction', $params))
            )
            ->willReturn([]);

        $this->service->getCharityLog(1, ['entity_type' => 'transaction']);
    }
}
