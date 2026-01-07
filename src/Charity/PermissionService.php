<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service for managing granular user permissions and custom roles
 */
class PermissionService
{
    private ?DatabaseInterface $db;
    
    /** @var array<int, array<string, array>> Cache of charity roles */
    private array $charityRoleCache = [];

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db;
    }

    /**
     * All available permissions with descriptions
     */
    public const PERMISSIONS = [
        // Transaction permissions
        'transactions.view' => 'View transactions',
        'transactions.create' => 'Create transactions',
        'transactions.edit' => 'Edit transactions',
        'transactions.delete' => 'Delete transactions',
        
        // Donor permissions
        'donors.view_list' => 'View donor list (names only)',
        'donors.view_detail' => 'View donor details including address',
        'donors.create' => 'Create donors',
        'donors.edit' => 'Edit donors',
        'donors.export' => 'Export donor data',
        
        // Member permissions
        'members.view_list' => 'View member list',
        'members.view_detail' => 'View member details',
        'members.create' => 'Create members',
        'members.edit' => 'Edit members',
        'members.manage_dues' => 'Manage membership dues',
        'members.manage_trustees' => 'Manage trustee roles',
        
        // Gift Aid permissions
        'giftaid.view' => 'View Gift Aid information',
        'giftaid.manage_claims' => 'Create and manage Gift Aid claims',
        
        // Cash Book permissions
        'cashbooks.view' => 'View cash books and balances',
        'cashbooks.manage' => 'Create and edit cash books',
        'cashbooks.reconcile' => 'Perform reconciliations',
        
        // Fund permissions
        'funds.view' => 'View funds',
        'funds.manage' => 'Create and edit funds',
        
        // Category permissions
        'categories.view' => 'View categories',
        'categories.manage' => 'Create and edit categories',
        
        // Report permissions
        'reports.view' => 'View reports',
        'reports.export' => 'Export report data',
        
        // Settings permissions
        'settings.view' => 'View charity settings',
        'settings.manage' => 'Manage charity settings',
        'users.view' => 'View user list',
        'users.manage' => 'Manage users and permissions',
        
        // Privacy / GDPR permissions
        'privacy.export' => 'Export personal data (SAR requests)',
        'privacy.delete' => 'Anonymise/delete personal data (GDPR erasure)',
    ];

    /**
     * Permission groups for the UI
     */
    public const PERMISSION_GROUPS = [
        'Transactions' => ['transactions.view', 'transactions.create', 'transactions.edit', 'transactions.delete'],
        'Donors' => ['donors.view_list', 'donors.view_detail', 'donors.create', 'donors.edit', 'donors.export'],
        'Members' => ['members.view_list', 'members.view_detail', 'members.create', 'members.edit', 'members.manage_dues', 'members.manage_trustees'],
        'Gift Aid' => ['giftaid.view', 'giftaid.manage_claims'],
        'Cash Books' => ['cashbooks.view', 'cashbooks.manage', 'cashbooks.reconcile'],
        'Funds' => ['funds.view', 'funds.manage'],
        'Categories' => ['categories.view', 'categories.manage'],
        'Reports' => ['reports.view', 'reports.export'],
        'Administration' => ['settings.view', 'settings.manage', 'users.view', 'users.manage'],
        'Privacy (GDPR)' => ['privacy.export', 'privacy.delete'],
    ];

    /**
     * Available colours for roles (Tailwind colour names)
     */
    public const ROLE_COLOURS = [
        'gray' => 'Gray',
        'red' => 'Red',
        'orange' => 'Orange',
        'yellow' => 'Yellow',
        'green' => 'Green',
        'teal' => 'Teal',
        'blue' => 'Blue',
        'indigo' => 'Indigo',
        'purple' => 'Purple',
        'pink' => 'Pink',
    ];

    /**
     * Default role templates (used when creating a new charity)
     */
    public const DEFAULT_ROLES = [
        [
            'name' => 'pending',
            'display_name' => 'Pending Approval',
            'description' => 'Users awaiting approval',
            'colour' => 'yellow',
            'permissions' => [],
            'is_system' => true,
            'can_be_deleted' => false,
            'sort_order' => 0,
        ],
        [
            'name' => 'readonly',
            'display_name' => 'Read Only',
            'description' => 'Can view but not edit',
            'colour' => 'gray',
            'permissions' => [
                'transactions.view',
                'donors.view_list',
                'members.view_list',
                'funds.view',
                'categories.view',
                'reports.view',
                'giftaid.view',
            ],
            'is_system' => false,
            'can_be_deleted' => true,
            'sort_order' => 1,
        ],
        [
            'name' => 'standard',
            'display_name' => 'Standard User',
            'description' => 'Can add and edit transactions',
            'colour' => 'blue',
            'permissions' => [
                'transactions.view',
                'transactions.create',
                'transactions.edit',
                'donors.view_list',
                'donors.view_detail',
                'donors.create',
                'donors.edit',
                'members.view_list',
                'members.view_detail',
                'giftaid.view',
                'funds.view',
                'categories.view',
                'reports.view',
            ],
            'is_system' => false,
            'can_be_deleted' => true,
            'sort_order' => 2,
        ],
        [
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Full access to all features',
            'colour' => 'red',
            'permissions' => [
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
                'cashbooks.view',
                'cashbooks.manage',
                'cashbooks.reconcile',
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
            ],
            'is_system' => true,
            'can_be_deleted' => false,
            'sort_order' => 3,
        ],
    ];

    /**
     * Create default roles for a new charity
     */
    public function createDefaultRoles(int $charityId): void
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not available');
        }

        foreach (self::DEFAULT_ROLES as $role) {
            $this->db->insert('charity_roles', [
                'charity_id' => $charityId,
                'name' => $role['name'],
                'display_name' => $role['display_name'],
                'description' => $role['description'],
                'colour' => $role['colour'] ?? null,
                'permissions' => json_encode($role['permissions']),
                'is_system' => $role['is_system'],
                'can_be_deleted' => $role['can_be_deleted'],
                'sort_order' => $role['sort_order'],
            ]);
        }
    }

    /**
     * Get all roles for a charity
     */
    public function getRoles(int $charityId): array
    {
        if (!$this->db) {
            return [];
        }

        if (isset($this->charityRoleCache[$charityId])) {
            return $this->charityRoleCache[$charityId];
        }

        $roles = $this->db->query(
            "SELECT * FROM charity_roles WHERE charity_id = ? ORDER BY sort_order, name",
            [$charityId]
        );

        // Decode permissions JSON
        foreach ($roles as &$role) {
            $role['permissions'] = json_decode($role['permissions'], true) ?? [];
        }

        $this->charityRoleCache[$charityId] = $roles;
        return $roles;
    }

    /**
     * Get a specific role by name
     */
    public function getRole(int $charityId, string $roleName): ?array
    {
        $roles = $this->getRoles($charityId);
        foreach ($roles as $role) {
            if ($role['name'] === $roleName) {
                return $role;
            }
        }
        return null;
    }

    /**
     * Get a specific role by ID
     */
    public function getRoleById(int $roleId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $role = $this->db->queryOne(
            "SELECT * FROM charity_roles WHERE id = ?",
            [$roleId]
        );

        if ($role) {
            $role['permissions'] = json_decode($role['permissions'], true) ?? [];
        }

        return $role;
    }

    /**
     * Create a new custom role
     */
    public function createRole(int $charityId, array $data): int
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not available');
        }

        // Validate name is unique for this charity
        $existing = $this->db->queryOne(
            "SELECT id FROM charity_roles WHERE charity_id = ? AND name = ?",
            [$charityId, $data['name']]
        );
        if ($existing) {
            throw new \InvalidArgumentException("Role name '{$data['name']}' already exists");
        }

        // Get max sort order
        $maxSort = (int) $this->db->queryValue(
            "SELECT COALESCE(MAX(sort_order), 0) FROM charity_roles WHERE charity_id = ?",
            [$charityId]
        );

        $roleId = $this->db->insert('charity_roles', [
            'charity_id' => $charityId,
            'name' => $data['name'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'colour' => $data['colour'] ?? null,
            'permissions' => json_encode($data['permissions'] ?? []),
            'is_system' => false,
            'can_be_deleted' => true,
            'sort_order' => $maxSort + 1,
        ]);

        // Clear cache
        unset($this->charityRoleCache[$charityId]);

        return $roleId;
    }

    /**
     * Update a role
     */
    public function updateRole(int $roleId, array $data): void
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not available');
        }

        $role = $this->getRoleById($roleId);
        if (!$role) {
            throw new \InvalidArgumentException("Role not found");
        }

        // Can't change name of system roles
        if ($role['is_system'] && isset($data['name']) && $data['name'] !== $role['name']) {
            throw new \InvalidArgumentException("Cannot change name of system role");
        }

        $updateData = [];
        
        if (isset($data['display_name'])) {
            $updateData['display_name'] = $data['display_name'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('colour', $data)) {
            $updateData['colour'] = $data['colour'];
        }
        if (isset($data['permissions'])) {
            $updateData['permissions'] = json_encode($data['permissions']);
        }
        if (isset($data['sort_order'])) {
            $updateData['sort_order'] = (int) $data['sort_order'];
        }

        if (!empty($updateData)) {
            $this->db->update('charity_roles', $updateData, 'id = ?', [$roleId]);
        }

        // Clear cache
        unset($this->charityRoleCache[$role['charity_id']]);
    }

    /**
     * Delete a role (must reassign users first)
     */
    public function deleteRole(int $roleId, string $reassignToRole): void
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not available');
        }

        $role = $this->getRoleById($roleId);
        if (!$role) {
            throw new \InvalidArgumentException("Role not found");
        }

        if (!$role['can_be_deleted']) {
            throw new \InvalidArgumentException("This role cannot be deleted");
        }

        // Verify reassign role exists
        $targetRole = $this->getRole($role['charity_id'], $reassignToRole);
        if (!$targetRole) {
            throw new \InvalidArgumentException("Target role does not exist");
        }

        // Reassign users
        $this->db->execute(
            "UPDATE charity_users SET role = ? WHERE charity_id = ? AND role = ?",
            [$reassignToRole, $role['charity_id'], $role['name']]
        );

        // Delete the role
        $this->db->execute("DELETE FROM charity_roles WHERE id = ?", [$roleId]);

        // Clear cache
        unset($this->charityRoleCache[$role['charity_id']]);
    }

    /**
     * Get count of users with a specific role
     */
    public function getUserCountByRole(int $charityId, string $roleName): int
    {
        if (!$this->db) {
            return 0;
        }

        return (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM charity_users WHERE charity_id = ? AND role = ?",
            [$charityId, $roleName]
        );
    }

    /**
     * Get role permissions for a charity
     */
    public function getRolePermissions(int $charityId, string $roleName): array
    {
        $role = $this->getRole($charityId, $roleName);
        return $role ? $role['permissions'] : [];
    }

    /**
     * Check if a user has a specific permission
     */
    public function hasPermission(array $charityUser, string $permission, ?int $charityId = null): bool
    {
        // Inactive users have no permissions
        if (!($charityUser['is_active'] ?? true)) {
            return false;
        }

        $roleName = $charityUser['role'] ?? 'pending';
        
        // Pending users have no permissions
        if ($roleName === 'pending') {
            return false;
        }

        // Admin role always has ALL permissions
        if ($roleName === 'admin') {
            return array_key_exists($permission, self::PERMISSIONS);
        }

        // Check custom user permissions first
        $customPermissions = $charityUser['permissions'] ?? null;
        if ($customPermissions !== null) {
            if (is_string($customPermissions)) {
                $customPermissions = json_decode($customPermissions, true) ?? [];
            }
            
            if (isset($customPermissions[$permission])) {
                return (bool) $customPermissions[$permission];
            }
        }

        // Get role permissions
        $charityId = $charityId ?? ($charityUser['charity_id'] ?? null);
        if ($charityId) {
            $rolePermissions = $this->getRolePermissions($charityId, $roleName);
        } else {
            $rolePermissions = [];
        }
        
        return in_array($permission, $rolePermissions, true);
    }

    /**
     * Get all permissions for a user (for display)
     */
    public function getEffectivePermissions(array $charityUser, ?int $charityId = null): array
    {
        $roleName = $charityUser['role'] ?? 'pending';
        $customPermissions = $charityUser['permissions'] ?? null;
        
        if ($customPermissions !== null && is_string($customPermissions)) {
            $customPermissions = json_decode($customPermissions, true) ?? [];
        }

        // Get role permissions
        $charityId = $charityId ?? ($charityUser['charity_id'] ?? null);
        if ($charityId) {
            $rolePermissions = $this->getRolePermissions($charityId, $roleName);
        } else {
            $rolePermissions = [];
        }

        $effective = [];
        
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            // Check custom user permissions first
            if ($customPermissions !== null && isset($customPermissions[$permission])) {
                $effective[$permission] = (bool) $customPermissions[$permission];
            } else {
                // Use role permission
                $effective[$permission] = in_array($permission, $rolePermissions, true);
            }
        }

        return $effective;
    }

    /**
     * Get available roles for dropdown (for a specific charity)
     */
    public function getAvailableRoles(int $charityId): array
    {
        $roles = $this->getRoles($charityId);
        $result = [];
        foreach ($roles as $role) {
            $result[$role['name']] = $role['display_name'];
        }
        return $result;
    }

    /**
     * Check if user can approve other users
     */
    public function canApproveUsers(array $charityUser): bool
    {
        return $this->hasPermission($charityUser, 'users.manage');
    }

    /**
     * Clear role cache for a charity
     */
    public function clearCache(?int $charityId = null): void
    {
        if ($charityId !== null) {
            unset($this->charityRoleCache[$charityId]);
        } else {
            $this->charityRoleCache = [];
        }
    }
}
