<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database wrapper with connection management
 */
class Database implements DatabaseInterface
{
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the PDO connection (lazy initialization)
     */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );

            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    /**
     * Execute a query and return all results
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return a single row
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute a query and return a single value
     */
    public function queryValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     * Returns the number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): void
    {
        $this->getConnection()->rollBack();
    }

    /**
     * Check if a transaction is active
     */
    public function inTransaction(): bool
    {
        return $this->getConnection()->inTransaction();
    }

    /**
     * Insert a row and return the inserted ID
     */
    public function insert(string $table, array $data): int
    {
        // Convert booleans to integers for MySQL compatibility
        $data = array_map(fn($v) => is_bool($v) ? (int) $v : $v, $data);
        
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->execute($sql, array_values($data));
        return $this->lastInsertId();
    }

    /**
     * Update rows in a table
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        // Convert booleans to integers for MySQL compatibility
        $data = array_map(fn($v) => is_bool($v) ? (int) $v : $v, $data);
        
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "$column = ?";
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            $where
        );

        return $this->execute($sql, array_merge(array_values($data), $whereParams));
    }
}
