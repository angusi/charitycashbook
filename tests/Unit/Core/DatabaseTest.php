<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CharityCashbook\Core\Database;
use PHPUnit\Framework\TestCase;
use PDO;
use ReflectionClass;

/**
 * Tests for Database class using SQLite in-memory database
 * 
 * Note: These tests use SQLite instead of MySQL to avoid requiring
 * a real database connection. The Database class DSN format is for MySQL,
 * so we inject a PDO connection directly for testing.
 */
class DatabaseTest extends TestCase
{
    private Database $database;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Create SQLite in-memory database
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Create test table
        $this->pdo->exec('CREATE TABLE test_table (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            value INTEGER,
            active INTEGER DEFAULT 1
        )');

        // Create Database instance with dummy config
        $this->database = new Database([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test',
            'charset' => 'utf8mb4',
            'username' => 'test',
            'password' => 'test',
        ]);

        // Inject our SQLite PDO connection using reflection
        $reflection = new ReflectionClass($this->database);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $property->setValue($this->database, $this->pdo);
    }

    public function testConstructorSetsConfig(): void
    {
        $config = ['host' => 'localhost', 'port' => 3306, 'database' => 'test', 'charset' => 'utf8mb4', 'username' => 'user', 'password' => 'pass'];
        $database = new Database($config);
        
        $this->assertInstanceOf(Database::class, $database);
    }

    public function testGetConnectionReturnsPdo(): void
    {
        $pdo = $this->database->getConnection();
        
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testQueryReturnsAllRows(): void
    {
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('one', 1)");
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('two', 2)");

        $result = $this->database->query('SELECT * FROM test_table ORDER BY id');

        $this->assertCount(2, $result);
        $this->assertEquals('one', $result[0]['name']);
        $this->assertEquals('two', $result[1]['name']);
    }

    public function testQueryWithParameters(): void
    {
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('one', 1)");
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('two', 2)");

        $result = $this->database->query('SELECT * FROM test_table WHERE value > ?', [1]);

        $this->assertCount(1, $result);
        $this->assertEquals('two', $result[0]['name']);
    }

    public function testQueryOneReturnsSingleRow(): void
    {
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('test', 42)");

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE name = ?', ['test']);

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
        $this->assertEquals(42, $result['value']);
    }

    public function testQueryOneReturnsNullWhenNoMatch(): void
    {
        $result = $this->database->queryOne('SELECT * FROM test_table WHERE name = ?', ['nonexistent']);

        $this->assertNull($result);
    }

    public function testQueryValueReturnsSingleValue(): void
    {
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('test', 42)");

        $result = $this->database->queryValue('SELECT value FROM test_table WHERE name = ?', ['test']);

        $this->assertEquals(42, $result);
    }

    public function testQueryValueReturnsFalseWhenNoMatch(): void
    {
        $result = $this->database->queryValue('SELECT value FROM test_table WHERE name = ?', ['nonexistent']);

        $this->assertFalse($result);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('test1', 1)");
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('test2', 2)");

        $affected = $this->database->execute('UPDATE test_table SET value = ? WHERE value < ?', [99, 10]);

        $this->assertEquals(2, $affected);
    }

    public function testLastInsertIdReturnsId(): void
    {
        $this->database->execute("INSERT INTO test_table (name, value) VALUES ('test', 1)");

        $id = $this->database->lastInsertId();

        $this->assertGreaterThan(0, $id);
    }

    public function testBeginTransactionStartsTransaction(): void
    {
        $this->database->beginTransaction();

        $this->assertTrue($this->database->inTransaction());
        
        $this->database->rollback();
    }

    public function testCommitCommitsTransaction(): void
    {
        $this->database->beginTransaction();
        $this->database->execute("INSERT INTO test_table (name, value) VALUES ('commit_test', 1)");
        $this->database->commit();

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE name = ?', ['commit_test']);
        $this->assertNotNull($result);
    }

    public function testRollbackRevertsTransaction(): void
    {
        $this->database->beginTransaction();
        $this->database->execute("INSERT INTO test_table (name, value) VALUES ('rollback_test', 1)");
        $this->database->rollback();

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE name = ?', ['rollback_test']);
        $this->assertNull($result);
    }

    public function testInTransactionReturnsFalseWhenNotInTransaction(): void
    {
        $this->assertFalse($this->database->inTransaction());
    }

    public function testInsertInsertsRowAndReturnsId(): void
    {
        $id = $this->database->insert('test_table', [
            'name' => 'inserted',
            'value' => 123,
        ]);

        $this->assertGreaterThan(0, $id);

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE id = ?', [$id]);
        $this->assertEquals('inserted', $result['name']);
        $this->assertEquals(123, $result['value']);
    }

    public function testInsertConvertsBooleans(): void
    {
        $id = $this->database->insert('test_table', [
            'name' => 'bool_test',
            'value' => 1,
            'active' => true,
        ]);

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE id = ?', [$id]);
        $this->assertEquals(1, $result['active']);
    }

    public function testInsertConvertsFalseToZero(): void
    {
        $id = $this->database->insert('test_table', [
            'name' => 'false_test',
            'value' => 1,
            'active' => false,
        ]);

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE id = ?', [$id]);
        $this->assertEquals(0, $result['active']);
    }

    public function testUpdateUpdatesRows(): void
    {
        $this->database->insert('test_table', ['name' => 'original', 'value' => 1]);

        $affected = $this->database->update(
            'test_table',
            ['name' => 'updated', 'value' => 2],
            'name = ?',
            ['original']
        );

        $this->assertEquals(1, $affected);

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE name = ?', ['updated']);
        $this->assertNotNull($result);
        $this->assertEquals(2, $result['value']);
    }

    public function testUpdateConvertsBooleans(): void
    {
        $id = $this->database->insert('test_table', ['name' => 'bool_update', 'value' => 1, 'active' => 1]);

        $this->database->update(
            'test_table',
            ['active' => false],
            'id = ?',
            [$id]
        );

        $result = $this->database->queryOne('SELECT * FROM test_table WHERE id = ?', [$id]);
        $this->assertEquals(0, $result['active']);
    }

    public function testUpdateReturnsZeroWhenNoMatch(): void
    {
        $affected = $this->database->update(
            'test_table',
            ['value' => 999],
            'name = ?',
            ['nonexistent']
        );

        $this->assertEquals(0, $affected);
    }

    public function testQueryEmptyResultReturnsEmptyArray(): void
    {
        $result = $this->database->query('SELECT * FROM test_table WHERE 1 = 0');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
