<?php

declare(strict_types=1);

/**
 * Migration: Add transaction_category_entries table for multi-category support
 *
 * This migration introduces a many-to-many relationship between transactions and
 * operational categories, allowing a single transaction to be split across multiple
 * categories (e.g., a payment covering both "Office Supplies" and "Postage").
 *
 * Constraint: All categories in a split must be of the same type (all receipt OR all payment).
 * This is enforced at the application level, not database level.
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Create transaction_category_entries table
        $db->execute("
            CREATE TABLE IF NOT EXISTS transaction_category_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT UNSIGNED NOT NULL,
                operational_category_id INT UNSIGNED NOT NULL,
                amount DECIMAL(15,2) NOT NULL COMMENT 'Positive for receipts, negative for payments',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
                FOREIGN KEY (operational_category_id) REFERENCES operational_categories(id),
                INDEX idx_transaction (transaction_id),
                INDEX idx_category (operational_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migrate existing data: create category entries from transactions.operational_category_id
        $db->execute("
            INSERT INTO transaction_category_entries (transaction_id, operational_category_id, amount)
            SELECT
                id,
                operational_category_id,
                CASE WHEN transaction_type = 'receipt' THEN amount ELSE -amount END
            FROM transactions
            WHERE operational_category_id IS NOT NULL
        ");

        // Note: We keep the old column (operational_category_id) for now as a rollback safety net.
        // It can be removed in a future migration after the new system is validated.
    },

    'down' => function (Database $db): void {
        // Note: Rolling back will lose any split transaction data that was created
        // after the migration. The original operational_category_id column is preserved,
        // so single-entry transactions will still work.

        $db->execute("DROP TABLE IF EXISTS transaction_category_entries");
    },
];
