<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Service for managing category templates
 */
class TemplateService
{
    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct(DatabaseInterface $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Get all templates
     */
    public function getAllTemplates(): array
    {
        return $this->db->query(
            "SELECT t.*, 
                    (SELECT COUNT(*) FROM category_template_items WHERE template_id = t.id) as item_count
             FROM category_templates t
             ORDER BY t.display_order, t.name"
        );
    }

    /**
     * Get active templates for dropdown
     */
    public function getActiveTemplates(): array
    {
        return $this->db->query(
            "SELECT id, name, description FROM category_templates WHERE is_active = TRUE ORDER BY display_order, name"
        );
    }

    /**
     * Get a template by ID
     */
    public function getTemplate(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM category_templates WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get template items grouped by type
     */
    public function getTemplateItems(int $templateId): array
    {
        $items = $this->db->query(
            "SELECT * FROM category_template_items WHERE template_id = ? ORDER BY category_type, display_order",
            [$templateId]
        );

        $grouped = ['receipt' => [], 'payment' => []];
        foreach ($items as $item) {
            $item['operational_names'] = json_decode($item['operational_names'], true);
            $grouped[$item['category_type']][] = $item;
        }

        return $grouped;
    }

    /**
     * Create a new template
     */
    public function createTemplate(array $data): int
    {
        $id = $this->db->insert('category_templates', [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'display_order' => $data['display_order'] ?? 0,
        ]);

        $this->logger->info('Category template created', ['template_id' => $id, 'name' => $data['name']]);

        return $id;
    }

    /**
     * Update a template
     */
    public function updateTemplate(int $id, array $data): void
    {
        $this->db->update('category_templates', [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'display_order' => $data['display_order'] ?? 0,
        ], 'id = ?', [$id]);

        $this->logger->info('Category template updated', ['template_id' => $id]);
    }

    /**
     * Delete a template
     */
    public function deleteTemplate(int $id): void
    {
        $this->db->execute("DELETE FROM category_templates WHERE id = ?", [$id]);
        $this->logger->info('Category template deleted', ['template_id' => $id]);
    }

    /**
     * Add an item to a template
     */
    public function addTemplateItem(int $templateId, array $data): int
    {
        $maxOrder = $this->db->queryValue(
            "SELECT COALESCE(MAX(display_order), -1) + 1 FROM category_template_items WHERE template_id = ? AND category_type = ?",
            [$templateId, $data['category_type']]
        );

        return $this->db->insert('category_template_items', [
            'template_id' => $templateId,
            'category_type' => $data['category_type'],
            'reporting_name' => $data['reporting_name'],
            'operational_names' => json_encode($data['operational_names'] ?? []),
            'display_order' => $data['display_order'] ?? $maxOrder,
        ]);
    }

    /**
     * Update a template item
     */
    public function updateTemplateItem(int $itemId, array $data): void
    {
        $updateData = [
            'reporting_name' => $data['reporting_name'],
        ];

        if (isset($data['operational_names'])) {
            $updateData['operational_names'] = json_encode($data['operational_names']);
        }

        if (isset($data['display_order'])) {
            $updateData['display_order'] = $data['display_order'];
        }

        $this->db->update('category_template_items', $updateData, 'id = ?', [$itemId]);
    }

    /**
     * Delete a template item
     */
    public function deleteTemplateItem(int $itemId): void
    {
        $this->db->execute("DELETE FROM category_template_items WHERE id = ?", [$itemId]);
    }

    /**
     * Get a single template item
     */
    public function getTemplateItem(int $itemId): ?array
    {
        $item = $this->db->queryOne(
            "SELECT * FROM category_template_items WHERE id = ?",
            [$itemId]
        );

        if ($item) {
            $item['operational_names'] = json_decode($item['operational_names'], true);
        }

        return $item;
    }

    /**
     * Duplicate a template
     */
    public function duplicateTemplate(int $id, string $newName): int
    {
        $template = $this->getTemplate($id);
        if (!$template) {
            throw new \RuntimeException("Template not found: $id");
        }

        $this->db->beginTransaction();

        try {
            $newId = $this->db->insert('category_templates', [
                'name' => $newName,
                'description' => $template['description'],
                'is_active' => false,
                'display_order' => $template['display_order'] + 1,
            ]);

            $items = $this->db->query(
                "SELECT * FROM category_template_items WHERE template_id = ?",
                [$id]
            );

            foreach ($items as $item) {
                $this->db->insert('category_template_items', [
                    'template_id' => $newId,
                    'category_type' => $item['category_type'],
                    'reporting_name' => $item['reporting_name'],
                    'operational_names' => $item['operational_names'],
                    'display_order' => $item['display_order'],
                ]);
            }

            $this->db->commit();

            $this->logger->info('Category template duplicated', [
                'source_id' => $id,
                'new_id' => $newId,
                'new_name' => $newName,
            ]);

            return $newId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
