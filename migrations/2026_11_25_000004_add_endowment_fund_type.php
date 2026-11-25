<?php

declare(strict_types=1);

/**
 * Migration: allow endowment funds (the UI already offered this type).
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Before this migration, non-strict MySQL stored '' when 'endowment' was chosen.
        // Those rows cannot be identified reliably, so list them for the charity to review.
        $blank = $db->query("SELECT id, charity_id, name FROM funds WHERE fund_type = ''");
        foreach ($blank as $fund) {
            echo "  Fund #{$fund['id']} ({$fund['name']}, charity {$fund['charity_id']}) has no type - set it to unrestricted; review whether it should be endowment\n";
        }
        $db->execute("UPDATE funds SET fund_type = 'unrestricted' WHERE fund_type = ''");

        $db->execute("
            ALTER TABLE funds
            MODIFY COLUMN fund_type ENUM('unrestricted', 'restricted', 'endowment') NOT NULL DEFAULT 'unrestricted'
        ");
    },

    'down' => function (Database $db): void {
        $count = (int) $db->queryValue("SELECT COUNT(*) FROM funds WHERE fund_type = 'endowment'");
        if ($count > 0) {
            throw new \RuntimeException("$count endowment fund(s) exist; change their type before rolling back");
        }
        $db->execute("
            ALTER TABLE funds
            MODIFY COLUMN fund_type ENUM('unrestricted', 'restricted') NOT NULL DEFAULT 'unrestricted'
        ");
    },
];
