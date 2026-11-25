<?php

declare(strict_types=1);

/**
 * Migration: grant cashbooks.view to existing "standard" roles.
 *
 * View permissions are now enforced on GET routes. The default Standard role never
 * listed cashbooks.view, but its users could previously see cash books, so this keeps
 * their existing access.
 */

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        $roles = $db->query("SELECT id, permissions FROM charity_roles WHERE name = 'standard'");
        foreach ($roles as $role) {
            $permissions = json_decode($role['permissions'], true) ?: [];
            if (!in_array('cashbooks.view', $permissions, true)) {
                $permissions[] = 'cashbooks.view';
                $db->execute(
                    "UPDATE charity_roles SET permissions = ? WHERE id = ?",
                    [json_encode(array_values($permissions)), $role['id']]
                );
            }
        }
    },

    'down' => function (Database $db): void {
        $roles = $db->query("SELECT id, permissions FROM charity_roles WHERE name = 'standard'");
        foreach ($roles as $role) {
            $permissions = json_decode($role['permissions'], true) ?: [];
            $permissions = array_values(array_filter($permissions, fn($p) => $p !== 'cashbooks.view'));
            $db->execute(
                "UPDATE charity_roles SET permissions = ? WHERE id = ?",
                [json_encode($permissions), $role['id']]
            );
        }
    },
];
