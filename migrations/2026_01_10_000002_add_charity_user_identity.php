<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

/**
 * Add identity tracking to charity_users table
 *
 * This enables per-charity OAuth identity verification:
 * - identity_id: Which OAuth identity was used to authenticate for this charity
 * - email: The email from that OAuth identity (for display in Users page)
 */
return [
    'up' => function (DatabaseInterface $db) {
        // Add identity_id column to track which OAuth identity authenticated
        $db->execute("
            ALTER TABLE charity_users
            ADD COLUMN identity_id INT UNSIGNED NULL AFTER user_id,
            ADD COLUMN email VARCHAR(255) NULL AFTER identity_id
        ");

        // Add foreign key to user_identities
        $db->execute("
            ALTER TABLE charity_users
            ADD CONSTRAINT fk_charity_users_identity
            FOREIGN KEY (identity_id) REFERENCES user_identities(id) ON DELETE SET NULL
        ");
    },

    'down' => function (DatabaseInterface $db) {
        $db->execute("ALTER TABLE charity_users DROP FOREIGN KEY fk_charity_users_identity");
        $db->execute("ALTER TABLE charity_users DROP COLUMN email, DROP COLUMN identity_id");
    }
];
