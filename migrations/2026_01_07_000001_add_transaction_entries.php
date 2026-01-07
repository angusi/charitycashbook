<?php

declare(strict_types=1);

/**
 * Migration: Add transaction entry tables for multi-fund and multi-cashbook support
 * 
 * This migration introduces a many-to-many relationship between transactions and both
 * funds and cashbooks, allowing:
 * - A single transaction to be split across multiple funds
 * - A single transaction to be split across multiple cashbooks
 * - Inter-fund transfers (fund entries with no cashbook entries)
 * - Complex splits (multiple funds AND multiple cashbooks)
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Create transaction_fund_entries table
        $db->execute("
            CREATE TABLE IF NOT EXISTS transaction_fund_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT UNSIGNED NOT NULL,
                fund_id INT UNSIGNED NOT NULL,
                amount DECIMAL(15,2) NOT NULL COMMENT 'Positive for receipts, negative for payments',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
                FOREIGN KEY (fund_id) REFERENCES funds(id),
                INDEX idx_transaction (transaction_id),
                INDEX idx_fund (fund_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create transaction_cashbook_entries table
        $db->execute("
            CREATE TABLE IF NOT EXISTS transaction_cashbook_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT UNSIGNED NOT NULL,
                cash_book_id INT UNSIGNED NOT NULL,
                amount DECIMAL(15,2) NOT NULL COMMENT 'Positive for money in, negative for money out',
                is_reconciled BOOLEAN NOT NULL DEFAULT FALSE,
                reconciled_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
                FOREIGN KEY (cash_book_id) REFERENCES cash_books(id),
                INDEX idx_transaction (transaction_id),
                INDEX idx_cashbook (cash_book_id),
                INDEX idx_cashbook_reconciled (cash_book_id, is_reconciled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migrate existing data: create fund entries from transactions.fund_id
        $db->execute("
            INSERT INTO transaction_fund_entries (transaction_id, fund_id, amount)
            SELECT 
                id,
                fund_id,
                CASE WHEN transaction_type = 'receipt' THEN amount ELSE -amount END
            FROM transactions
            WHERE fund_id IS NOT NULL
        ");

        // Migrate existing data: create cashbook entries from transactions.cash_book_id
        $db->execute("
            INSERT INTO transaction_cashbook_entries (transaction_id, cash_book_id, amount, is_reconciled, reconciled_at)
            SELECT 
                id,
                cash_book_id,
                CASE WHEN transaction_type = 'receipt' THEN amount ELSE -amount END,
                is_reconciled,
                reconciled_at
            FROM transactions
            WHERE cash_book_id IS NOT NULL
        ");

        // Note: We keep the old columns (fund_id, cash_book_id, is_reconciled, reconciled_at) 
        // for now as a rollback safety net. They can be removed in a future migration
        // after the new system is validated.
    },

    'down' => function (Database $db): void {
        // Note: Rolling back will lose any split transaction data that was created
        // after the migration. The original single fund_id/cash_book_id columns
        // are preserved, so single-entry transactions will still work.
        
        $db->execute("DROP TABLE IF EXISTS transaction_cashbook_entries");
        $db->execute("DROP TABLE IF EXISTS transaction_fund_entries");
    },
];
