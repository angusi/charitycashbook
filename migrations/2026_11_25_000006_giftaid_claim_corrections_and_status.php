<?php

declare(strict_types=1);

/**
 * Migration: Gift Aid claim fixes.
 *
 * - giftaid_claims.underclaim_adjustment / overclaim_adjustment: corrections to earlier
 *   claims that are applied to this claim (donation amounts, not tax).
 * - giftaid_claim_items.original_status: the status a transaction had before it was
 *   claimed, so un-submitting a claim restores it exactly.
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $db->execute("
            ALTER TABLE giftaid_claims
            ADD COLUMN underclaim_adjustment DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER gasds_giftaid,
            ADD COLUMN overclaim_adjustment DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER underclaim_adjustment
        ");

        $db->execute("
            ALTER TABLE giftaid_claim_items
            ADD COLUMN original_status ENUM('eligible', 'gasds') NULL
        ");

        // Items in draft claims still carry their pre-claim status
        $db->execute("
            UPDATE giftaid_claim_items gci
            JOIN transactions t ON t.id = gci.transaction_id
            SET gci.original_status = t.giftaid_status
            WHERE t.giftaid_status IN ('eligible', 'gasds')
        ");
    },

    'down' => function (Database $db): void {
        $db->execute("ALTER TABLE giftaid_claim_items DROP COLUMN original_status");
        $db->execute("ALTER TABLE giftaid_claims DROP COLUMN underclaim_adjustment, DROP COLUMN overclaim_adjustment");
    },
];
