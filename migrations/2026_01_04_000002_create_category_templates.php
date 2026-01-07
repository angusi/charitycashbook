<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Category templates table
        $db->execute("
            CREATE TABLE category_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                display_order INT UNSIGNED DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Template items (reporting categories with their operational categories)
        $db->execute("
            CREATE TABLE category_template_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                template_id INT UNSIGNED NOT NULL,
                category_type ENUM('receipt', 'payment') NOT NULL,
                reporting_name VARCHAR(100) NOT NULL,
                operational_names JSON NOT NULL,
                display_order INT UNSIGNED DEFAULT 0,
                FOREIGN KEY (template_id) REFERENCES category_templates(id) ON DELETE CASCADE,
                KEY idx_template_type (template_id, category_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default templates
        // English (Charity Commission) template
        $db->execute("INSERT INTO category_templates (name, description, display_order) VALUES (?, ?, ?)", 
            ['Charity Commission (England & Wales)', 'Standard SORP categories for charities registered with the Charity Commission', 1]);
        $englishId = $db->lastInsertId();

        // English receipt categories
        $englishReceipts = [
            ['Donations and legacies', ['Regular giving', 'One-off donations', 'Gift Aid reclaimed', 'Legacies']],
            ['Charitable activities', ['Fees for services', 'Contract income']],
            ['Other trading activities', ['Fundraising events', 'Shop income']],
            ['Investments', ['Interest received', 'Dividends']],
            ['Other', ['Miscellaneous income']],
        ];
        foreach ($englishReceipts as $order => $item) {
            $db->execute(
                "INSERT INTO category_template_items (template_id, category_type, reporting_name, operational_names, display_order) VALUES (?, ?, ?, ?, ?)",
                [$englishId, 'receipt', $item[0], json_encode($item[1]), $order]
            );
        }

        // English payment categories
        $englishPayments = [
            ['Raising funds', ['Fundraising costs', 'Shop costs']],
            ['Charitable activities', ['Direct charitable expenditure', 'Support costs']],
            ['Other', ['Governance costs']],
        ];
        foreach ($englishPayments as $order => $item) {
            $db->execute(
                "INSERT INTO category_template_items (template_id, category_type, reporting_name, operational_names, display_order) VALUES (?, ?, ?, ?, ?)",
                [$englishId, 'payment', $item[0], json_encode($item[1]), $order]
            );
        }

        // Scottish (OSCR) template
        $db->execute("INSERT INTO category_templates (name, description, display_order) VALUES (?, ?, ?)",
            ['OSCR (Scotland)', 'Standard categories for charities registered with the Office of the Scottish Charity Regulator', 2]);
        $scottishId = $db->lastInsertId();

        // Scottish receipt categories
        $scottishReceipts = [
            ['Donations and legacies', ['Regular giving', 'One-off donations', 'Gift Aid reclaimed', 'Legacies']],
            ['Grants', ['Grant income']],
            ['Fundraising income', ['Events', 'Sales']],
            ['Investment income', ['Bank interest']],
            ['Other income', ['Miscellaneous income']],
        ];
        foreach ($scottishReceipts as $order => $item) {
            $db->execute(
                "INSERT INTO category_template_items (template_id, category_type, reporting_name, operational_names, display_order) VALUES (?, ?, ?, ?, ?)",
                [$scottishId, 'receipt', $item[0], json_encode($item[1]), $order]
            );
        }

        // Scottish payment categories
        $scottishPayments = [
            ['Charitable activities', ['Programme costs', 'Materials', 'Venue hire', 'Travel']],
            ['Fundraising costs', ['Event costs', 'Publicity']],
            ['Governance costs', ['Independent examination', 'Insurance', 'Bank charges']],
        ];
        foreach ($scottishPayments as $order => $item) {
            $db->execute(
                "INSERT INTO category_template_items (template_id, category_type, reporting_name, operational_names, display_order) VALUES (?, ?, ?, ?, ?)",
                [$scottishId, 'payment', $item[0], json_encode($item[1]), $order]
            );
        }
    },

    'down' => function (Database $db): void {
        $db->execute("DROP TABLE IF EXISTS category_template_items");
        $db->execute("DROP TABLE IF EXISTS category_templates");
    },
];
