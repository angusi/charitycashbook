<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Add fields for generic OIDC provider configuration
        $db->execute("
            ALTER TABLE oauth_providers
            ADD COLUMN authorization_endpoint VARCHAR(500) NULL AFTER discovery_url,
            ADD COLUMN token_endpoint VARCHAR(500) NULL AFTER authorization_endpoint,
            ADD COLUMN userinfo_endpoint VARCHAR(500) NULL AFTER token_endpoint,
            ADD COLUMN scopes VARCHAR(500) NULL AFTER userinfo_endpoint
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("
            ALTER TABLE oauth_providers
            DROP COLUMN authorization_endpoint,
            DROP COLUMN token_endpoint,
            DROP COLUMN userinfo_endpoint,
            DROP COLUMN scopes
        ");
    },
];
