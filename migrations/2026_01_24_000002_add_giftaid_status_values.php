<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Add new status values: excluded, pending_donor, pending_declaration
        $db->execute("
            ALTER TABLE transactions 
            MODIFY COLUMN giftaid_status ENUM(
                'not_applicable', 
                'not_eligible', 
                'eligible', 
                'gasds', 
                'claimed',
                'excluded',
                'pending_donor',
                'pending_declaration'
            ) DEFAULT 'not_applicable'
        ");
    },

    'down' => function (DatabaseInterface $db) {
        // First update any rows with new statuses back to compatible values
        $db->execute("
            UPDATE transactions 
            SET giftaid_status = 'not_eligible'
            WHERE giftaid_status IN ('excluded', 'pending_donor', 'pending_declaration')
        ");
        
        // Then revert the column
        $db->execute("
            ALTER TABLE transactions 
            MODIFY COLUMN giftaid_status ENUM(
                'not_applicable', 
                'not_eligible', 
                'eligible', 
                'gasds', 
                'claimed'
            ) DEFAULT 'not_applicable'
        ");
    }
];
