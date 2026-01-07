<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Migration: Sync admin role permissions
 * 
 * Updates all existing admin roles to have the complete set of permissions.
 * This ensures the database reflects what the code grants (admin has all permissions).
 */

return [
    'up' => function (Database $db): void {
        // All permissions that should be granted to admin role
        $allPermissions = [
            'transactions.view',
            'transactions.create',
            'transactions.edit',
            'transactions.delete',
            'donors.view_list',
            'donors.view_detail',
            'donors.create',
            'donors.edit',
            'donors.export',
            'members.view_list',
            'members.view_detail',
            'members.create',
            'members.edit',
            'members.manage_dues',
            'members.manage_trustees',
            'giftaid.view',
            'giftaid.manage_claims',
            'funds.view',
            'funds.manage',
            'categories.view',
            'categories.manage',
            'reports.view',
            'reports.export',
            'settings.view',
            'settings.manage',
            'users.view',
            'users.manage',
            'privacy.export',
            'privacy.delete',
        ];

        $permissionsJson = json_encode($allPermissions);

        // Update all admin roles across all charities
        $count = $db->execute(
            "UPDATE charity_roles SET permissions = ? WHERE name = 'admin'",
            [$permissionsJson]
        );

        echo "Updated $count admin role(s) with full permissions\n";
    },

    'down' => function (Database $db): void {
        // We can't really reverse this - we don't know what the previous permissions were
        // But the code grants admin all permissions anyway, so the DB value doesn't matter
        echo "Note: Admin roles retain full permissions (code grants all permissions to admin role)\n";
    },
];
