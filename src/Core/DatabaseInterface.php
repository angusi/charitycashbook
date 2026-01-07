<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

use PDO;

/**
 * Interface for database operations.
 * Implemented by Database and QueryTracker.
 */
interface DatabaseInterface
{
    /**
     * Get the PDO connection
     */
    public function getConnection(): PDO;

    /**
     * Execute a query and return all results
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute a query and return a single row
     */
    public function queryOne(string $sql, array $params = []): ?array;

    /**
     * Execute a query and return a single value
     */
    public function queryValue(string $sql, array $params = []): mixed;

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     * Returns the number of affected rows
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): int;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void;

    /**
     * Commit a transaction
     */
    public function commit(): void;

    /**
     * Rollback a transaction
     */
    public function rollback(): void;

    /**
     * Check if a transaction is active
     */
    public function inTransaction(): bool;

    /**
     * Insert a row and return the inserted ID
     */
    public function insert(string $table, array $data): int;

    /**
     * Update rows in a table
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int;
}
