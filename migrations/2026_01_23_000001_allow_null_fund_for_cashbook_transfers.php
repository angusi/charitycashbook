<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Make fund_id nullable to support cashbook-only transfers (which don't move between funds)
        $db->execute("ALTER TABLE transactions MODIFY COLUMN fund_id INT UNSIGNED NULL");
    },

    'down' => function (DatabaseInterface $db) {
        // Note: Cannot safely revert if cashbook-only transfers exist with NULL fund_id
        // Would need to delete those transfers first
        $db->execute("ALTER TABLE transactions MODIFY COLUMN fund_id INT UNSIGNED NOT NULL");
    }
];
