<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity\Support;

use CharityCashbook\Core\DatabaseInterface;
use PDO;

/**
 * Minimal in-memory stand-in for the database, answering the queries TransactionService
 * makes from fixtures and recording every write. Unknown queries fail loudly.
 */
class FakeLedgerDb implements DatabaseInterface
{
    /** @var array<int, int[]> charity id => fund ids */
    public array $funds = [1 => [10, 11, 12]];
    /** @var array<int, int[]> charity id => cash book ids */
    public array $cashBooks = [1 => [20, 21]];
    public array $charity = ['giftaid_eligible_from_date' => null, 'books_locked_until' => null];

    /** Existing transaction returned by get(), with its entries */
    public ?array $transaction = null;
    public array $fundEntries = [];
    public array $categoryEntries = [];
    public array $cashbookEntries = [];

    /** @var array<int, array{table: string, data: array}> */
    public array $inserts = [];
    /** @var array<int, array{table: string, data: array, where: string, params: array}> */
    public array $updates = [];
    /** @var array<int, array{sql: string, params: array}> */
    public array $executes = [];
    /** @var array<int, array{sql: string, params: array}> */
    public array $queries = [];

    public bool $committed = false;
    public bool $rolledBack = false;
    public ?\Throwable $failOnInsert = null;
    private int $nextId = 100;
    private bool $inTransaction = false;

    public function getConnection(): PDO
    {
        throw new \LogicException('No connection in tests');
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'FROM transaction_fund_entries tfe') && str_contains($sql, 'JOIN funds f')) {
            return $this->fundEntries;
        }
        if (str_contains($sql, 'FROM transaction_category_entries tce') && str_contains($sql, 'JOIN operational_categories oc')
            && str_contains($sql, 'WHERE tce.transaction_id = ?')) {
            return $this->categoryEntries;
        }
        if (str_contains($sql, 'FROM transaction_cashbook_entries tce') && str_contains($sql, 'JOIN cash_books cb')) {
            return $this->cashbookEntries;
        }

        return [];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'FROM charities WHERE id = ?')) {
            return $this->charity;
        }
        if (str_contains($sql, 'FROM transactions t') && str_contains($sql, 'WHERE t.id = ? AND t.charity_id = ?')) {
            return ($this->transaction && (int) $this->transaction['charity_id'] === (int) $params[1]) ? $this->transaction : null;
        }

        throw new \LogicException("Unexpected queryOne: $sql");
    }

    public function queryValue(string $sql, array $params = []): mixed
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        if (preg_match('/FROM (funds|cash_books) WHERE charity_id = \? AND id IN/', $sql, $m)) {
            $charityId = (int) array_shift($params);
            $owned = $m[1] === 'funds' ? ($this->funds[$charityId] ?? []) : ($this->cashBooks[$charityId] ?? []);
            return count(array_intersect(array_map('intval', $params), $owned));
        }
        if (str_contains($sql, 'SELECT COUNT(*) FROM transactions t WHERE')) {
            return 0;
        }

        throw new \LogicException("Unexpected queryValue: $sql");
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executes[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }

    public function lastInsertId(): int
    {
        return $this->nextId;
    }

    public function beginTransaction(): void
    {
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        $this->committed = true;
    }

    public function rollback(): void
    {
        $this->inTransaction = false;
        $this->rolledBack = true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function insert(string $table, array $data): int
    {
        if ($this->failOnInsert && $table === 'transaction_fund_entries') {
            throw $this->failOnInsert;
        }
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return ++$this->nextId;
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where, 'params' => $whereParams];
        return 1;
    }

    /**
     * Rows inserted into a table
     */
    public function inserted(string $table): array
    {
        return array_values(array_map(
            fn($i) => $i['data'],
            array_filter($this->inserts, fn($i) => $i['table'] === $table)
        ));
    }

    /**
     * Set up an existing transaction for update/delete tests
     */
    public function withTransaction(array $overrides = [], array $lines = null, array $cashbook = []): self
    {
        $this->transaction = array_merge([
            'id' => 5,
            'charity_id' => 1,
            'transaction_date' => '2026-05-01',
            'transaction_type' => 'receipt',
            'amount' => '100.00',
            'description' => 'Donation',
            'reference' => null,
            'donor_id' => null,
            'has_oneoff_declaration' => 0,
            'giftaid_status' => 'not_applicable',
            'giftaid_amount' => null,
            'giftaid_eligibility_override' => null,
            'giftaid_claim_id' => null,
            'giftaid_claim_status' => null,
            'is_deleted' => 0,
            'operational_category_id' => 1,
            'allocation_needs_review' => 0,
        ], $overrides);

        $this->categoryEntries = $lines ?? [
            ['operational_category_id' => 1, 'fund_id' => 10, 'amount' => '100.00', 'category_name' => 'Donations', 'reporting_category_name' => 'Donations', 'fund_name' => 'General'],
        ];
        $this->fundEntries = [['fund_id' => 10, 'amount' => '100.00', 'fund_name' => 'General', 'fund_type' => 'unrestricted']];
        $this->cashbookEntries = $cashbook;

        return $this;
    }
}
