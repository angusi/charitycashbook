<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add per-charity display name for users.
 * Users may be known by different names at different charities.
 * This is synced from OAuth on reauth and can be manually edited.
 */
return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charity_users
            ADD COLUMN display_name VARCHAR(200) NULL AFTER role
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charity_users
            DROP COLUMN display_name
        ");
    },
];
