<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Add giftaid_amount to transactions
        // NULL means not confirmed - requires review before claiming
        // When set, this is the confirmed claimable donation amount
        $db->execute("
            ALTER TABLE transactions 
            ADD COLUMN giftaid_amount DECIMAL(15,2) NULL AFTER giftaid_status
        ");

        // Create corrections table for adjusting previously submitted claims
        $db->execute("
            CREATE TABLE giftaid_corrections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                charity_id INT UNSIGNED NOT NULL,
                original_transaction_id INT UNSIGNED NOT NULL,
                original_claim_id INT UNSIGNED NOT NULL,
                original_amount DECIMAL(15,2) NOT NULL COMMENT 'Donation amount originally claimed',
                corrected_amount DECIMAL(15,2) NOT NULL COMMENT 'What the donation amount should have been',
                adjustment DECIMAL(15,2) NOT NULL COMMENT 'corrected - original (negative = overclaim)',
                reason VARCHAR(500) NOT NULL,
                applied_to_claim_id INT UNSIGNED NULL COMMENT 'Claim where this correction was applied',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by_user_id INT UNSIGNED NOT NULL,
                
                FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
                FOREIGN KEY (original_transaction_id) REFERENCES transactions(id),
                FOREIGN KEY (original_claim_id) REFERENCES giftaid_claims(id),
                FOREIGN KEY (applied_to_claim_id) REFERENCES giftaid_claims(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id),
                
                INDEX idx_charity (charity_id),
                INDEX idx_original_transaction (original_transaction_id),
                INDEX idx_pending (charity_id, applied_to_claim_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // For existing claimed transactions, set giftaid_amount to the full amount
        // (they were claimed at full amount under the old system)
        $db->execute("
            UPDATE transactions 
            SET giftaid_amount = amount 
            WHERE giftaid_status = 'claimed'
        ");

        // For existing eligible/gasds transactions that haven't been claimed,
        // leave giftaid_amount as NULL - they'll need review under new system
        // This is intentional: we don't want to auto-confirm amounts
    },

    'down' => function (DatabaseInterface $db) {
        $db->execute("DROP TABLE IF EXISTS giftaid_corrections");
        $db->execute("ALTER TABLE transactions DROP COLUMN giftaid_amount");
    }
];
