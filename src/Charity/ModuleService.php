<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service for managing charity modules (feature flags)
 */
class ModuleService
{
    private DatabaseInterface $db;
    
    /** @var array<int, array<string, bool>> Cache of charity module states */
    private array $moduleCache = [];

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * All available modules with metadata
     */
    public const MODULES = [
        'donors' => [
            'name' => 'Donor Management',
            'description' => 'Track donors and link donations to individuals',
            'icon' => 'users',
            'default_enabled' => true,
            'dependencies' => [],
            'nav_items' => ['donors'],
        ],
        'giftaid' => [
            'name' => 'Gift Aid',
            'description' => 'Manage Gift Aid declarations and claims for UK tax relief',
            'icon' => 'gift',
            'default_enabled' => true,
            'dependencies' => ['donors'],
            'nav_items' => ['giftaid'],
        ],
        'members' => [
            'name' => 'Membership',
            'description' => 'Track charity members, trustees, and membership dues',
            'icon' => 'id-card',
            'default_enabled' => false,
            'dependencies' => [],
            'nav_items' => ['members'],
        ],
        'reports' => [
            'name' => 'Reports',
            'description' => 'Financial reports including Receipts & Payments, Fund Summary, and more',
            'icon' => 'chart-bar',
            'default_enabled' => true,
            'dependencies' => [],
            'nav_items' => ['reports'],
        ],
        'cashbooks' => [
            'name' => 'Cash Books',
            'description' => 'Track bank accounts and petty cash, reconcile balances',
            'icon' => 'library',
            'default_enabled' => false,
            'dependencies' => [],
            'nav_items' => ['cashbooks'],
        ],
    ];

