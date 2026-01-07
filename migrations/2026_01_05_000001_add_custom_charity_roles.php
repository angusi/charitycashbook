<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add custom roles per charity
 * 
 * Changes:
 * - Create charity_roles table to define roles per charity
 * - Change charity_users.role from ENUM to VARCHAR to allow custom role names
 * - Migrate existing role data
 */

return [
    'up' => function (Database $db): void {
        // Create charity_roles table
        $db->execute("
            CREATE TABLE charity_roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(50) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                permissions JSON NOT NULL,
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                can_be_deleted BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_charity_role_name (charity_id, name),
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");

        // Change charity_users.role from ENUM to VARCHAR
        // First add new column
        $db->execute("
            ALTER TABLE charity_users 
            ADD COLUMN role_name VARCHAR(50) NULL AFTER role
        ");

        // Copy existing role values
        $db->execute("
            UPDATE charity_users SET role_name = role
        ");

        // Drop old column and rename new one
        $db->execute("
            ALTER TABLE charity_users 
            DROP COLUMN role,
            CHANGE COLUMN role_name role VARCHAR(50) NOT NULL DEFAULT 'pending'
        ");

        // Add foreign key (will be enforced at application level since role references charity_roles.name not id)
        // We use name-based reference for simpler queries and human-readable data

        // Create default roles for existing charities
        $charities = $db->query("SELECT id FROM charities");
        foreach ($charities as $charity) {
            $charityId = $charity['id'];
            
            // Insert default roles
            $db->execute("
                INSERT INTO charity_roles (charity_id, name, display_name, description, permissions, is_system, can_be_deleted, sort_order)
                VALUES 
                (?, 'pending', 'Pending Approval', 'Users awaiting approval', '[]', TRUE, FALSE, 0),
                (?, 'readonly', 'Read Only', 'Can view but not edit', ?, FALSE, TRUE, 1),
                (?, 'standard', 'Standard User', 'Can add and edit transactions', ?, FALSE, TRUE, 2),
                (?, 'admin', 'Administrator', 'Full access to all features', ?, TRUE, FALSE, 3)
            ", [
                $charityId,
                $charityId,
                json_encode(['transactions.view', 'donors.view_list', 'funds.view', 'categories.view', 'reports.view']),
                $charityId,
                json_encode(['transactions.view', 'transactions.create', 'transactions.edit', 'donors.view_list', 'donors.view_detail', 'donors.create', 'donors.edit', 'giftaid.view', 'funds.view', 'categories.view', 'reports.view']),
                $charityId,
                json_encode(['transactions.view', 'transactions.create', 'transactions.edit', 'transactions.delete', 'donors.view_list', 'donors.view_detail', 'donors.create', 'donors.edit', 'donors.export', 'giftaid.view', 'giftaid.manage_claims', 'funds.view', 'funds.manage', 'categories.view', 'categories.manage', 'reports.view', 'reports.export', 'settings.view', 'settings.manage', 'users.view', 'users.manage']),
            ]);
        }

        // Drop the old charity_role_permissions table if it exists (we're consolidating into charity_roles)
        $db->execute("DROP TABLE IF EXISTS charity_role_permissions");
    },
    
    'down' => function (Database $db): void {
        // Recreate charity_role_permissions table
        $db->execute("
            CREATE TABLE IF NOT EXISTS charity_role_permissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                role ENUM('readonly', 'standard', 'admin') NOT NULL,
                permissions JSON NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by_user_id INT UNSIGNED NULL,
                UNIQUE KEY uk_charity_role (charity_id, role),
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Change role back to ENUM
        $db->execute("
            ALTER TABLE charity_users 
            ADD COLUMN role_enum ENUM('pending', 'admin', 'standard', 'readonly') NOT NULL DEFAULT 'pending' AFTER role
        ");

        // Copy values back (only standard roles)
        $db->execute("
            UPDATE charity_users 
            SET role_enum = CASE 
                WHEN role IN ('pending', 'admin', 'standard', 'readonly') THEN role 
                ELSE 'standard' 
            END
        ");

        // Drop varchar column and rename enum column
        $db->execute("
            ALTER TABLE charity_users 
            DROP COLUMN role,
            CHANGE COLUMN role_enum role ENUM('pending', 'admin', 'standard', 'readonly') NOT NULL DEFAULT 'pending'
        ");

        // Drop charity_roles table
        $db->execute("DROP TABLE IF EXISTS charity_roles");
    },
];
