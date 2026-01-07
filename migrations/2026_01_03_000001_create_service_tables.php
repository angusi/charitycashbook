<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // OAuth provider configuration (service-level)
        $db->execute("
            CREATE TABLE oauth_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_type ENUM('google', 'microsoft', 'oidc') NOT NULL,
                name VARCHAR(100) NOT NULL,
                client_id VARCHAR(255) NOT NULL,
                client_secret_encrypted TEXT NOT NULL,
                discovery_url VARCHAR(500) NULL,
                tenant_id VARCHAR(100) NULL,
                is_enabled BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Internal user identities
        $db->execute("
            CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                display_name VARCHAR(200) NOT NULL,
                primary_email VARCHAR(255) NOT NULL,
                is_super_admin BOOLEAN DEFAULT FALSE,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY uk_primary_email (primary_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // OAuth identities linked to users
        $db->execute("
            CREATE TABLE user_identities (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                oauth_provider_id INT UNSIGNED NOT NULL,
                oauth_subject VARCHAR(255) NOT NULL,
                oauth_email VARCHAR(255) NOT NULL,
                is_verified BOOLEAN DEFAULT FALSE,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (oauth_provider_id) REFERENCES oauth_providers(id),
                UNIQUE KEY uk_provider_subject (oauth_provider_id, oauth_subject),
                KEY idx_oauth_email (oauth_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Pending identity links awaiting confirmation
        $db->execute("
            CREATE TABLE pending_identity_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                existing_user_id INT UNSIGNED NOT NULL,
                new_oauth_provider_id INT UNSIGNED NOT NULL,
                new_oauth_subject VARCHAR(255) NOT NULL,
                new_oauth_email VARCHAR(255) NOT NULL,
                confirmation_token VARCHAR(100) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (existing_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (new_oauth_provider_id) REFERENCES oauth_providers(id),
                UNIQUE KEY uk_token (confirmation_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Charities (tenants)
        $db->execute("
            CREATE TABLE charities (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                registration_number VARCHAR(50) NULL,
                year_end_day TINYINT UNSIGNED NOT NULL DEFAULT 31,
                year_end_month TINYINT UNSIGNED NOT NULL DEFAULT 3,
                session_timeout_hours INT UNSIGNED DEFAULT 24,
                allow_account_linking BOOLEAN DEFAULT TRUE,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Which OAuth providers a charity allows
        $db->execute("
            CREATE TABLE charity_oauth_providers (
                charity_id INT UNSIGNED NOT NULL,
                oauth_provider_id INT UNSIGNED NOT NULL,
                
                PRIMARY KEY (charity_id, oauth_provider_id),
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (oauth_provider_id) REFERENCES oauth_providers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Allowed email domains/addresses per charity
        $db->execute("
            CREATE TABLE charity_allowed_emails (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                email_pattern VARCHAR(255) NOT NULL,
                pattern_type ENUM('domain', 'email') NOT NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                KEY idx_charity (charity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // User membership in charities
        $db->execute("
            CREATE TABLE charity_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                role ENUM('admin', 'standard', 'readonly') NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                last_authenticated_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY uk_charity_user (charity_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Database-backed session storage
        $db->execute("
            CREATE TABLE sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token_hash VARCHAR(128) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                charity_sessions JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY uk_token_hash (token_hash),
                KEY idx_user (user_id),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS sessions");
        $db->execute("DROP TABLE IF EXISTS charity_users");
        $db->execute("DROP TABLE IF EXISTS charity_allowed_emails");
        $db->execute("DROP TABLE IF EXISTS charity_oauth_providers");
        $db->execute("DROP TABLE IF EXISTS charities");
        $db->execute("DROP TABLE IF EXISTS pending_identity_links");
        $db->execute("DROP TABLE IF EXISTS user_identities");
        $db->execute("DROP TABLE IF EXISTS users");
        $db->execute("DROP TABLE IF EXISTS oauth_providers");
    },
];
