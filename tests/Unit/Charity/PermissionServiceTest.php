<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Core\DatabaseInterface;

class PermissionServiceTest extends TestCase
{
    private PermissionService $service;
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->service = new PermissionService($this->db);
    }

    // Constants tests
    public function testPermissionsConstantIsNotEmpty(): void
    {
        $this->assertNotEmpty(PermissionService::PERMISSIONS);
        $this->assertIsArray(PermissionService::PERMISSIONS);
    }

    public function testPermissionGroupsContainValidPermissions(): void
    {
        $allPermissions = array_keys(PermissionService::PERMISSIONS);
        
        foreach (PermissionService::PERMISSION_GROUPS as $group => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains(
                    $permission,
                    $allPermissions,
                    "Permission '$permission' in group '$group' is not defined in PERMISSIONS"
                );
            }
        }
    }

    public function testRoleColoursConstantIsNotEmpty(): void
    {
        $this->assertNotEmpty(PermissionService::ROLE_COLOURS);
        $this->assertArrayHasKey('gray', PermissionService::ROLE_COLOURS);
        $this->assertArrayHasKey('blue', PermissionService::ROLE_COLOURS);
    }

    public function testDefaultRolesContainRequiredRoles(): void
    {
        $roleNames = array_column(PermissionService::DEFAULT_ROLES, 'name');
        
        $this->assertContains('pending', $roleNames);
        $this->assertContains('admin', $roleNames);
        $this->assertContains('standard', $roleNames);
        $this->assertContains('readonly', $roleNames);
    }

    public function testDefaultRolesHaveRequiredFields(): void
    {
        foreach (PermissionService::DEFAULT_ROLES as $role) {
            $this->assertArrayHasKey('name', $role);
            $this->assertArrayHasKey('display_name', $role);
            $this->assertArrayHasKey('permissions', $role);
            $this->assertArrayHasKey('is_system', $role);
            $this->assertArrayHasKey('can_be_deleted', $role);
            $this->assertArrayHasKey('sort_order', $role);
        }
    }

    // createDefaultRoles tests
    public function testCreateDefaultRolesInsertsAllRoles(): void
    {
        $this->db->expects($this->exactly(count(PermissionService::DEFAULT_ROLES)))
            ->method('insert')
            ->with('charity_roles', $this->isType('array'));

        $this->service->createDefaultRoles(1);
    }

    public function testCreateDefaultRolesThrowsExceptionWithoutDatabase(): void
    {
        $service = new PermissionService(null);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database not available');
        
        $service->createDefaultRoles(1);
    }

    // getRoles tests
    public function testGetRolesReturnsEmptyArrayWithoutDatabase(): void
    {
        $service = new PermissionService(null);
        
        $result = $service->getRoles(1);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetRolesReturnsDecodedPermissions(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'admin', 'permissions' => '["transactions.view","transactions.edit"]'],
        ];
        
        $this->db->method('query')->willReturn($roles);

        $result = $this->service->getRoles(1);
        
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]['permissions']);
        $this->assertContains('transactions.view', $result[0]['permissions']);
    }

    public function testGetRolesCachesResults(): void
    {
        $roles = [['id' => 1, 'name' => 'admin', 'permissions' => '[]']];
        
        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($roles);

        // Call twice - should only query once
        $this->service->getRoles(1);
        $this->service->getRoles(1);
    }

    // getRole tests
    public function testGetRoleReturnsMatchingRole(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'admin', 'permissions' => '[]'],
            ['id' => 2, 'name' => 'standard', 'permissions' => '[]'],
        ];
        
        $this->db->method('query')->willReturn($roles);

        $result = $this->service->getRole(1, 'standard');
        
        $this->assertNotNull($result);
        $this->assertEquals('standard', $result['name']);
    }

    public function testGetRoleReturnsNullForNonexistent(): void
    {
        $roles = [['id' => 1, 'name' => 'admin', 'permissions' => '[]']];
        
        $this->db->method('query')->willReturn($roles);

        $result = $this->service->getRole(1, 'nonexistent');
        
        $this->assertNull($result);
    }

    // getRoleById tests
    public function testGetRoleByIdReturnsRole(): void
    {
        $role = ['id' => 1, 'name' => 'admin', 'permissions' => '["transactions.view"]'];
        
        $this->db->method('queryOne')->willReturn($role);

        $result = $this->service->getRoleById(1);
        
        $this->assertNotNull($result);
        $this->assertIsArray($result['permissions']);
    }

    public function testGetRoleByIdReturnsNullWithoutDatabase(): void
    {
        $service = new PermissionService(null);
        
        $result = $service->getRoleById(1);
        
        $this->assertNull($result);
    }

    public function testGetRoleByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getRoleById(999);
        
        $this->assertNull($result);
    }

    // createRole tests
    public function testCreateRoleReturnsId(): void
    {
        $this->db->method('queryOne')->willReturn(null); // No existing role
        $this->db->method('queryValue')->willReturn(5); // Max sort order
        $this->db->method('insert')->willReturn(10);

        $result = $this->service->createRole(1, [
            'name' => 'custom',
            'display_name' => 'Custom Role',
            'permissions' => ['transactions.view'],
        ]);
        
        $this->assertEquals(10, $result);
    }

    public function testCreateRoleThrowsExceptionForDuplicateName(): void
    {
        $this->db->method('queryOne')->willReturn(['id' => 1]); // Existing role

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("already exists");

        $this->service->createRole(1, [
            'name' => 'existing',
            'display_name' => 'Existing Role',
        ]);
    }

    public function testCreateRoleThrowsExceptionWithoutDatabase(): void
    {
        $service = new PermissionService(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database not available');

        $service->createRole(1, ['name' => 'test', 'display_name' => 'Test']);
    }

    // updateRole tests
    public function testUpdateRoleUpdatesFields(): void
    {
        $role = ['id' => 1, 'name' => 'custom', 'charity_id' => 1, 'is_system' => false, 'permissions' => '[]'];
        
        $this->db->method('queryOne')->willReturn($role);
        $this->db->expects($this->once())
            ->method('update')
            ->with('charity_roles', $this->isType('array'), 'id = ?', [1]);

        $this->service->updateRole(1, ['display_name' => 'Updated Name']);
    }

    public function testUpdateRoleThrowsExceptionForNonexistentRole(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Role not found');

        $this->service->updateRole(999, ['display_name' => 'Test']);
    }

    public function testUpdateRoleThrowsExceptionWhenRenamingSystemRole(): void
    {
        $role = ['id' => 1, 'name' => 'admin', 'charity_id' => 1, 'is_system' => true, 'permissions' => '[]'];
        
        $this->db->method('queryOne')->willReturn($role);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot change name of system role');

        $this->service->updateRole(1, ['name' => 'renamed']);
    }

    public function testUpdateRoleThrowsExceptionWithoutDatabase(): void
    {
        $service = new PermissionService(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database not available');

        $service->updateRole(1, ['display_name' => 'Test']);
    }

    // deleteRole tests
    public function testDeleteRoleReassignsUsersAndDeletes(): void
    {
        $role = ['id' => 2, 'name' => 'custom', 'charity_id' => 1, 'can_be_deleted' => true, 'permissions' => '[]'];
        $targetRole = ['id' => 1, 'name' => 'standard', 'permissions' => '[]'];
        
        $this->db->method('queryOne')->willReturn($role);
        $this->db->method('query')->willReturn([$targetRole, $role]);
        
        $this->db->expects($this->exactly(2))
            ->method('execute');

        $this->service->deleteRole(2, 'standard');
    }

    public function testDeleteRoleThrowsExceptionForNonDeletableRole(): void
    {
        $role = ['id' => 1, 'name' => 'admin', 'charity_id' => 1, 'can_be_deleted' => false, 'permissions' => '[]'];
        
        $this->db->method('queryOne')->willReturn($role);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $this->service->deleteRole(1, 'standard');
    }

    public function testDeleteRoleThrowsExceptionWithoutDatabase(): void
    {
        $service = new PermissionService(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database not available');

        $service->deleteRole(1, 'standard');
    }

    // getUserCountByRole tests
    public function testGetUserCountByRoleReturnsCount(): void
    {
        $this->db->method('queryValue')->willReturn(5);

        $result = $this->service->getUserCountByRole(1, 'admin');
        
        $this->assertEquals(5, $result);
    }

    public function testGetUserCountByRoleReturnsZeroWithoutDatabase(): void
    {
        $service = new PermissionService(null);

        $result = $service->getUserCountByRole(1, 'admin');
        
        $this->assertEquals(0, $result);
    }

    // getRolePermissions tests
    public function testGetRolePermissionsReturnsPermissions(): void
    {
        $this->db->method('query')->willReturn([
            ['name' => 'admin', 'permissions' => '["transactions.view", "transactions.create"]'],
        ]);

        $result = $this->service->getRolePermissions(1, 'admin');
        
        $this->assertEquals(['transactions.view', 'transactions.create'], $result);
    }

    public function testGetRolePermissionsReturnsEmptyArrayWhenRoleNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getRolePermissions(1, 'nonexistent');
        
        $this->assertEquals([], $result);
    }

    // hasPermission tests
    public function testHasPermissionReturnsFalseForInactiveUser(): void
    {
        $user = ['role' => 'admin', 'is_active' => false];

        $result = $this->service->hasPermission($user, 'transactions.view');
        
        $this->assertFalse($result);
    }

    public function testHasPermissionReturnsFalseForPendingUser(): void
    {
        $user = ['role' => 'pending', 'is_active' => true];

        $result = $this->service->hasPermission($user, 'transactions.view');
        
        $this->assertFalse($result);
    }

    public function testHasPermissionReturnsTrueForAdminWithValidPermission(): void
    {
        $user = ['role' => 'admin', 'is_active' => true];

        $result = $this->service->hasPermission($user, 'transactions.view');
        
        $this->assertTrue($result);
    }

    public function testHasPermissionReturnsFalseForAdminWithInvalidPermission(): void
    {
        $user = ['role' => 'admin', 'is_active' => true];

        $result = $this->service->hasPermission($user, 'nonexistent.permission');
        
        $this->assertFalse($result);
    }

    public function testHasPermissionChecksCustomPermissionsFirst(): void
    {
        $user = [
            'role' => 'standard',
            'is_active' => true,
            'charity_id' => 1,
            'permissions' => json_encode(['transactions.delete' => true]),
        ];

        $this->db->method('query')->willReturn([
            ['name' => 'standard', 'permissions' => '["transactions.view"]']
        ]);

        // transactions.delete is not in standard role but is in custom permissions
        $result = $this->service->hasPermission($user, 'transactions.delete', 1);
        
        $this->assertTrue($result);
    }

    public function testHasPermissionFallsBackToRolePermissions(): void
    {
        $user = [
            'role' => 'standard',
            'is_active' => true,
            'charity_id' => 1,
        ];

        $this->db->method('query')->willReturn([
            ['name' => 'standard', 'permissions' => '["transactions.view"]']
        ]);

        $result = $this->service->hasPermission($user, 'transactions.view', 1);
        
        $this->assertTrue($result);
    }

    // getEffectivePermissions tests
    public function testGetEffectivePermissionsReturnsAllPermissions(): void
    {
        $user = ['role' => 'standard', 'charity_id' => 1];

        $this->db->method('query')->willReturn([
            ['name' => 'standard', 'permissions' => '["transactions.view"]']
        ]);

        $result = $this->service->getEffectivePermissions($user, 1);
        
        $this->assertCount(count(PermissionService::PERMISSIONS), $result);
        $this->assertTrue($result['transactions.view']);
        $this->assertFalse($result['transactions.delete']);
    }

    public function testGetEffectivePermissionsOverridesWithCustomPermissions(): void
    {
        $user = [
            'role' => 'standard',
            'charity_id' => 1,
            'permissions' => json_encode(['transactions.view' => false, 'transactions.delete' => true]),
        ];

        $this->db->method('query')->willReturn([
            ['name' => 'standard', 'permissions' => '["transactions.view"]']
        ]);

        $result = $this->service->getEffectivePermissions($user, 1);
        
        // Custom permission overrides role
        $this->assertFalse($result['transactions.view']);
        $this->assertTrue($result['transactions.delete']);
    }

    // getAvailableRoles tests
    public function testGetAvailableRolesReturnsNameDisplayNameMap(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Administrator', 'permissions' => '[]'],
            ['id' => 2, 'name' => 'standard', 'display_name' => 'Standard User', 'permissions' => '[]'],
        ];

        $this->db->method('query')->willReturn($roles);

        $result = $this->service->getAvailableRoles(1);
        
        $this->assertEquals(['admin' => 'Administrator', 'standard' => 'Standard User'], $result);
    }

    // canApproveUsers tests
    public function testCanApproveUsersReturnsTrueForAdmin(): void
    {
        $user = ['role' => 'admin', 'is_active' => true];

        $result = $this->service->canApproveUsers($user);
        
        $this->assertTrue($result);
    }

    public function testCanApproveUsersReturnsFalseForStandardUser(): void
    {
        $user = ['role' => 'standard', 'is_active' => true, 'charity_id' => 1];

        $this->db->method('query')->willReturn([
            ['name' => 'standard', 'permissions' => '["transactions.view"]']
        ]);

        $result = $this->service->canApproveUsers($user);
        
        $this->assertFalse($result);
    }

    // clearCache tests
    public function testClearCacheClearsSpecificCharity(): void
    {
        $roles = [['id' => 1, 'name' => 'admin', 'permissions' => '[]']];
        
        $this->db->expects($this->exactly(2))
            ->method('query')
            ->willReturn($roles);

        // First call caches
        $this->service->getRoles(1);
        
        // Clear cache for charity 1
        $this->service->clearCache(1);
        
        // Should query again
        $this->service->getRoles(1);
    }

    public function testClearCacheClearsAllWhenNullPassed(): void
    {
        $roles = [['id' => 1, 'name' => 'admin', 'permissions' => '[]']];
        
        $this->db->expects($this->exactly(3))
            ->method('query')
            ->willReturn($roles);

        // Cache two charities
        $this->service->getRoles(1);
        $this->service->getRoles(2);
        
        // Clear all
        $this->service->clearCache(null);
        
        // Should query again for both
        $this->service->getRoles(1);
    }
}
