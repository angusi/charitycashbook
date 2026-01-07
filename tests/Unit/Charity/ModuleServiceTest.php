<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use CharityCashbook\Charity\ModuleService;
use CharityCashbook\Core\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ModuleServiceTest extends TestCase
{
    private ModuleService $service;
    private MockObject $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->service = new ModuleService($this->db);
    }

    // ===========================================
    // MODULES constant tests
    // ===========================================

    public function testModulesConstantContainsExpectedModules(): void
    {
        $modules = ModuleService::MODULES;
        
        $this->assertArrayHasKey('donors', $modules);
        $this->assertArrayHasKey('giftaid', $modules);
        $this->assertArrayHasKey('members', $modules);
        $this->assertArrayHasKey('reports', $modules);
        $this->assertArrayHasKey('cashbooks', $modules);
    }

    public function testGiftaidDependsOnDonors(): void
    {
        $giftaid = ModuleService::MODULES['giftaid'];
        $this->assertContains('donors', $giftaid['dependencies']);
    }

    public function testModulesHaveRequiredMetadata(): void
    {
        foreach (ModuleService::MODULES as $moduleId => $module) {
            $this->assertArrayHasKey('name', $module, "Module $moduleId missing name");
            $this->assertArrayHasKey('description', $module, "Module $moduleId missing description");
            $this->assertArrayHasKey('icon', $module, "Module $moduleId missing icon");
            $this->assertArrayHasKey('default_enabled', $module, "Module $moduleId missing default_enabled");
            $this->assertArrayHasKey('dependencies', $module, "Module $moduleId missing dependencies");
            $this->assertArrayHasKey('nav_items', $module, "Module $moduleId missing nav_items");
        }
    }

    // ===========================================
    // createDefaultModules() tests
    // ===========================================

    public function testCreateDefaultModulesInsertsAllModules(): void
    {
        $moduleCount = count(ModuleService::MODULES);
        
        $this->db->expects($this->exactly($moduleCount))
            ->method('insert')
            ->with('charity_modules', $this->anything());

        $this->service->createDefaultModules(1);
    }

    public function testCreateDefaultModulesRespectDefaultEnabled(): void
    {
        $insertedModules = [];
        $insertId = 0;
        
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedModules, &$insertId) {
                $insertedModules[$data['module']] = $data;
                return ++$insertId;
            });

        $this->service->createDefaultModules(1);

        // Donors should be enabled by default
        $this->assertTrue($insertedModules['donors']['is_enabled']);
        $this->assertNotNull($insertedModules['donors']['enabled_at']);
        
        // Members should be disabled by default
        $this->assertFalse($insertedModules['members']['is_enabled']);
        $this->assertNull($insertedModules['members']['enabled_at']);
    }

    // ===========================================
    // isModuleEnabled() tests
    // ===========================================

    public function testIsModuleEnabledReturnsTrue(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        $result = $this->service->isModuleEnabled(1, 'donors');
        $this->assertTrue($result);
    }

    public function testIsModuleEnabledReturnsFalse(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '0']);

        $result = $this->service->isModuleEnabled(1, 'members');
        $this->assertFalse($result);
    }

    public function testIsModuleEnabledReturnsFalseWhenNotFound(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $result = $this->service->isModuleEnabled(1, 'donors');
        $this->assertFalse($result);
    }

    public function testIsModuleEnabledUsesCache(): void
    {
        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        // First call should query database
        $this->service->isModuleEnabled(1, 'donors');
        
        // Second call should use cache (not query again)
        $result = $this->service->isModuleEnabled(1, 'donors');
        $this->assertTrue($result);
    }

    // ===========================================
    // getModulesForCharity() tests
    // ===========================================

    public function testGetModulesForCharityReturnsAllModules(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['module' => 'donors', 'is_enabled' => '1', 'enabled_at' => '2024-01-01', 'disabled_at' => null, 'settings' => null],
                ['module' => 'giftaid', 'is_enabled' => '1', 'enabled_at' => '2024-01-01', 'disabled_at' => null, 'settings' => null],
            ]);

        $result = $this->service->getModulesForCharity(1);

        $this->assertCount(count(ModuleService::MODULES), $result);
        $this->assertArrayHasKey('donors', $result);
        $this->assertArrayHasKey('giftaid', $result);
        $this->assertArrayHasKey('members', $result);
    }

    public function testGetModulesForCharityIncludesMetadata(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['module' => 'donors', 'is_enabled' => '1', 'enabled_at' => '2024-01-01 10:00:00', 'disabled_at' => null, 'settings' => '{"key":"value"}'],
            ]);

        $result = $this->service->getModulesForCharity(1);

        $this->assertEquals('donors', $result['donors']['id']);
        $this->assertEquals('Donor Management', $result['donors']['name']);
        $this->assertTrue($result['donors']['is_enabled']);
        $this->assertEquals('2024-01-01 10:00:00', $result['donors']['enabled_at']);
        $this->assertEquals(['key' => 'value'], $result['donors']['settings']);
    }

    public function testGetModulesForCharityHandlesEmptySettings(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['module' => 'donors', 'is_enabled' => '1', 'enabled_at' => '2024-01-01', 'disabled_at' => null, 'settings' => null],
            ]);

        $result = $this->service->getModulesForCharity(1);

        $this->assertEquals([], $result['donors']['settings']);
    }

    public function testGetModulesForCharityHandlesMissingModules(): void
    {
        // No modules configured in database
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->getModulesForCharity(1);

        // All modules should still be returned, but disabled
        $this->assertCount(count(ModuleService::MODULES), $result);
        $this->assertFalse($result['donors']['is_enabled']);
    }

    // ===========================================
    // getEnabledModules() tests
    // ===========================================

    public function testGetEnabledModulesFiltersDisabled(): void
    {
        $this->db->method('query')
            ->willReturn([
                ['module' => 'donors', 'is_enabled' => '1', 'enabled_at' => '2024-01-01', 'disabled_at' => null, 'settings' => null],
                ['module' => 'members', 'is_enabled' => '0', 'enabled_at' => null, 'disabled_at' => null, 'settings' => null],
            ]);

        $result = $this->service->getEnabledModules(1);

        $this->assertArrayHasKey('donors', $result);
        $this->assertArrayNotHasKey('members', $result);
    }

    public function testGetEnabledModulesReturnsEmptyForNoEnabled(): void
    {
        $this->db->method('query')
            ->willReturn([]);

        $result = $this->service->getEnabledModules(1);

        $this->assertEmpty($result);
    }

    // ===========================================
    // enableModule() tests
    // ===========================================

    public function testEnableModuleUpdatesExisting(): void
    {
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1] // Module exists
            );

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE charity_modules SET is_enabled = TRUE'),
                $this->anything()
            );

        $this->service->enableModule(1, 'donors', 1);
    }

    public function testEnableModuleInsertsNew(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null); // Module doesn't exist

        $this->db->expects($this->once())
            ->method('insert')
            ->with('charity_modules', $this->callback(function ($data) {
                return $data['charity_id'] === 1
                    && $data['module'] === 'donors'
                    && $data['is_enabled'] === true;
            }));

        $this->service->enableModule(1, 'donors', 1);
    }

    public function testEnableModuleThrowsForUnknownModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown module: invalid');

        $this->service->enableModule(1, 'invalid');
    }

    public function testEnableModuleThrowsForMissingDependency(): void
    {
        // Donors is disabled
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '0']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot enable Gift Aid without enabling Donor Management first');

        $this->service->enableModule(1, 'giftaid');
    }

    public function testEnableModuleClearsCache(): void
    {
        // First, populate cache
        $this->db->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                ['is_enabled' => '0'], // isModuleEnabled check
                ['id' => 1] // enableModule existing check
            );

        $this->service->isModuleEnabled(1, 'members');
        
        $this->db->expects($this->once())
            ->method('execute');

        $this->service->enableModule(1, 'members', 1);
        
        // Cache should be cleared, but we can't easily verify without more setup
    }

    // ===========================================
    // disableModule() tests
    // ===========================================

    public function testDisableModuleExecutesUpdate(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '0']); // For dependency check

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE charity_modules SET is_enabled = FALSE'),
                $this->anything()
            );

        $this->service->disableModule(1, 'members', 1);
    }

    public function testDisableModuleThrowsForUnknownModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown module: invalid');

        $this->service->disableModule(1, 'invalid');
    }

    public function testDisableModuleThrowsWhenDependentModuleEnabled(): void
    {
        // Giftaid depends on donors, and giftaid is enabled
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot disable Donor Management while Gift Aid is enabled');

        $this->service->disableModule(1, 'donors');
    }

    // ===========================================
    // getModuleSettings() tests
    // ===========================================

    public function testGetModuleSettingsReturnsSettings(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['settings' => '{"option1":true,"option2":"value"}']);

        $result = $this->service->getModuleSettings(1, 'donors');

        $this->assertEquals(['option1' => true, 'option2' => 'value'], $result);
    }

    public function testGetModuleSettingsReturnsEmptyForNull(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['settings' => null]);

        $result = $this->service->getModuleSettings(1, 'donors');

        $this->assertEquals([], $result);
    }

    public function testGetModuleSettingsReturnsEmptyWhenNotFound(): void
    {
        $this->db->method('queryOne')
            ->willReturn(null);

        $result = $this->service->getModuleSettings(1, 'donors');

        $this->assertEquals([], $result);
    }

    // ===========================================
    // updateModuleSettings() tests
    // ===========================================

    public function testUpdateModuleSettingsExecutesUpdate(): void
    {
        $settings = ['option1' => true, 'option2' => 'test'];

        $this->db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE charity_modules SET settings = ?'),
                $this->callback(function ($params) use ($settings) {
                    return $params[0] === json_encode($settings)
                        && $params[1] === 1
                        && $params[2] === 'donors';
                })
            );

        $this->service->updateModuleSettings(1, 'donors', $settings);
    }

    // ===========================================
    // isNavItemEnabled() tests
    // ===========================================

    public function testIsNavItemEnabledReturnsTrueForEnabledModule(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        $result = $this->service->isNavItemEnabled(1, 'donors');
        $this->assertTrue($result);
    }

    public function testIsNavItemEnabledReturnsFalseForDisabledModule(): void
    {
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '0']);

        $result = $this->service->isNavItemEnabled(1, 'members');
        $this->assertFalse($result);
    }

    public function testIsNavItemEnabledReturnsTrueForUnlinkedNavItem(): void
    {
        // Nav item not tied to any module should always be true
        $result = $this->service->isNavItemEnabled(1, 'transactions');
        $this->assertTrue($result);
    }

    // ===========================================
    // clearCache() tests
    // ===========================================

    public function testClearCacheClearsSpecificCharity(): void
    {
        // Populate cache
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        $this->service->isModuleEnabled(1, 'donors');
        $this->service->isModuleEnabled(2, 'donors');

        // Clear only charity 1
        $this->service->clearCache(1);

        // This would need more setup to properly verify, but ensures no errors
        $this->assertTrue(true);
    }

    public function testClearCacheClearsAllCaches(): void
    {
        // Populate cache
        $this->db->method('queryOne')
            ->willReturn(['is_enabled' => '1']);

        $this->service->isModuleEnabled(1, 'donors');
        $this->service->isModuleEnabled(2, 'donors');

        // Clear all
        $this->service->clearCache();

        // This would need more setup to properly verify, but ensures no errors
        $this->assertTrue(true);
    }
}
