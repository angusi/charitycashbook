<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Migration: Convert session_timeout_hours to session_timeout_minutes
 *
 * The settings page accepts minutes but was storing hours with lossy conversion.
 * This migration converts the column to store minutes directly for accuracy.
 */

return [
    'up' => function (Database $db): void {
        // Add new column for minutes
        $db->execute("ALTER TABLE charities ADD COLUMN session_timeout_minutes INT UNSIGNED DEFAULT 480");

        // Convert existing hours to minutes (hours * 60)
        $db->execute("UPDATE charities SET session_timeout_minutes = session_timeout_hours * 60");

        // Drop the old column
        $db->execute("ALTER TABLE charities DROP COLUMN session_timeout_hours");

        echo "Converted session_timeout_hours to session_timeout_minutes\n";
    },

    'down' => function (Database $db): void {
        // Add back the hours column
        $db->execute("ALTER TABLE charities ADD COLUMN session_timeout_hours INT UNSIGNED DEFAULT 24");

        // Convert minutes back to hours (minutes / 60, rounded up)
        $db->execute("UPDATE charities SET session_timeout_hours = CEIL(session_timeout_minutes / 60)");

        // Drop the minutes column
        $db->execute("ALTER TABLE charities DROP COLUMN session_timeout_minutes");

        echo "Reverted session_timeout_minutes back to session_timeout_hours\n";
    },
];
