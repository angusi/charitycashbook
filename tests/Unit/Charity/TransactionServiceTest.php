<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Charity;

use PHPUnit\Framework\TestCase;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\ValidationException;

class TransactionServiceTest extends TestCase
{
    private TransactionService $service;
    private DatabaseInterface $db;
    private Logger $logger;
    private CategoryService $categoryService;
    private AuditService $auditService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->auditService = $this->createMock(AuditService::class);
        
        $this->service = new TransactionService(
            $this->db,
            $this->logger,
            $this->categoryService,
            $this->auditService
        );
    }

    // getTransactions tests
    public function testGetTransactionsReturnsArray(): void
    {
        $transactions = [
            ['id' => 1, 'amount' => 100, 'description' => 'Test'],
        ];
        
        $this->db->method('query')->willReturn($transactions);

        $result = $this->service->getTransactions(1);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testGetTransactionsWithDateFilters(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('transaction_date >='),
                $this->callback(function($params) {
                    return $params[1] === '2025-01-01' && $params[2] === '2025-12-31';
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, [
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);
    }

    public function testGetTransactionsWithFundFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('fund_id = ?'),
                $this->callback(function($params) {
                    return in_array(5, $params);
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['fund_id' => 5]);
    }

    public function testGetTransactionsWithTypeFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('transaction_type = ?'),
                $this->callback(function($params) {
                    return in_array('receipt', $params);
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['type' => 'receipt']);
    }

    public function testGetTransactionsWithSearchFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('description LIKE ?'),
                $this->callback(function($params) {
                    return in_array('%donation%', $params);
                })
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['search' => 'donation']);
    }

    public function testGetTransactionsWithLimit(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('LIMIT ?'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['limit' => 10]);
    }

    // get tests
    public function testGetReturnsTransaction(): void
    {
        $transaction = ['id' => 1, 'amount' => 100];
        
        $this->db->method('queryOne')->willReturn($transaction);

        $result = $this->service->get(1, 1);
        
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $result = $this->service->get(999, 1);
        
        $this->assertNull($result);
    }

    // create tests
    public function testCreateReturnsId(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => false,
        ]);
        
        $this->db->method('insert')->willReturn(10);
        
        $this->auditService->expects($this->once())->method('logTransaction');
        $this->logger->expects($this->once())->method('info');

        $result = $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test donation',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
        
        $this->assertEquals(10, $result);
    }

    public function testCreateThrowsValidationExceptionForMissingDate(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForMissingAmount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForZeroAmount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 0,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForNegativeAmount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => -50,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForMissingDescription(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForMissingFund(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'operational_category_id' => 1,
        ]);
    }

    public function testCreateThrowsValidationExceptionForMissingCategory(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
        ]);
    }

    public function testCreateRollsBackOnException(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => false,
        ]);
        
        $this->db->method('insert')->willThrowException(new \Exception('DB error'));
        $this->db->expects($this->once())->method('rollback');

        $this->expectException(\Exception::class);

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    // update tests
    public function testUpdateSucceeds(): void
    {
        $existing = [
            'id' => 1,
            'giftaid_status' => 'eligible',
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Old',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ];
        
        $this->db->method('queryOne')->willReturn($existing);
        
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => false,
        ]);
        
        $this->db->expects($this->once())->method('update');
        $this->auditService->expects($this->once())->method('logTransaction');

        $this->service->update(1, 1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 200,
            'description' => 'Updated',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testUpdateThrowsExceptionForNonexistentTransaction(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->update(999, 1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    public function testUpdateThrowsExceptionForClaimedTransaction(): void
    {
        $existing = [
            'id' => 1,
            'giftaid_status' => 'claimed',
        ];
        
        $this->db->method('queryOne')->willReturn($existing);

        $this->expectException(ValidationException::class);

        $this->service->update(1, 1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);
    }

    // delete tests
    public function testDeleteSucceeds(): void
    {
        $existing = [
            'id' => 1,
            'giftaid_status' => 'eligible',
        ];
        
        $this->db->method('queryOne')->willReturn($existing);
        
        $this->db->expects($this->once())->method('execute');
        $this->auditService->expects($this->once())->method('logTransaction');

        $this->service->delete(1, 1, 1);
    }

    public function testDeleteThrowsExceptionForNonexistentTransaction(): void
    {
        $this->db->method('queryOne')->willReturn(null);

        $this->expectException(ValidationException::class);

        $this->service->delete(999, 1, 1);
    }

    public function testDeleteThrowsExceptionForClaimedTransaction(): void
    {
        $existing = [
            'id' => 1,
            'giftaid_status' => 'claimed',
        ];
        
        $this->db->method('queryOne')->willReturn($existing);

        $this->expectException(ValidationException::class);

        $this->service->delete(1, 1, 1);
    }

    // getTotalsByCategory tests
    public function testGetTotalsByCategoryReturnsArray(): void
    {
        $totals = [
            ['reporting_category_id' => 1, 'total' => 500],
        ];
        
        $this->db->method('query')->willReturn($totals);

        $result = $this->service->getTotalsByCategory(1, '2025-01-01', '2025-12-31');
        
        $this->assertIsArray($result);
    }

    public function testGetTotalsByCategoryWithFundFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('fund_id = ?'),
                $this->anything()
            )
            ->willReturn([]);

        $this->service->getTotalsByCategory(1, '2025-01-01', '2025-12-31', 5);
    }

    // Additional filter tests
    public function testGetTransactionsWithCategoryFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('operational_category_id = ?'),
                $this->callback(fn($params) => in_array(3, $params))
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['category_id' => 3]);
    }

    public function testGetTransactionsWithReportingCategoryFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('oc.reporting_category_id = ?'),
                $this->callback(fn($params) => in_array(7, $params))
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['reporting_category_id' => 7]);
    }

    public function testGetTransactionsWithGiftaidStatusFilter(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('giftaid_status = ?'),
                $this->callback(fn($params) => in_array('eligible', $params))
            )
            ->willReturn([]);

        $this->service->getTransactions(1, ['giftaid_status' => 'eligible']);
    }

    // Gift Aid status determination tests
    public function testCreateSetsNotApplicableForPayments(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'payment',
            'giftaid_eligible_default' => false,
        ]);

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test payment',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);

        $this->assertEquals('not_applicable', $insertedData['giftaid_status']);
    }

    public function testCreateSetsGasdsStatus(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => true,
        ]);

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'GASDS donation',
            'fund_id' => 1,
            'operational_category_id' => 1,
            'is_gasds' => true,
        ]);

        $this->assertEquals('gasds', $insertedData['giftaid_status']);
    }

    public function testCreateSetsEligibleForOneOffDeclaration(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => true,
        ]);

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'One-off declaration',
            'fund_id' => 1,
            'operational_category_id' => 1,
            'has_oneoff_declaration' => true,
        ]);

        $this->assertEquals('eligible', $insertedData['giftaid_status']);
    }

    public function testCreateSetsEligibleWithValidDeclaration(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => true,
        ]);

        $this->db->method('queryValue')->willReturn(1); // Declaration exists

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'With donor',
            'fund_id' => 1,
            'operational_category_id' => 1,
            'donor_id' => 5,
        ]);

        $this->assertEquals('eligible', $insertedData['giftaid_status']);
    }

    public function testCreateSetsNotEligibleWithoutDeclaration(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => true,
        ]);

        $this->db->method('queryValue')->willReturn(0); // No declaration

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Without declaration',
            'fund_id' => 1,
            'operational_category_id' => 1,
            'donor_id' => 5,
        ]);

        $this->assertEquals('not_eligible', $insertedData['giftaid_status']);
    }

    public function testCreateSetsNotEligibleForAnonymousDonation(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => true,
        ]);

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Anonymous donation',
            'fund_id' => 1,
            'operational_category_id' => 1,
        ]);

        $this->assertEquals('not_eligible', $insertedData['giftaid_status']);
    }

    // Update tests with reference and cash_book_id
    public function testCreateWithOptionalFields(): void
    {
        $this->categoryService->method('getOperationalCategory')->willReturn([
            'category_type' => 'receipt',
            'giftaid_eligible_default' => false,
        ]);

        $insertedData = null;
        $this->db->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$insertedData) {
                if ($table === 'transactions') {
                    $insertedData = $data;
                }
                return 1;
            });

        $this->service->create(1, 1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100,
            'description' => 'Test',
            'fund_id' => 1,
            'operational_category_id' => 1,
            'reference' => 'REF-001',
            'cash_book_id' => 2,
        ]);

        $this->assertEquals('REF-001', $insertedData['reference']);
        $this->assertEquals(2, $insertedData['cash_book_id']);
    }

    // findDuplicate tests
    public function testFindDuplicateReturnsNullWhenNoDuplicateExists(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->service->findDuplicate(1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100.00,
            'description' => 'Test donation',
        ]);

        $this->assertNull($result);
    }

    public function testFindDuplicateReturnsTransactionWhenDuplicateExists(): void
    {
        $duplicate = [
            'id' => 5,
            'transaction_date' => '2025-01-01',
            'description' => 'Test donation',
            'amount' => 100.00,
        ];
        $this->db->method('query')->willReturn([$duplicate]);

        $result = $this->service->findDuplicate(1, [
            'transaction_date' => '2025-01-01',
            'amount' => 100.00,
            'description' => 'Test donation',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['id']);
        $this->assertEquals('Test donation', $result['description']);
    }

    public function testFindDuplicateQueryIncludesCorrectParameters(): void
    {
        $queryCalled = false;
        $queryParams = null;

        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use (&$queryCalled, &$queryParams) {
                $queryCalled = true;
                $queryParams = $params;
                return [];
            });

        $this->service->findDuplicate(1, [
            'transaction_date' => '2025-06-15',
            'amount' => 250.50,
            'description' => 'Monthly donation',
        ]);

        $this->assertTrue($queryCalled);
        $this->assertEquals(1, $queryParams[0]); // charity_id
        $this->assertEquals('2025-06-15', $queryParams[1]); // date
        $this->assertEquals(250.50, $queryParams[2]); // amount
        $this->assertEquals('Monthly donation', $queryParams[3]); // description
    }
}
