<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Core\DatabaseInterface;

class CategoryServiceTest extends TestCase
{
    private CategoryService $service;
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->service = new CategoryService($this->db);
    }

    // ========== Reporting Categories ==========

    public function testGetReportingCategoriesReturnsArray(): void
    {
        $categories = [
            ['id' => 1, 'name' => 'Donations', 'category_type' => 'receipt'],
        ];
        $this->db->method('query')->willReturn($categories);

        $result = $this->service->getReportingCategories(1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetReportingCategoriesFiltersByType(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('category_type = ?'),
                $this->callback(fn($params) => in_array('receipt', $params))
            )
            ->willReturn([]);

        $this->service->getReportingCategories(1, 'receipt');
    }

    public function testGetReportingCategoriesIncludesInactiveWhenFlagFalse(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('is_active = TRUE')),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getReportingCategories(1, null, false);
    }

    public function testGetReportingCategoryReturnsCategory(): void
    {
        $category = ['id' => 1, 'name' => 'Donations'];
        $this->db->method('queryOne')->willReturn($category);

        $result = $this->service->getReportingCategory(1, 1);

        $this->assertIsArray($result);
        $this->assertEquals('Donations', $result['name']);
    }

    public function testGetReportingCategoryReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getReportingCategory(999, 1);

        $this->assertNull($result);
    }

    public function testCreateReportingCategoryReturnsId(): void
    {
        $this->db->method('insert')->willReturn(5);

        $result = $this->service->createReportingCategory(1, [
            'name' => 'New Category',
            'category_type' => 'receipt',
        ]);

        $this->assertEquals(5, $result);
    }

    public function testCreateReportingCategoryUsesDefaultDisplayOrder(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'reporting_categories',
                $this->callback(fn($data) => $data['display_order'] === 0)
            )
            ->willReturn(1);

        $this->service->createReportingCategory(1, [
            'name' => 'Test',
            'category_type' => 'receipt',
        ]);
    }

    public function testUpdateReportingCategoryCallsUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'reporting_categories',
                ['name' => 'Updated'],
                'id = ? AND charity_id = ?',
                [1, 1]
            );

        $this->service->updateReportingCategory(1, 1, ['name' => 'Updated']);
    }

    public function testDeactivateReportingCategoryDeactivatesOperationalCategories(): void
    {
        $this->db->expects($this->exactly(2))->method('execute');

        $this->service->deactivateReportingCategory(1, 1);
    }

    // ========== Operational Categories ==========

    public function testGetOperationalCategoriesByReportingReturnsArray(): void
    {
        $categories = [
            ['id' => 1, 'name' => 'General Donations'],
        ];
        $this->db->method('query')->willReturn($categories);

        $result = $this->service->getOperationalCategoriesByReporting(1, 1);

        $this->assertIsArray($result);
    }

    public function testGetOperationalCategoriesByReportingIncludesInactiveWhenFlagFalse(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('is_active = TRUE')),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getOperationalCategoriesByReporting(1, 1, false);
    }

    public function testGetOperationalCategoriesReturnsArray(): void
    {
        $categories = [
            ['id' => 1, 'name' => 'General Donations', 'reporting_category_name' => 'Donations'],
        ];
        $this->db->method('query')->willReturn($categories);

        $result = $this->service->getOperationalCategories(1);

        $this->assertIsArray($result);
    }

    public function testGetOperationalCategoriesFiltersByType(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('rc.category_type = ?'),
                $this->callback(fn($params) => in_array('payment', $params))
            )
            ->willReturn([]);

        $this->service->getOperationalCategories(1, 'payment');
    }

    public function testGetOperationalCategoriesIncludesInactiveWhenFlagFalse(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->logicalNot($this->stringContains('oc.is_active = TRUE')),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getOperationalCategories(1, null, false);
    }

    public function testGetOperationalCategoryReturnsCategory(): void
    {
        $category = ['id' => 1, 'name' => 'General Donations', 'category_type' => 'receipt'];
        $this->db->method('queryOne')->willReturn($category);

        $result = $this->service->getOperationalCategory(1, 1);

        $this->assertIsArray($result);
        $this->assertEquals('General Donations', $result['name']);
    }

    public function testGetOperationalCategoryReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->getOperationalCategory(999, 1);

        $this->assertNull($result);
    }

    public function testCreateOperationalCategoryReturnsId(): void
    {
        $this->db->method('insert')->willReturn(10);

        $result = $this->service->createOperationalCategory(1, [
            'reporting_category_id' => 1,
            'name' => 'New Op Category',
        ]);

        $this->assertEquals(10, $result);
    }

    public function testCreateOperationalCategoryUsesDefaults(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with(
                'operational_categories',
                $this->callback(fn($data) => 
                    $data['giftaid_eligible_default'] === false &&
                    $data['display_order'] === 0 &&
                    $data['is_active'] === true
                )
            )
            ->willReturn(1);

        $this->service->createOperationalCategory(1, [
            'reporting_category_id' => 1,
            'name' => 'Test',
        ]);
    }

    public function testUpdateOperationalCategoryCallsUpdate(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with(
                'operational_categories',
                ['name' => 'Updated'],
                'id = ? AND charity_id = ?',
                [1, 1]
            );

        $this->service->updateOperationalCategory(1, 1, ['name' => 'Updated']);
    }

    public function testDeactivateOperationalCategoryCallsExecute(): void
    {
        $this->db->expects($this->once())->method('execute');

        $this->service->deactivateOperationalCategory(1, 1);
    }

    // ========== Templates ==========

    public function testApplyTemplateCreatesCategories(): void
    {
        $templateItems = [
            [
                'category_type' => 'receipt',
                'reporting_name' => 'Donations',
                'operational_names' => '["General Donations", "Regular Giving"]',
            ],
        ];
        
        $this->db->method('query')->willReturn($templateItems);
        $this->db->method('insert')->willReturn(1);
        
        // Should insert 1 reporting + 2 operational = 3 inserts
        $this->db->expects($this->exactly(3))->method('insert');

        $this->service->applyTemplate(1, 1);
    }

    public function testApplyTemplateDoesNothingForEmptyTemplate(): void
    {
        $this->db->method('query')->willReturn([]);
        $this->db->expects($this->never())->method('insert');

        $this->service->applyTemplate(1, 999);
    }

    public function testApplyTemplateSetsGiftAidEligibleForDonations(): void
    {
        $templateItems = [
            [
                'category_type' => 'receipt',
                'reporting_name' => 'Income',
                'operational_names' => '["Donation from John"]',
            ],
        ];
        
        $this->db->method('query')->willReturn($templateItems);
        
        $insertCalls = [];
        $this->db->method('insert')->willReturnCallback(function($table, $data) use (&$insertCalls) {
            $insertCalls[] = ['table' => $table, 'data' => $data];
            return count($insertCalls);
        });

        $this->service->applyTemplate(1, 1);

        // Find the operational category insert
        $opInsert = array_filter($insertCalls, fn($c) => $c['table'] === 'operational_categories');
        $opInsert = array_values($opInsert)[0] ?? null;
        
        $this->assertNotNull($opInsert);
        $this->assertTrue($opInsert['data']['giftaid_eligible_default']);
    }

    public function testGetAvailableTemplatesReturnsArray(): void
    {
        $templates = [
            ['id' => 1, 'name' => 'Standard UK Charity'],
        ];
        $this->db->method('query')->willReturn($templates);

        $result = $this->service->getAvailableTemplates();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }
}
