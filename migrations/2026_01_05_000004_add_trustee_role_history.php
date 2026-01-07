<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add trustee role history tracking
 * 
 * Allows tracking multiple trustee roles/periods per member
 */

return [
    'up' => function (Database $db): void {
        // Create trustee roles history table
        $db->execute("
            CREATE TABLE member_trustee_roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                
                role VARCHAR(100) NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                
                notes TEXT NULL,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                
                KEY idx_charity (charity_id),
                KEY idx_member (member_id),
                KEY idx_current (member_id, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migrate existing trustee data from members table
        $db->execute("
            INSERT INTO member_trustee_roles (charity_id, member_id, role, start_date, end_date)
            SELECT charity_id, id, trustee_role, 
                   COALESCE(trustee_start_date, joined_date, created_at),
                   trustee_end_date
            FROM members 
            WHERE is_trustee = TRUE
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS member_trustee_roles");
    },
];
