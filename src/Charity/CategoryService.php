<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service for managing reporting and operational categories
 */
class CategoryService
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // ========== Reporting Categories ==========

    /**
     * Get all reporting categories for a charity
     */
    public function getReportingCategories(int $charityId, ?string $type = null, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM reporting_categories WHERE charity_id = ?";
        $params = [$charityId];

        if ($type) {
            $sql .= " AND category_type = ?";
            $params[] = $type;
        }

        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }

        $sql .= " ORDER BY category_type, display_order, name";

        return $this->db->query($sql, $params);
    }

    /**
     * Get a single reporting category
     */
    public function getReportingCategory(int $id, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM reporting_categories WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Create a reporting category
     */
    public function createReportingCategory(int $charityId, array $data): int
    {
        return $this->db->insert('reporting_categories', [
            'charity_id' => $charityId,
            'name' => $data['name'],
            'category_type' => $data['category_type'],
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * Update a reporting category
     */
    public function updateReportingCategory(int $id, int $charityId, array $data): void
    {
        $this->db->update('reporting_categories', $data, 'id = ? AND charity_id = ?', [$id, $charityId]);
    }

    /**
     * Deactivate a reporting category
     */
    public function deactivateReportingCategory(int $id, int $charityId): void
    {
        // Also deactivate all operational categories under it
        $this->db->execute(
            "UPDATE operational_categories SET is_active = FALSE 
             WHERE reporting_category_id = ? AND charity_id = ?",
            [$id, $charityId]
        );

        $this->db->execute(
            "UPDATE reporting_categories SET is_active = FALSE WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    // ========== Operational Categories ==========

    /**
     * Get operational categories by reporting category
     */
    public function getOperationalCategoriesByReporting(int $charityId, int $reportingCategoryId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM operational_categories WHERE charity_id = ? AND reporting_category_id = ?";
        $params = [$charityId, $reportingCategoryId];

        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }

        $sql .= " ORDER BY display_order, name";

        return $this->db->query($sql, $params);
    }

    /**
     * Get all operational categories for a charity
     */
    public function getOperationalCategories(int $charityId, ?string $type = null, bool $activeOnly = true): array
    {
        $sql = "SELECT oc.*, rc.name as reporting_category_name, rc.category_type
                FROM operational_categories oc
                JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
                WHERE oc.charity_id = ?";
        $params = [$charityId];

        if ($type) {
            $sql .= " AND rc.category_type = ?";
            $params[] = $type;
        }

        if ($activeOnly) {
            $sql .= " AND oc.is_active = TRUE AND rc.is_active = TRUE";
        }

        $sql .= " ORDER BY rc.category_type, rc.display_order, oc.display_order, oc.name";

        return $this->db->query($sql, $params);
    }

    /**
     * Get a single operational category
     */
    public function getOperationalCategory(int $id, int $charityId): ?array
    {
        return $this->db->queryOne(
            "SELECT oc.*, rc.name as reporting_category_name, rc.category_type
             FROM operational_categories oc
             JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
             WHERE oc.id = ? AND oc.charity_id = ?",
            [$id, $charityId]
        );
    }

    /**
     * Create an operational category
     */
    public function createOperationalCategory(int $charityId, array $data): int
    {
        return $this->db->insert('operational_categories', [
            'charity_id' => $charityId,
            'reporting_category_id' => $data['reporting_category_id'],
            'name' => $data['name'],
            'giftaid_eligible_default' => $data['giftaid_eligible_default'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * Update an operational category
     */
    public function updateOperationalCategory(int $id, int $charityId, array $data): void
    {
        $this->db->update('operational_categories', $data, 'id = ? AND charity_id = ?', [$id, $charityId]);
    }

    /**
     * Deactivate an operational category
     */
    public function deactivateOperationalCategory(int $id, int $charityId): void
    {
        $this->db->execute(
            "UPDATE operational_categories SET is_active = FALSE WHERE id = ? AND charity_id = ?",
            [$id, $charityId]
        );
    }

    // ========== Category Templates ==========

    /**
     * Apply a category template to a charity (by template ID)
     */
    public function applyTemplate(int $charityId, int $templateId): void
    {
        // Get template items from database
        $items = $this->db->query(
            "SELECT * FROM category_template_items WHERE template_id = ? ORDER BY category_type, display_order",
            [$templateId]
        );

        if (empty($items)) {
            return;
        }

        $receiptOrder = 0;
        $paymentOrder = 0;

        foreach ($items as $item) {
            $isReceipt = $item['category_type'] === 'receipt';
            $order = $isReceipt ? $receiptOrder++ : $paymentOrder++;

            $reportingId = $this->createReportingCategory($charityId, [
                'name' => $item['reporting_name'],
                'category_type' => $item['category_type'],
                'display_order' => $order,
            ]);

            $operationalNames = json_decode($item['operational_names'], true) ?: [];
            $opOrder = 0;

            foreach ($operationalNames as $opName) {
                $this->createOperationalCategory($charityId, [
                    'reporting_category_id' => $reportingId,
                    'name' => $opName,
                    'giftaid_eligible_default' => $isReceipt && (
                        str_contains(strtolower($opName), 'donation') || 
                        str_contains(strtolower($opName), 'giving')
                    ),
                    'display_order' => $opOrder++,
                ]);
            }
        }
    }

    /**
     * Get available templates for dropdown
     */
    public function getAvailableTemplates(): array
    {
        return $this->db->query(
            "SELECT id, name, description FROM category_templates WHERE is_active = TRUE ORDER BY display_order, name"
        );
    }
}
