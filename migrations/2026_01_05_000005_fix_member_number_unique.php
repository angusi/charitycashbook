<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Fix member_number unique constraint to allow multiple NULL values
 * MySQL unique indexes treat NULL as distinct, but empty strings as duplicates
 */
return [
    'up' => function (Database $db): void {
        // First, convert any empty strings to NULL
        $db->execute("UPDATE members SET member_number = NULL WHERE member_number = ''");
        
        // Drop the existing unique key
        $db->execute("ALTER TABLE members DROP INDEX uk_charity_member_number");
        
        // Create a new unique index that only applies to non-null values
        // In MySQL, NULL values are not considered equal, so this allows multiple NULLs
        $db->execute("CREATE UNIQUE INDEX uk_charity_member_number ON members (charity_id, member_number)");
    },
    
    'down' => function (Database $db): void {
        // Restore original constraint (same structure, just recreate)
        $db->execute("ALTER TABLE members DROP INDEX uk_charity_member_number");
        $db->execute("CREATE UNIQUE INDEX uk_charity_member_number ON members (charity_id, member_number)");
    },
];
