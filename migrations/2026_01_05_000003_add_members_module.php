<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add members module tables
 * 
 * Tracks charity members, trustees, and membership dues/payments
 */

return [
    'up' => function (Database $db): void {
        // Members table - tracks individuals who are members of the charity
        $db->execute("
            CREATE TABLE members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                
                -- Link to donor record (optional - for Gift Aid on membership fees)
                donor_id INT UNSIGNED NULL,
                
                -- Member details
                title VARCHAR(20) NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                
                -- Contact information
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                address_line1 VARCHAR(200) NULL,
                address_line2 VARCHAR(200) NULL,
                address_city VARCHAR(100) NULL,
                address_county VARCHAR(100) NULL,
                address_postcode VARCHAR(20) NULL,
                
                -- Membership details
                member_number VARCHAR(50) NULL,
                membership_type VARCHAR(50) DEFAULT 'standard',
                joined_date DATE NULL,
                left_date DATE NULL,
                
                -- Trustee status
                is_trustee BOOLEAN DEFAULT FALSE,
                trustee_start_date DATE NULL,
                trustee_end_date DATE NULL,
                trustee_role VARCHAR(100) NULL,
                
                -- Status and notes
                is_active BOOLEAN DEFAULT TRUE,
                notes TEXT NULL,
                
                -- Audit fields
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                
                KEY idx_charity (charity_id),
                KEY idx_charity_name (charity_id, last_name, first_name),
                KEY idx_charity_active (charity_id, is_active),
                KEY idx_charity_trustee (charity_id, is_trustee),
                KEY idx_donor (donor_id),
                UNIQUE KEY uk_charity_member_number (charity_id, member_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Membership types - configurable per charity
        $db->execute("
            CREATE TABLE membership_types (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(50) NOT NULL,
                description VARCHAR(255) NULL,
                annual_fee DECIMAL(10,2) NULL,
                is_active BOOLEAN DEFAULT TRUE,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                KEY idx_charity (charity_id),
                UNIQUE KEY uk_charity_name (charity_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Membership dues - tracks what members owe/have paid for each period
        $db->execute("
            CREATE TABLE membership_dues (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                
                -- Period this due covers (typically a year)
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                period_label VARCHAR(50) NULL,
                
                -- Amounts
                amount_due DECIMAL(10,2) NOT NULL,
                amount_paid DECIMAL(10,2) DEFAULT 0.00,
                
                -- Status
                status ENUM('pending', 'partial', 'paid', 'waived', 'cancelled') DEFAULT 'pending',
                
                -- If linked to a transaction (payment)
                transaction_id INT UNSIGNED NULL,
                
                -- Gift Aid eligibility (inherited from donor if linked)
                is_giftaid_eligible BOOLEAN DEFAULT FALSE,
                
                notes TEXT NULL,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                
                KEY idx_charity (charity_id),
                KEY idx_member (member_id),
                KEY idx_period (charity_id, period_start, period_end),
                KEY idx_status (charity_id, status),
                UNIQUE KEY uk_member_period (member_id, period_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add members module to existing charities (disabled by default)
        $charities = $db->query("SELECT id FROM charities");
        foreach ($charities as $charity) {
            $db->insert('charity_modules', [
                'charity_id' => $charity['id'],
                'module' => 'members',
                'is_enabled' => false,
            ]);
        }

        // Create default membership types for existing charities
        foreach ($charities as $charity) {
            $db->insert('membership_types', [
                'charity_id' => $charity['id'],
                'name' => 'Standard',
                'description' => 'Standard membership',
                'sort_order' => 1,
            ]);
        }
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS membership_dues");
        $db->execute("DROP TABLE IF EXISTS membership_types");
        $db->execute("DROP TABLE IF EXISTS members");
        $db->execute("DELETE FROM charity_modules WHERE module = 'members'");
    },
];
