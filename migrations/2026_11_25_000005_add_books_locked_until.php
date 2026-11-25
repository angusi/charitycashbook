<?php

declare(strict_types=1);

/**
 * Migration: year-end close.
 *
 * books_locked_until is the last day of the most recent closed financial year.
 * Transactions dated on or before it (and opening balances that affect it) cannot change.
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $db->execute("ALTER TABLE charities ADD COLUMN books_locked_until DATE NULL");
    },

    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE charities DROP COLUMN books_locked_until");
    },
];
