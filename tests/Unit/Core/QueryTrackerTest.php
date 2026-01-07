<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\QueryTracker;
use CharityCashbook\Core\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class QueryTrackerTest extends TestCase
{
    private QueryTracker $tracker;
    private MockObject $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Database::class);
        $this->tracker = new QueryTracker($this->db, true);
    }

    // ===========================================
    // query() tests
    // ===========================================

    public function testQueryDelegatesToDatabase(): void
    {
        $expected = [['id' => 1, 'name' => 'Test']];

        $this->db->expects($this->once())
            ->method('query')
            ->with('SELECT * FROM users', [])
            ->willReturn($expected);

        $result = $this->tracker->query('SELECT * FROM users');

        $this->assertEquals($expected, $result);
    }

    public function testQueryTracksExecution(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->tracker->query('SELECT * FROM users', ['param1']);

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('query', $queries[0]['method']);
        $this->assertEquals('SELECT * FROM users', $queries[0]['sql']);
        $this->assertEquals(['param1'], $queries[0]['params']);
        $this->assertArrayHasKey('duration', $queries[0]);
    }

    // ===========================================
    // queryOne() tests
    // ===========================================

    public function testQueryOneDelegatesToDatabase(): void
    {
        $expected = ['id' => 1, 'name' => 'Test'];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->with('SELECT * FROM users WHERE id = ?', [1])
            ->willReturn($expected);

        $result = $this->tracker->queryOne('SELECT * FROM users WHERE id = ?', [1]);

        $this->assertEquals($expected, $result);
    }

    public function testQueryOneTracksExecution(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->tracker->queryOne('SELECT * FROM users WHERE id = ?', [1]);

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('queryOne', $queries[0]['method']);
    }

    // ===========================================
    // queryValue() tests
    // ===========================================

    public function testQueryValueDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('queryValue')
            ->with('SELECT COUNT(*) FROM users', [])
            ->willReturn(42);

        $result = $this->tracker->queryValue('SELECT COUNT(*) FROM users');

        $this->assertEquals(42, $result);
    }

    public function testQueryValueTracksExecution(): void
    {
        $this->db->method('queryValue')->willReturn(0);

        $this->tracker->queryValue('SELECT COUNT(*) FROM users');

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('queryValue', $queries[0]['method']);
    }

    // ===========================================
    // execute() tests
    // ===========================================

    public function testExecuteDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM users WHERE id = ?', [1])
            ->willReturn(1);

        $result = $this->tracker->execute('DELETE FROM users WHERE id = ?', [1]);

        $this->assertEquals(1, $result);
    }

    public function testExecuteTracksExecution(): void
    {
        $this->db->method('execute')->willReturn(1);

        $this->tracker->execute('DELETE FROM users WHERE id = ?', [1]);

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('execute', $queries[0]['method']);
    }

    // ===========================================
    // insert() tests
    // ===========================================

    public function testInsertDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('users', ['name' => 'Test'])
            ->willReturn(5);

        $result = $this->tracker->insert('users', ['name' => 'Test']);

        $this->assertEquals(5, $result);
    }

    public function testInsertTracksExecution(): void
    {
        $this->db->method('insert')->willReturn(1);

        $this->tracker->insert('users', ['name' => 'Test']);

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('insert', $queries[0]['method']);
    }

    // ===========================================
    // update() tests
    // ===========================================

    public function testUpdateDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with('users', ['name' => 'Updated'], 'id = ?', [1])
            ->willReturn(1);

        $result = $this->tracker->update('users', ['name' => 'Updated'], 'id = ?', [1]);

        $this->assertEquals(1, $result);
    }

    public function testUpdateTracksExecution(): void
    {
        $this->db->method('update')->willReturn(1);

        $this->tracker->update('users', ['name' => 'Updated'], 'id = ?', [1]);

        $queries = $this->tracker->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('update', $queries[0]['method']);
    }

    // ===========================================
    // Transaction methods tests
    // ===========================================

    public function testLastInsertIdDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('lastInsertId')
            ->willReturn(99);

        $result = $this->tracker->lastInsertId();

        $this->assertEquals(99, $result);
    }

    public function testBeginTransactionDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('beginTransaction');

        $this->tracker->beginTransaction();
    }

    public function testCommitDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('commit');

        $this->tracker->commit();
    }

    public function testRollbackDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('rollback');

        $this->tracker->rollback();
    }

    public function testInTransactionDelegatesToDatabase(): void
    {
        $this->db->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $result = $this->tracker->inTransaction();

        $this->assertTrue($result);
    }

    public function testGetConnectionDelegatesToDatabase(): void
    {
        $pdo = $this->createMock(\PDO::class);

        $this->db->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $result = $this->tracker->getConnection();

        $this->assertSame($pdo, $result);
    }

    // ===========================================
    // Tracking statistics tests
    // ===========================================

    public function testGetQueriesReturnsEmptyArrayInitially(): void
    {
        $this->assertEquals([], $this->tracker->getQueries());
    }

    public function testGetQueryCountReturnsZeroInitially(): void
    {
        $this->assertEquals(0, $this->tracker->getQueryCount());
    }

    public function testGetQueryCountReturnsCorrectCount(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->tracker->query('SELECT 1');
        $this->tracker->query('SELECT 2');
        $this->tracker->query('SELECT 3');

        $this->assertEquals(3, $this->tracker->getQueryCount());
    }

    public function testGetTotalTimeReturnsMilliseconds(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->tracker->query('SELECT 1');

        // Should be a small positive number in milliseconds
        $totalTime = $this->tracker->getTotalTime();
        $this->assertGreaterThanOrEqual(0, $totalTime);
        $this->assertLessThan(1000, $totalTime); // Should be less than 1 second
    }

    public function testGetDatabaseReturnsUnderlyingDatabase(): void
    {
        $result = $this->tracker->getDatabase();

        $this->assertSame($this->db, $result);
    }

    // ===========================================
    // Disabled tracking tests
    // ===========================================

    public function testDisabledTrackerDoesNotTrack(): void
    {
        $disabledTracker = new QueryTracker($this->db, false);

        $this->db->method('query')->willReturn([]);

        $disabledTracker->query('SELECT 1');
        $disabledTracker->query('SELECT 2');

        $this->assertEquals(0, $disabledTracker->getQueryCount());
        $this->assertEquals([], $disabledTracker->getQueries());
    }

    public function testDisabledTrackerStillExecutesQueries(): void
    {
        $disabledTracker = new QueryTracker($this->db, false);

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn([['id' => 1]]);

        $result = $disabledTracker->query('SELECT * FROM users');

        $this->assertEquals([['id' => 1]], $result);
    }

    // ===========================================
    // Backtrace tests
    // ===========================================

    public function testQueriesIncludeBacktrace(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->tracker->query('SELECT 1');

        $queries = $this->tracker->getQueries();
        $this->assertArrayHasKey('backtrace', $queries[0]);
        $this->assertIsArray($queries[0]['backtrace']);
    }

    // ===========================================
    // Duration tracking tests
    // ===========================================

    public function testQueriesIncludeDuration(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->tracker->query('SELECT 1');

        $queries = $this->tracker->getQueries();
        $this->assertArrayHasKey('duration', $queries[0]);
        $this->assertIsFloat($queries[0]['duration']);
        $this->assertGreaterThanOrEqual(0, $queries[0]['duration']);
    }
}
