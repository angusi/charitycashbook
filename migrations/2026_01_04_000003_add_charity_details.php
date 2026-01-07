<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charities
            ADD COLUMN hmrc_reference VARCHAR(50) NULL AFTER registration_number,
            ADD COLUMN address TEXT NULL AFTER hmrc_reference
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charities
            DROP COLUMN hmrc_reference,
            DROP COLUMN address
        ");
    },
];
