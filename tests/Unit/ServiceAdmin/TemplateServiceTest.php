<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceAdmin;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\ServiceAdmin\TemplateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateServiceTest extends TestCase
{
    private TemplateService $service;
    private MockObject&DatabaseInterface $db;
    private MockObject&Logger $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->service = new TemplateService($this->db, $this->logger);
    }

    // ========================================
    // getAllTemplates tests
    // ========================================

    public function testGetAllTemplatesReturnsAllTemplatesWithItemCount(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Church', 'item_count' => 12],
            ['id' => 2, 'name' => 'Scout Group', 'item_count' => 8],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('SELECT t.*'))
            ->willReturn($expected);

        $result = $this->service->getAllTemplates();

        $this->assertSame($expected, $result);
    }

    public function testGetAllTemplatesReturnsEmptyArrayWhenNoTemplates(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getAllTemplates();

        $this->assertSame([], $result);
    }

    // ========================================
    // getActiveTemplates tests
    // ========================================

    public function testGetActiveTemplatesReturnsOnlyActiveTemplates(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Church', 'description' => 'For churches'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('is_active = TRUE'))
            ->willReturn($expected);

        $result = $this->service->getActiveTemplates();

        $this->assertSame($expected, $result);
    }

    // ========================================
    // getTemplate tests
    // ========================================

    public function testGetTemplateReturnsTemplateById(): void
    {
        $expected = ['id' => 1, 'name' => 'Church', 'description' => 'For churches'];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->with(
                $this->stringContains('SELECT * FROM category_templates'),
                [1]
            )
            ->willReturn($expected);

        $result = $this->service->getTemplate(1);

        $this->assertSame($expected, $result);
    }

    public function testGetTemplateReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getTemplate(999);

        $this->assertNull($result);
    }

    // ========================================
    // getTemplateItems tests
    // ========================================

    public function testGetTemplateItemsReturnsGroupedByType(): void
    {
        $items = [
            ['category_type' => 'receipt', 'reporting_name' => 'Donations', 'operational_names' => '["Regular giving"]'],
            ['category_type' => 'receipt', 'reporting_name' => 'Grants', 'operational_names' => '[]'],
            ['category_type' => 'payment', 'reporting_name' => 'Salaries', 'operational_names' => '["Staff costs"]'],
        ];

        $this->db->method('query')->willReturn($items);

        $result = $this->service->getTemplateItems(1);

        $this->assertArrayHasKey('receipt', $result);
        $this->assertArrayHasKey('payment', $result);
        $this->assertCount(2, $result['receipt']);
        $this->assertCount(1, $result['payment']);
        $this->assertEquals(['Regular giving'], $result['receipt'][0]['operational_names']);
    }

    public function testGetTemplateItemsReturnsEmptyGroupsWhenNoItems(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->getTemplateItems(1);

        $this->assertSame(['receipt' => [], 'payment' => []], $result);
    }

    public function testGetTemplateItemsDecodesJsonOperationalNames(): void
    {
        $items = [
            ['category_type' => 'receipt', 'reporting_name' => 'Test', 'operational_names' => '["Name 1", "Name 2", "Name 3"]'],
        ];

        $this->db->method('query')->willReturn($items);

        $result = $this->service->getTemplateItems(1);

        $this->assertEquals(['Name 1', 'Name 2', 'Name 3'], $result['receipt'][0]['operational_names']);
    }

    // ========================================
    // createTemplate tests
    // ========================================

    public function testCreateTemplateInsertsAndReturnsId(): void
    {
        $data = [
            'name' => 'New Template',
            'description' => 'A test template',
            'is_active' => true,
            'display_order' => 5,
        ];

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_templates', $this->callback(function ($insertData) use ($data) {
                return $insertData['name'] === $data['name']
                    && $insertData['description'] === $data['description']
                    && $insertData['is_active'] === true
                    && $insertData['display_order'] === 5;
            }))
            ->willReturn(42);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Category template created', $this->arrayHasKey('template_id'));

        $result = $this->service->createTemplate($data);

        $this->assertSame(42, $result);
    }

    public function testCreateTemplateUsesDefaultValues(): void
    {
        $data = ['name' => 'Minimal Template'];

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_templates', $this->callback(function ($insertData) {
                return $insertData['name'] === 'Minimal Template'
                    && $insertData['description'] === null
                    && $insertData['is_active'] === true
                    && $insertData['display_order'] === 0;
            }))
            ->willReturn(1);

        $this->service->createTemplate($data);
    }

    // ========================================
    // updateTemplate tests
    // ========================================

    public function testUpdateTemplateUpdatesAllFields(): void
    {
        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'is_active' => false,
            'display_order' => 10,
        ];

        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'category_templates',
                $this->callback(function ($updateData) use ($data) {
                    return $updateData['name'] === $data['name']
                        && $updateData['description'] === $data['description']
                        && $updateData['is_active'] === false
                        && $updateData['display_order'] === 10;
                }),
                'id = ?',
                [1]
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Category template updated', ['template_id' => 1]);

        $this->service->updateTemplate(1, $data);
    }

    // ========================================
    // deleteTemplate tests
    // ========================================

    public function testDeleteTemplateExecutesDelete(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM category_templates WHERE id = ?', [1]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Category template deleted', ['template_id' => 1]);

        $this->service->deleteTemplate(1);
    }

    // ========================================
    // addTemplateItem tests
    // ========================================

    public function testAddTemplateItemCalculatesDisplayOrder(): void
    {
        $data = [
            'category_type' => 'receipt',
            'reporting_name' => 'New Category',
            'operational_names' => ['Alt Name 1', 'Alt Name 2'],
        ];

        $this->db->expects($this->once())
            ->method('queryValue')
            ->with(
                $this->stringContains('COALESCE(MAX(display_order)'),
                [1, 'receipt']
            )
            ->willReturn(5);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_template_items', $this->callback(function ($insertData) {
                return $insertData['template_id'] === 1
                    && $insertData['category_type'] === 'receipt'
                    && $insertData['reporting_name'] === 'New Category'
                    && $insertData['operational_names'] === '["Alt Name 1","Alt Name 2"]'
                    && $insertData['display_order'] === 5;
            }))
            ->willReturn(100);

        $result = $this->service->addTemplateItem(1, $data);

        $this->assertSame(100, $result);
    }

    public function testAddTemplateItemUsesProvidedDisplayOrder(): void
    {
        $data = [
            'category_type' => 'payment',
            'reporting_name' => 'Test',
            'display_order' => 99,
        ];

        $this->db->method('queryValue')->willReturn(5);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_template_items', $this->callback(function ($insertData) {
                return $insertData['display_order'] === 99;
            }))
            ->willReturn(1);

        $this->service->addTemplateItem(1, $data);
    }

    public function testAddTemplateItemDefaultsOperationalNamesToEmptyArray(): void
    {
        $data = [
            'category_type' => 'receipt',
            'reporting_name' => 'Simple Category',
        ];

        $this->db->method('queryValue')->willReturn(0);

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_template_items', $this->callback(function ($insertData) {
                return $insertData['operational_names'] === '[]';
            }))
            ->willReturn(1);

        $this->service->addTemplateItem(1, $data);
    }

    // ========================================
    // updateTemplateItem tests
    // ========================================

    public function testUpdateTemplateItemUpdatesReportingName(): void
    {
        $data = ['reporting_name' => 'Updated Name'];

        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'category_template_items',
                ['reporting_name' => 'Updated Name'],
                'id = ?',
                [1]
            );

        $this->service->updateTemplateItem(1, $data);
    }

    public function testUpdateTemplateItemUpdatesOperationalNames(): void
    {
        $data = [
            'reporting_name' => 'Name',
            'operational_names' => ['Alt 1', 'Alt 2'],
        ];

        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'category_template_items',
                $this->callback(function ($updateData) {
                    return $updateData['reporting_name'] === 'Name'
                        && $updateData['operational_names'] === '["Alt 1","Alt 2"]';
                }),
                'id = ?',
                [1]
            );

        $this->service->updateTemplateItem(1, $data);
    }

    public function testUpdateTemplateItemUpdatesDisplayOrder(): void
    {
        $data = [
            'reporting_name' => 'Name',
            'display_order' => 15,
        ];

        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'category_template_items',
                $this->callback(function ($updateData) {
                    return $updateData['display_order'] === 15;
                }),
                'id = ?',
                [1]
            );

        $this->service->updateTemplateItem(1, $data);
    }

    // ========================================
    // deleteTemplateItem tests
    // ========================================

    public function testDeleteTemplateItemExecutesDelete(): void
    {
        $this->db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM category_template_items WHERE id = ?', [1]);

        $this->service->deleteTemplateItem(1);
    }

    // ========================================
    // getTemplateItem tests
    // ========================================

    public function testGetTemplateItemReturnsItemWithDecodedNames(): void
    {
        $item = [
            'id' => 1,
            'reporting_name' => 'Donations',
            'operational_names' => '["Regular giving", "One-off gifts"]',
        ];

        $this->db->method('queryOne')->willReturn($item);

        $result = $this->service->getTemplateItem(1);

        $this->assertEquals(['Regular giving', 'One-off gifts'], $result['operational_names']);
    }

    public function testGetTemplateItemReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getTemplateItem(999);

        $this->assertNull($result);
    }

    // ========================================
    // duplicateTemplate tests
    // ========================================

    public function testDuplicateTemplateCreatesNewTemplateWithItems(): void
    {
        $template = [
            'id' => 1,
            'name' => 'Church',
            'description' => 'For churches',
            'display_order' => 0,
        ];

        $items = [
            ['category_type' => 'receipt', 'reporting_name' => 'Donations', 'operational_names' => '[]', 'display_order' => 0],
            ['category_type' => 'payment', 'reporting_name' => 'Salaries', 'operational_names' => '[]', 'display_order' => 0],
        ];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($template);

        $this->db->method('query')->willReturn($items);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $insertCalls = [];
        $this->db->expects($this->exactly(3))
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertCalls) {
                $insertCalls[] = ['table' => $table, 'data' => $data];
                return $table === 'category_templates' ? 42 : count($insertCalls);
            });

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Category template duplicated', $this->callback(function ($context) {
                return $context['source_id'] === 1
                    && $context['new_id'] === 42
                    && $context['new_name'] === 'Church Copy';
            }));

        $result = $this->service->duplicateTemplate(1, 'Church Copy');

        $this->assertSame(42, $result);
        $this->assertSame('category_templates', $insertCalls[0]['table']);
        $this->assertSame('Church Copy', $insertCalls[0]['data']['name']);
        $this->assertFalse($insertCalls[0]['data']['is_active']);
        $this->assertSame(1, $insertCalls[0]['data']['display_order']);
    }

    public function testDuplicateTemplateThrowsExceptionWhenTemplateNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found: 999');

        $this->service->duplicateTemplate(999, 'Copy');
    }

    public function testDuplicateTemplateRollsBackOnError(): void
    {
        $template = ['id' => 1, 'name' => 'Church', 'description' => null, 'display_order' => 0];

        $this->db->method('queryOne')->willReturn($template);
        $this->db->method('query')->willReturn([]);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollback');

        $this->db->method('insert')
            ->willThrowException(new \Exception('Database error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->service->duplicateTemplate(1, 'Copy');
    }

    public function testDuplicateTemplateWithNoItemsCreatesEmptyTemplate(): void
    {
        $template = ['id' => 1, 'name' => 'Empty', 'description' => null, 'display_order' => 0];

        $this->db->expects($this->once())
            ->method('queryOne')
            ->willReturn($template);

        $this->db->method('query')->willReturn([]);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $this->db->expects($this->once())
            ->method('insert')
            ->with('category_templates', $this->anything())
            ->willReturn(10);

        $result = $this->service->duplicateTemplate(1, 'Empty Copy');

        $this->assertSame(10, $result);
    }
}
