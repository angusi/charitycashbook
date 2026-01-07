<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add granular permissions system to charity_users
 * 
 * Changes:
 * - Add 'pending' to role enum for new signups awaiting approval
 * - Add permissions JSON column for granular permission control
 * - Existing users keep their role, which acts as a permission template
 */

return [
    'up' => function (Database $db): void {
        // Modify role enum to include 'pending' status
        $db->execute("
            ALTER TABLE charity_users 
            MODIFY COLUMN role ENUM('pending', 'admin', 'standard', 'readonly') NOT NULL DEFAULT 'pending'
        ");

        // Add permissions column for granular control
        // NULL means "use role defaults", otherwise JSON object of permission overrides
        $db->execute("
            ALTER TABLE charity_users 
            ADD COLUMN permissions JSON NULL AFTER role
        ");

        // Add approved_by and approved_at for audit trail
        $db->execute("
            ALTER TABLE charity_users 
            ADD COLUMN approved_by_user_id INT UNSIGNED NULL AFTER is_active,
            ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by_user_id
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charity_users 
            DROP COLUMN approved_at,
            DROP COLUMN approved_by_user_id,
            DROP COLUMN permissions
        ");

        $db->execute("
            ALTER TABLE charity_users 
            MODIFY COLUMN role ENUM('admin', 'standard', 'readonly') NOT NULL
        ");
    },
];
