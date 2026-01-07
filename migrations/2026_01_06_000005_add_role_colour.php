<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Migration: Add colour field to charity_roles
 */

return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charity_roles 
            ADD COLUMN colour VARCHAR(20) NULL DEFAULT NULL AFTER description
        ");
        
        // Set default colours for system roles
        $db->execute("UPDATE charity_roles SET colour = 'red' WHERE name = 'admin'");
        $db->execute("UPDATE charity_roles SET colour = 'blue' WHERE name = 'standard'");
        $db->execute("UPDATE charity_roles SET colour = 'gray' WHERE name = 'readonly'");
        $db->execute("UPDATE charity_roles SET colour = 'yellow' WHERE name = 'pending'");
    },

    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE charity_roles DROP COLUMN colour");
    },
];