    /**
     * Create default module settings for a new charity
     */
    public function createDefaultModules(int $charityId): void
    {
        foreach (self::MODULES as $moduleId => $module) {
            $this->db->insert('charity_modules', [
                'charity_id' => $charityId,
                'module' => $moduleId,
                'is_enabled' => $module['default_enabled'],
                'enabled_at' => $module['default_enabled'] ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }

    /**
     * Check if a module is enabled for a charity
     */
    public function isModuleEnabled(int $charityId, string $moduleId): bool
    {
        // Check cache first
        if (isset($this->moduleCache[$charityId][$moduleId])) {
            return $this->moduleCache[$charityId][$moduleId];
        }

        // Load from database
        $row = $this->db->queryOne(
            "SELECT is_enabled FROM charity_modules WHERE charity_id = ? AND module = ?",
            [$charityId, $moduleId]
        );

        $enabled = $row ? (bool) $row['is_enabled'] : false;
        $this->moduleCache[$charityId][$moduleId] = $enabled;

        return $enabled;
    }

    /**
     * Get all modules with their status for a charity
     */
    public function getModulesForCharity(int $charityId): array
    {
        $rows = $this->db->query(
            "SELECT * FROM charity_modules WHERE charity_id = ?",
            [$charityId]
        );

        $dbModules = [];
        foreach ($rows as $row) {
            $dbModules[$row['module']] = $row;
        }

        $result = [];
        foreach (self::MODULES as $moduleId => $module) {
            $dbData = $dbModules[$moduleId] ?? null;
            $result[$moduleId] = array_merge($module, [
                'id' => $moduleId,
                'is_enabled' => $dbData ? (bool) $dbData['is_enabled'] : false,
                'enabled_at' => $dbData['enabled_at'] ?? null,
                'disabled_at' => $dbData['disabled_at'] ?? null,
                'settings' => $dbData && $dbData['settings'] ? json_decode($dbData['settings'], true) : [],
            ]);
        }

        return $result;
    }

    /**
     * Get only enabled modules for a charity (for navigation)
     */
    public function getEnabledModules(int $charityId): array
    {
        $all = $this->getModulesForCharity($charityId);
        return array_filter($all, fn($m) => $m['is_enabled']);
    }

    /**
     * Enable a module
     */
    public function enableModule(int $charityId, string $moduleId, ?int $userId = null): void
    {
        if (!isset(self::MODULES[$moduleId])) {
            throw new \InvalidArgumentException("Unknown module: $moduleId");
        }

        // Check dependencies
        $module = self::MODULES[$moduleId];
        foreach ($module['dependencies'] as $dep) {
            if (!$this->isModuleEnabled($charityId, $dep)) {
                $depName = self::MODULES[$dep]['name'] ?? $dep;
                throw new \InvalidArgumentException("Cannot enable {$module['name']} without enabling {$depName} first");
            }
        }

        $existing = $this->db->queryOne(
            "SELECT id FROM charity_modules WHERE charity_id = ? AND module = ?",
            [$charityId, $moduleId]
        );

        if ($existing) {
            $this->db->execute(
                "UPDATE charity_modules SET is_enabled = TRUE, enabled_at = NOW(), enabled_by_user_id = ?, disabled_at = NULL, disabled_by_user_id = NULL WHERE charity_id = ? AND module = ?",
                [$userId, $charityId, $moduleId]
            );
        } else {
            $this->db->insert('charity_modules', [
                'charity_id' => $charityId,
                'module' => $moduleId,
                'is_enabled' => true,
                'enabled_at' => date('Y-m-d H:i:s'),
                'enabled_by_user_id' => $userId,
            ]);
        }

        // Clear cache
        unset($this->moduleCache[$charityId][$moduleId]);
    }

    /**
     * Disable a module
     */
    public function disableModule(int $charityId, string $moduleId, ?int $userId = null): void
    {
        if (!isset(self::MODULES[$moduleId])) {
            throw new \InvalidArgumentException("Unknown module: $moduleId");
        }

        $moduleName = self::MODULES[$moduleId]['name'];

        // Check if other modules depend on this one
        foreach (self::MODULES as $otherId => $other) {
            if (in_array($moduleId, $other['dependencies']) && $this->isModuleEnabled($charityId, $otherId)) {
                throw new \InvalidArgumentException("Cannot disable {$moduleName} while {$other['name']} is enabled");
            }
        }

        $this->db->execute(
            "UPDATE charity_modules SET is_enabled = FALSE, disabled_at = NOW(), disabled_by_user_id = ? WHERE charity_id = ? AND module = ?",
            [$userId, $charityId, $moduleId]
        );

        // Clear cache
        unset($this->moduleCache[$charityId][$moduleId]);
    }

    /**
     * Get module settings
     */
    public function getModuleSettings(int $charityId, string $moduleId): array
    {
        $row = $this->db->queryOne(
            "SELECT settings FROM charity_modules WHERE charity_id = ? AND module = ?",
            [$charityId, $moduleId]
        );

        if ($row && $row['settings']) {
            return json_decode($row['settings'], true) ?? [];
        }

        return [];
    }

    /**
     * Update module settings
     */
    public function updateModuleSettings(int $charityId, string $moduleId, array $settings): void
    {
        $this->db->execute(
            "UPDATE charity_modules SET settings = ? WHERE charity_id = ? AND module = ?",
            [json_encode($settings), $charityId, $moduleId]
        );
    }

    /**
     * Check if a nav item should be shown based on enabled modules
     */
    public function isNavItemEnabled(int $charityId, string $navItem): bool
    {
        foreach (self::MODULES as $moduleId => $module) {
            if (in_array($navItem, $module['nav_items'])) {
                return $this->isModuleEnabled($charityId, $moduleId);
            }
        }
        // If nav item isn't tied to a module, always show it
        return true;
    }

    /**
     * Clear module cache for a charity
     */
    public function clearCache(?int $charityId = null): void
    {
        if ($charityId !== null) {
            unset($this->moduleCache[$charityId]);
        } else {
            $this->moduleCache = [];
        }
    }
}
