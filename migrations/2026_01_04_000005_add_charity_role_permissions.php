<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add table to store custom role permissions per charity
 * 
 * If a charity has no entry, the system defaults from PermissionService are used.
 * This allows each charity to customize what each role can do.
 */

return [
    'up' => function (Database $db): void {
        $db->execute("
            CREATE TABLE charity_role_permissions (
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
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS charity_role_permissions");
    },
];
