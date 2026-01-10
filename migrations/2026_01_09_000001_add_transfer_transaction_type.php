<?php

declare(strict_types=1);

use CharityCashbook\Core\DatabaseInterface;

return [
    'up' => function (DatabaseInterface $db) {
        // Modify transaction_type enum to add 'transfer'
        $db->execute("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('receipt', 'payment', 'transfer') NOT NULL");
    },

    'down' => function (DatabaseInterface $db) {
        // Revert back to original enum (only if no transfers exist)
        $db->execute("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('receipt', 'payment') NOT NULL");
    }
];
