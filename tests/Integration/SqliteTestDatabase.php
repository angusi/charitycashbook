<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use PDO;
use CharityCashbook\Core\DatabaseInterface;

/**
 * SQLite implementation of DatabaseInterface for integration testing.
 * 
 * Uses an in-memory SQLite database with MySQL compatibility functions.
 */
class SqliteTestDatabase implements DatabaseInterface
{
    private PDO $pdo;
    
    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        
        // Register MySQL compatibility functions
        $this->registerCustomFunctions();
        
        // Create schema
        $this->createSchema();
    }
    
    /**
     * Register SQLite user-defined functions to mimic MySQL functions
     */
    private function registerCustomFunctions(): void
    {
        // NOW() -> datetime('now')
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        
        // CURDATE() -> date('now')
        $this->pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
        
        // Note: DATE_SUB, YEAR(), MONTH() etc. will need SQL rewriting or test-specific handling
    }
    
    /**
     * Create the test database schema
     */
    private function createSchema(): void
    {
        // Users table
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                display_name TEXT NOT NULL,
                primary_email TEXT NOT NULL UNIQUE,
                is_super_admin INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Charities table
        $this->pdo->exec("
            CREATE TABLE charities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                registration_number TEXT,
                year_end_day INTEGER NOT NULL DEFAULT 31,
                year_end_month INTEGER NOT NULL DEFAULT 3,
                address_line1 TEXT,
                address_line2 TEXT,
                city TEXT,
                county TEXT,
                postcode TEXT,
                country TEXT DEFAULT 'United Kingdom',
                session_timeout_hours INTEGER DEFAULT 24,
                allow_account_linking INTEGER DEFAULT 1,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Charity users
        $this->pdo->exec("
            CREATE TABLE charity_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT 'standard',
                custom_role_id INTEGER,
                display_name TEXT,
                is_active INTEGER DEFAULT 1,
                last_authenticated_at TEXT,
                approved_by_user_id INTEGER,
                approved_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (charity_id, user_id)
            )
        ");
        
        // Custom roles
        $this->pdo->exec("
            CREATE TABLE charity_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                colour TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                UNIQUE (charity_id, name)
            )
        ");
        
        // Role permissions
        $this->pdo->exec("
            CREATE TABLE charity_role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL,
                permission TEXT NOT NULL,
                FOREIGN KEY (role_id) REFERENCES charity_roles(id) ON DELETE CASCADE,
                UNIQUE (role_id, permission)
            )
        ");
        
        // Charity modules
        $this->pdo->exec("
            CREATE TABLE charity_modules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                module TEXT NOT NULL,
                is_enabled INTEGER DEFAULT 1,
                enabled_at TEXT,
                enabled_by_user_id INTEGER,
                disabled_at TEXT,
                disabled_by_user_id INTEGER,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                UNIQUE (charity_id, module)
            )
        ");
        
        // Funds
        $this->pdo->exec("
            CREATE TABLE funds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                fund_type TEXT NOT NULL DEFAULT 'unrestricted',
                is_default INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                display_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Cash books
        $this->pdo->exec("
            CREATE TABLE cash_books (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                book_type TEXT NOT NULL DEFAULT 'general',
                is_default INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                display_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Categories
        $this->pdo->exec("
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                parent_id INTEGER,
                description TEXT,
                is_active INTEGER DEFAULT 1,
                display_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (parent_id) REFERENCES categories(id)
            )
        ");
        
        // Transactions
        $this->pdo->exec("
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                cash_book_id INTEGER NOT NULL,
                fund_id INTEGER NOT NULL,
                category_id INTEGER,
                type TEXT NOT NULL,
                amount REAL NOT NULL,
                date TEXT NOT NULL,
                description TEXT,
                reference TEXT,
                payee TEXT,
                donor_id INTEGER,
                is_reconciled INTEGER DEFAULT 0,
                reconciled_at TEXT,
                is_deleted INTEGER DEFAULT 0,
                deleted_at TEXT,
                deleted_by_user_id INTEGER,
                created_by_user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (cash_book_id) REFERENCES cash_books(id),
                FOREIGN KEY (fund_id) REFERENCES funds(id),
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        ");
        
        // Donors
        $this->pdo->exec("
            CREATE TABLE donors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                title TEXT,
                first_name TEXT,
                last_name TEXT,
                organisation_name TEXT,
                email TEXT,
                phone TEXT,
                address_line1 TEXT,
                address_line2 TEXT,
                city TEXT,
                county TEXT,
                postcode TEXT,
                country TEXT DEFAULT 'United Kingdom',
                is_giftaid_eligible INTEGER DEFAULT 0,
                giftaid_declaration_date TEXT,
                notes TEXT,
                member_id INTEGER,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Members
        $this->pdo->exec("
            CREATE TABLE members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                member_number TEXT,
                title TEXT,
                first_name TEXT,
                last_name TEXT,
                full_name TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                address_line1 TEXT,
                address_line2 TEXT,
                city TEXT,
                county TEXT,
                postcode TEXT,
                country TEXT DEFAULT 'United Kingdom',
                join_date TEXT,
                membership_type TEXT DEFAULT 'standard',
                is_trustee INTEGER DEFAULT 0,
                trustee_start_date TEXT,
                trustee_end_date TEXT,
                trustee_role TEXT,
                donor_id INTEGER,
                notes TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (donor_id) REFERENCES donors(id)
            )
        ");
        
        // Member trustee roles (history)
        $this->pdo->exec("
            CREATE TABLE member_trustee_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                charity_id INTEGER NOT NULL,
                role TEXT,
                start_date TEXT NOT NULL,
                end_date TEXT,
                notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Member dues
        $this->pdo->exec("
            CREATE TABLE member_dues (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                charity_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                paid_date TEXT,
                paid_amount REAL,
                transaction_id INTEGER,
                waived_date TEXT,
                waived_by_user_id INTEGER,
                waived_reason TEXT,
                notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Gift Aid claims
        $this->pdo->exec("
            CREATE TABLE giftaid_claims (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER NOT NULL,
                claim_reference TEXT,
                claim_period_start TEXT NOT NULL,
                claim_period_end TEXT NOT NULL,
                total_donations REAL NOT NULL DEFAULT 0,
                total_giftaid REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'draft',
                submitted_at TEXT,
                created_by_user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");
        
        // Gift Aid claim donations
        $this->pdo->exec("
            CREATE TABLE giftaid_claim_donations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                claim_id INTEGER NOT NULL,
                transaction_id INTEGER NOT NULL,
                donor_id INTEGER NOT NULL,
                donation_amount REAL NOT NULL,
                giftaid_amount REAL NOT NULL,
                FOREIGN KEY (claim_id) REFERENCES giftaid_claims(id) ON DELETE CASCADE,
                FOREIGN KEY (transaction_id) REFERENCES transactions(id),
                FOREIGN KEY (donor_id) REFERENCES donors(id)
            )
        ");
        
        // Audit log
        $this->pdo->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charity_id INTEGER,
                user_id INTEGER,
                action TEXT NOT NULL,
                entity_type TEXT,
                entity_id INTEGER,
                old_values TEXT,
                new_values TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Sessions (minimal for testing)
        $this->pdo->exec("
            CREATE TABLE sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                charity_sessions TEXT NOT NULL DEFAULT '{}',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_active_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }
    
    public function getConnection(): PDO
    {
        return $this->pdo;
    }
    
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($this->adaptSql($sql));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($this->adaptSql($sql));
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    public function queryValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($this->adaptSql($sql));
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
    
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($this->adaptSql($sql));
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
    
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }
    
    public function commit(): void
    {
        $this->pdo->commit();
    }
    
    public function rollback(): void
    {
        $this->pdo->rollBack();
    }
    
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
    
    public function insert(string $table, array $data): int
    {
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
    
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
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
    
    /**
     * Adapt MySQL SQL to SQLite SQL where possible
     */
    private function adaptSql(string $sql): string
    {
        // CURDATE() and NOW() are handled by custom functions registered above
        
        // Handle DATE_SUB with INTERVAL - convert to SQLite datetime()
        // DATE_SUB(NOW(), INTERVAL ? DAY) -> datetime('now', '-' || ? || ' days')
        // This is complex to do with parameterized queries, so we leave it for now
        // Tests should avoid these queries or use date values directly
        
        return $sql;
    }
}
