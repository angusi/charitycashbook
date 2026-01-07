<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // General audit log
        $db->execute("
            CREATE TABLE audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id INT UNSIGNED NULL,
                details JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                KEY idx_charity (charity_id),
                KEY idx_user (user_id),
                KEY idx_created (created_at),
                KEY idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Login history
        $db->execute("
            CREATE TABLE login_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                oauth_provider_id INT UNSIGNED NULL,
                oauth_email VARCHAR(255) NOT NULL,
                success BOOLEAN NOT NULL,
                failure_reason VARCHAR(200) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                KEY idx_user (user_id),
                KEY idx_email (oauth_email),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Donor access log (PII access tracking)
        $db->execute("
            CREATE TABLE donor_access_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                donor_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                access_type ENUM('view', 'view_address', 'edit', 'export') NOT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                KEY idx_charity (charity_id),
                KEY idx_donor (donor_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS donor_access_log");
        $db->execute("DROP TABLE IF EXISTS login_history");
        $db->execute("DROP TABLE IF EXISTS audit_log");
    },
];
