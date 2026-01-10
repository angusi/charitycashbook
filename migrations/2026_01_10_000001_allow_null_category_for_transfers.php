<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Make operational_category_id nullable to support transfers (which don't have categories)
        $db->execute("ALTER TABLE transactions MODIFY COLUMN operational_category_id INT UNSIGNED NULL");
    },

    'down' => function (DatabaseInterface $db) {
        // Note: Cannot safely revert if transfers exist with NULL operational_category_id
        // Would need to delete transfers first
        $db->execute("ALTER TABLE transactions MODIFY COLUMN operational_category_id INT UNSIGNED NOT NULL");
    }
];
