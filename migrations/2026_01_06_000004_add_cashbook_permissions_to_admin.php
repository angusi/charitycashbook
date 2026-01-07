<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

/**
 * Migration: Add cashbook permissions to admin role
 */

return [
    'up' => function (Database $db): void {
        // Get all admin roles
        $adminRoles = $db->query("SELECT id, permissions FROM charity_roles WHERE name = 'admin'");
        
        foreach ($adminRoles as $role) {
            $permissions = json_decode($role['permissions'], true) ?? [];
            
            // Add cashbook permissions if not present
            $newPerms = ['cashbooks.view', 'cashbooks.manage', 'cashbooks.reconcile'];
            foreach ($newPerms as $perm) {
                if (!in_array($perm, $permissions)) {
                    $permissions[] = $perm;
                }
            }
            
            $db->execute(
                "UPDATE charity_roles SET permissions = ? WHERE id = ?",
                [json_encode($permissions), $role['id']]
            );
        }
        
        echo "Added cashbook permissions to admin roles\n";
    },

    'down' => function (Database $db): void {
        // Get all admin roles and remove cashbook permissions
        $adminRoles = $db->query("SELECT id, permissions FROM charity_roles WHERE name = 'admin'");
        
        foreach ($adminRoles as $role) {
            $permissions = json_decode($role['permissions'], true) ?? [];
            $permissions = array_filter($permissions, fn($p) => !str_starts_with($p, 'cashbooks.'));
            
            $db->execute(
                "UPDATE charity_roles SET permissions = ? WHERE id = ?",
                [json_encode(array_values($permissions)), $role['id']]
            );
        }
    },
];
