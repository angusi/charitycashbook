<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Simplify member name fields: consolidate title, first_name, last_name into single name field
 */
return [
    'up' => function (Database $db): void {
        // Add the new name column
        $db->execute("ALTER TABLE members ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER donor_id");
        
        // Migrate existing data: combine title + first_name + last_name
        $db->execute("
            UPDATE members 
            SET name = TRIM(CONCAT(
                COALESCE(title, ''), 
                IF(title IS NOT NULL AND title != '', ' ', ''),
                COALESCE(first_name, ''), 
                ' ', 
                COALESCE(last_name, '')
            ))
        ");
        
        // Drop the old columns
        $db->execute("ALTER TABLE members DROP COLUMN title");
        $db->execute("ALTER TABLE members DROP COLUMN first_name");
        $db->execute("ALTER TABLE members DROP COLUMN last_name");
    },
    
    'down' => function (Database $db): void {
        // Add back the old columns
        $db->execute("ALTER TABLE members ADD COLUMN title VARCHAR(20) NULL AFTER donor_id");
        $db->execute("ALTER TABLE members ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER title");
        $db->execute("ALTER TABLE members ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name");
        
        // Best effort to split name back (just put it all in last_name)
        $db->execute("UPDATE members SET last_name = name");
        
        // Drop the name column
        $db->execute("ALTER TABLE members DROP COLUMN name");
    },
];
