<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charities
            ADD COLUMN giftaid_eligible_from_date DATE NULL AFTER hmrc_reference
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("
            ALTER TABLE charities
            DROP COLUMN giftaid_eligible_from_date
        ");
    },
];
