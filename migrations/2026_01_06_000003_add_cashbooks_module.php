<?php

declare(strict_types=1);

/**
 * Migration: Add cash books module for bank/cash balance tracking and reconciliation
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Cash books table - represents bank accounts, petty cash tins, PayPal, etc.
        $db->execute("
            CREATE TABLE IF NOT EXISTS cash_books (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                book_type ENUM('bank', 'cash', 'online', 'other') NOT NULL DEFAULT 'bank',
                description TEXT NULL,
                account_number VARCHAR(50) NULL,
                sort_code VARCHAR(20) NULL,
                opening_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                opening_balance_date DATE NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                INDEX idx_charity_active (charity_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Reconciliations table - records when balances were checked
        $db->execute("
            CREATE TABLE IF NOT EXISTS cash_book_reconciliations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cash_book_id INT UNSIGNED NOT NULL,
                reconciled_date DATE NOT NULL,
                statement_balance DECIMAL(10,2) NOT NULL,
                expected_balance DECIMAL(10,2) NOT NULL,
                discrepancy DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                reconciled_by_user_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (cash_book_id) REFERENCES cash_books(id) ON DELETE CASCADE,
                FOREIGN KEY (reconciled_by_user_id) REFERENCES users(id),
                INDEX idx_cashbook_date (cash_book_id, reconciled_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add cash_book_id to transactions
        $db->execute("
            ALTER TABLE transactions 
            ADD COLUMN cash_book_id INT UNSIGNED NULL AFTER fund_id,
            ADD COLUMN is_reconciled BOOLEAN NOT NULL DEFAULT FALSE AFTER cash_book_id,
            ADD COLUMN reconciled_at DATETIME NULL AFTER is_reconciled,
            ADD FOREIGN KEY (cash_book_id) REFERENCES cash_books(id) ON DELETE SET NULL
        ");

        // Add index for finding unreconciled transactions
        $db->execute("
            CREATE INDEX idx_transactions_cashbook_reconciled 
            ON transactions(cash_book_id, is_reconciled)
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE transactions DROP FOREIGN KEY transactions_ibfk_cash_book");
        $db->execute("DROP INDEX idx_transactions_cashbook_reconciled ON transactions");
        $db->execute("ALTER TABLE transactions DROP COLUMN cash_book_id, DROP COLUMN is_reconciled, DROP COLUMN reconciled_at");
        $db->execute("DROP TABLE IF EXISTS cash_book_reconciliations");
        $db->execute("DROP TABLE IF EXISTS cash_books");
    },
];
