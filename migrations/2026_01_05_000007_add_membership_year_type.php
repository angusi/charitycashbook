<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Add membership year type setting to charities
 * Options: 'financial' (use charity's financial year), 'calendar' (Jan-Dec), 'anniversary' (per member join date)
 */
return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charities 
            ADD COLUMN membership_year_type ENUM('financial', 'calendar', 'anniversary') 
            NOT NULL DEFAULT 'financial' 
            AFTER year_end_month
        ");
    },
    
    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE charities DROP COLUMN membership_year_type");
    },
];
