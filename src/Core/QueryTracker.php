<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Tracks database queries for debugging purposes.
 * Wraps the Database class to intercept and time all queries.
 */
class QueryTracker implements DatabaseInterface
{
    private Database $db;
    private array $queries = [];
    private float $totalTime = 0.0;
    private bool $enabled;

    public function __construct(Database $db, bool $enabled = true)
    {
        $this->db = $db;
        $this->enabled = $enabled;
    }

    /**
     * Execute a query and return all results
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->track('query', $sql, $params, fn() => $this->db->query($sql, $params));
    }

    /**
     * Execute a query and return a single row
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->track('queryOne', $sql, $params, fn() => $this->db->queryOne($sql, $params));
    }

    /**
     * Execute a query and return a single value
     */
    public function queryValue(string $sql, array $params = []): mixed
    {
        return $this->track('queryValue', $sql, $params, fn() => $this->db->queryValue($sql, $params));
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->track('execute', $sql, $params, fn() => $this->db->execute($sql, $params));
    }

    /**
     * Insert a row and return the inserted ID
     */
    public function insert(string $table, array $data): int
    {
        $sql = "INSERT INTO $table (...)"; // Simplified for display
        return $this->track('insert', $sql, $data, fn() => $this->db->insert($table, $data));
    }

    /**
     * Update rows in a table
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sql = "UPDATE $table SET ... WHERE $where";
        return $this->track('update', $sql, array_merge($data, $whereParams), fn() => $this->db->update($table, $data, $where, $whereParams));
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): int
    {
        return $this->db->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): void
    {
        $this->db->rollback();
    }

    /**
     * Check if a transaction is active
     */
    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    /**
     * Get the underlying PDO connection
     */
    public function getConnection(): \PDO
    {
        return $this->db->getConnection();
    }

    /**
     * Track a query execution
     */
    private function track(string $method, string $sql, array $params, callable $execute): mixed
    {
        if (!$this->enabled) {
            return $execute();
        }

        $start = microtime(true);
        $result = $execute();
        $duration = microtime(true) - $start;

        $this->queries[] = [
            'method' => $method,
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'backtrace' => $this->getSimplifiedBacktrace(),
        ];

        $this->totalTime += $duration;

        return $result;
    }

    /**
     * Get a simplified backtrace showing where the query originated
     */
    private function getSimplifiedBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $simplified = [];

        foreach ($trace as $frame) {
            // Skip internal frames
            if (isset($frame['class']) && $frame['class'] === self::class) {
                continue;
            }
            if (isset($frame['class']) && $frame['class'] === Database::class) {
                continue;
            }

            $location = '';
            if (isset($frame['file'])) {
                // Make path relative to project root
                $file = str_replace(dirname(__DIR__, 2) . '/', '', $frame['file']);
                $location = $file . ':' . ($frame['line'] ?? '?');
            }

            $call = '';
            if (isset($frame['class'])) {
                $call = $frame['class'] . $frame['type'] . $frame['function'] . '()';
            } elseif (isset($frame['function'])) {
                $call = $frame['function'] . '()';
            }

            if ($location || $call) {
                $simplified[] = trim("$location $call");
            }

            // Only keep first 3 relevant frames
            if (count($simplified) >= 3) {
                break;
            }
        }

        return $simplified;
    }

    /**
     * Get all tracked queries
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get query count
     */
    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Get total query time in milliseconds
     */
    public function getTotalTime(): float
    {
        return $this->totalTime * 1000;
    }

    /**
     * Get the underlying database instance
     */
    public function getDatabase(): Database
    {
        return $this->db;
    }
}
