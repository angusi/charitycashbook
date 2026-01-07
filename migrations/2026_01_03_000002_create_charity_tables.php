<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Funds within a charity
        $db->execute("
            CREATE TABLE funds (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                fund_type ENUM('unrestricted', 'restricted') NOT NULL DEFAULT 'unrestricted',
                opening_balance DECIMAL(15,2) DEFAULT 0.00,
                opening_balance_date DATE NULL,
                display_order INT UNSIGNED DEFAULT 0,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                KEY idx_charity (charity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Reporting categories (appear in published accounts)
        $db->execute("
            CREATE TABLE reporting_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                category_type ENUM('receipt', 'payment') NOT NULL,
                display_order INT UNSIGNED DEFAULT 0,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                KEY idx_charity_type (charity_id, category_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Operational categories (day-to-day use, map to reporting categories)
        $db->execute("
            CREATE TABLE operational_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                reporting_category_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                giftaid_eligible_default BOOLEAN DEFAULT FALSE,
                display_order INT UNSIGNED DEFAULT 0,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (reporting_category_id) REFERENCES reporting_categories(id),
                KEY idx_charity (charity_id),
                KEY idx_reporting_category (reporting_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Donors
        $db->execute("
            CREATE TABLE donors (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                name VARCHAR(200) NOT NULL,
                address_line1 VARCHAR(200) NULL,
                address_line2 VARCHAR(200) NULL,
                address_city VARCHAR(100) NULL,
                address_postcode VARCHAR(20) NULL,
                email VARCHAR(255) NULL,
                notes TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                KEY idx_charity (charity_id),
                KEY idx_name (charity_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Gift Aid declarations (ongoing declarations only)
        $db->execute("
            CREATE TABLE declarations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                donor_id INT UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                covers_past_donations BOOLEAN DEFAULT FALSE,
                covers_past_from_date DATE NULL,
                withdrawn_date DATE NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                KEY idx_donor (donor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Gift Aid claims
        $db->execute("
            CREATE TABLE giftaid_claims (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                claim_period_start DATE NOT NULL,
                claim_period_end DATE NOT NULL,
                total_donations DECIMAL(15,2) NOT NULL,
                total_giftaid DECIMAL(15,2) NOT NULL,
                gasds_donations DECIMAL(15,2) DEFAULT 0.00,
                gasds_giftaid DECIMAL(15,2) DEFAULT 0.00,
                status ENUM('draft', 'submitted', 'cancelled') NOT NULL DEFAULT 'draft',
                submitted_at TIMESTAMP NULL,
                hmrc_reference VARCHAR(100) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NOT NULL,
                submitted_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id),
                FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                KEY idx_charity (charity_id),
                KEY idx_status (charity_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Transactions (receipts and payments)
        $db->execute("
            CREATE TABLE transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                transaction_date DATE NOT NULL,
                transaction_type ENUM('receipt', 'payment') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                description VARCHAR(500) NOT NULL,
                reference VARCHAR(100) NULL,
                fund_id INT UNSIGNED NOT NULL,
                operational_category_id INT UNSIGNED NOT NULL,
                donor_id INT UNSIGNED NULL,
                has_oneoff_declaration BOOLEAN DEFAULT FALSE,
                giftaid_status ENUM('not_applicable', 'not_eligible', 'eligible', 'gasds', 'claimed') 
                    DEFAULT 'not_applicable',
                giftaid_claim_id INT UNSIGNED NULL,
                is_deleted BOOLEAN DEFAULT FALSE,
                deleted_at TIMESTAMP NULL,
                deleted_by_user_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NOT NULL,
                updated_by_user_id INT UNSIGNED NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (fund_id) REFERENCES funds(id),
                FOREIGN KEY (operational_category_id) REFERENCES operational_categories(id),
                FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL,
                FOREIGN KEY (giftaid_claim_id) REFERENCES giftaid_claims(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id),
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                
                KEY idx_charity_date (charity_id, transaction_date),
                KEY idx_charity_type (charity_id, transaction_type),
                KEY idx_fund (fund_id),
                KEY idx_category (operational_category_id),
                KEY idx_donor (donor_id),
                KEY idx_giftaid (charity_id, giftaid_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Link table for transactions in a claim
        $db->execute("
            CREATE TABLE giftaid_claim_items (
                claim_id INT UNSIGNED NOT NULL,
                transaction_id INT UNSIGNED NOT NULL,
                
                PRIMARY KEY (claim_id, transaction_id),
                FOREIGN KEY (claim_id) REFERENCES giftaid_claims(id) ON DELETE CASCADE,
                FOREIGN KEY (transaction_id) REFERENCES transactions(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Transaction audit trail
        $db->execute("
            CREATE TABLE transaction_audit (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT UNSIGNED NOT NULL,
                action ENUM('created', 'updated', 'deleted', 'restored') NOT NULL,
                changed_fields JSON NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                KEY idx_transaction (transaction_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS transaction_audit");
        $db->execute("DROP TABLE IF EXISTS giftaid_claim_items");
        $db->execute("DROP TABLE IF EXISTS transactions");
        $db->execute("DROP TABLE IF EXISTS giftaid_claims");
        $db->execute("DROP TABLE IF EXISTS declarations");
        $db->execute("DROP TABLE IF EXISTS donors");
        $db->execute("DROP TABLE IF EXISTS operational_categories");
        $db->execute("DROP TABLE IF EXISTS reporting_categories");
        $db->execute("DROP TABLE IF EXISTS funds");
    },
];
