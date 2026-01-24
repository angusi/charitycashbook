<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Add giftaid_eligibility_override column
        // NULL = auto-determine from donor/declaration/category
        // 'eligible' = user explicitly marked as Gift Aid eligible
        // 'not_eligible' = user explicitly excluded from Gift Aid
        $db->execute("
            ALTER TABLE transactions 
            ADD COLUMN giftaid_eligibility_override ENUM('eligible', 'not_eligible') NULL 
            AFTER giftaid_amount
        ");

        // Backfill: transactions that are already eligible/claimed/gasds 
        // should have override='eligible' to preserve their status
        $db->execute("
            UPDATE transactions 
            SET giftaid_eligibility_override = 'eligible'
            WHERE giftaid_status IN ('eligible', 'claimed', 'gasds')
            AND giftaid_eligibility_override IS NULL
        ");
    },

    'down' => function (DatabaseInterface $db) {
        $db->execute("
            ALTER TABLE transactions 
            DROP COLUMN giftaid_eligibility_override
        ");
    }
];
