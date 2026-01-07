<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add modules system for charities
 * 
 * Allows charities to enable/disable features like Gift Aid, Donors, etc.
 */

return [
    'up' => function (Database $db): void {
        // Create charity_modules table
        $db->execute("
            CREATE TABLE charity_modules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                module VARCHAR(50) NOT NULL,
                is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
                enabled_at TIMESTAMP NULL,
                enabled_by_user_id INT UNSIGNED NULL,
                disabled_at TIMESTAMP NULL,
                disabled_by_user_id INT UNSIGNED NULL,
                settings JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_charity_module (charity_id, module),
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE
            )
        ");

        // Enable all modules for existing charities
        $charities = $db->query("SELECT id FROM charities");
        $modules = ['donors', 'giftaid', 'reports'];
        
        foreach ($charities as $charity) {
            foreach ($modules as $module) {
                $db->insert('charity_modules', [
                    'charity_id' => $charity['id'],
                    'module' => $module,
                    'is_enabled' => true,
                    'enabled_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS charity_modules");
    },
];
