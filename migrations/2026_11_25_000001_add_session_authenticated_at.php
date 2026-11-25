<?php

declare(strict_types=1);

/**
 * Migration: record when a session's user last proved their identity.
 *
 * Service-admin sensitive actions require a recent sign-in. Re-authentication reuses
 * the existing session, so created_at cannot be used for this.
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $db->execute("ALTER TABLE sessions ADD COLUMN authenticated_at TIMESTAMP NULL AFTER last_active_at");
        $db->execute("UPDATE sessions SET authenticated_at = created_at");
    },

    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE sessions DROP COLUMN authenticated_at");
    },
];
