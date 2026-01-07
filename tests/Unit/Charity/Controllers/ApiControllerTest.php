<?php

declare(strict_types=1);

namespace Tests\Unit\Charity\Controllers;

use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\Controllers\ApiController;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Core\Container;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class ApiControllerTest extends TestCase
{
    private MockObject&Container $container;
    private ApiController $controller;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->controller = new ApiController($this->container);
    }

    // ========================================
    // searchDonors tests
    // ========================================

    public function testSearchDonorsReturnsJsonResponse(): void
    {
        $donorService = $this->createMock(DonorService::class);
        $donorService->method('search')
            ->willReturn([
                ['id' => 1, 'display_name' => 'John Smith', 'has_active_declaration' => true],
                ['id' => 2, 'display_name' => 'Jane Doe', 'has_active_declaration' => false],
            ]);

        $auditService = $this->createMock(AuditService::class);

        $this->container->method('get')
            ->willReturnMap([
                [DonorService::class, $donorService],
                [AuditService::class, $auditService],
            ]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn($name) => match($name) {
                'charity_id' => 1,
                'user_id' => 42,
                default => null,
            });
        $request->method('getParsedBody')
            ->willReturn(['query' => 'Smith', 'limit' => 10]);

        $response = new Response();
        $result = $this->controller->searchDonors($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));

        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('donors', $body);
        $this->assertCount(2, $body['donors']);
        $this->assertEquals('John Smith', $body['donors'][0]['name']);
    }

    public function testSearchDonorsLimitsTo20Results(): void
    {
        $donorService = $this->createMock(DonorService::class);
        $donorService->expects($this->once())
            ->method('search')
            ->with(1, 'test', 20) // Max 20
            ->willReturn([]);

        $auditService = $this->createMock(AuditService::class);

        $this->container->method('get')
            ->willReturnMap([
                [DonorService::class, $donorService],
                [AuditService::class, $auditService],
            ]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(fn($name) => match($name) {
                'charity_id' => 1,
                'user_id' => 42,
                default => null,
            });
        $request->method('getParsedBody')
            ->willReturn(['query' => 'test', 'limit' => 100]); // Try 100

        $response = new Response();
        $this->controller->searchDonors($request, $response);
    }

    // ========================================
    // validateTransaction tests
    // ========================================

    public function testValidateTransactionReturnsValidForCorrectData(): void
    {
        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('findDuplicate')->willReturn(null);

        $this->container->method('get')
            ->with(TransactionService::class)
            ->willReturn($transactionService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);
        $request->method('getParsedBody')
            ->willReturn([
                'transaction_date' => '2025-01-15',
                'amount' => '100.00',
                'description' => 'Test donation',
                'fund_id' => 1,
                'category_id' => 1,
            ]);

        $response = new Response();
        $result = $this->controller->validateTransaction($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['valid']);
        $this->assertEmpty(array_filter($body['errors'], fn($k) => $k !== '_warning', ARRAY_FILTER_USE_KEY));
    }

    public function testValidateTransactionReturnsErrorsForMissingFields(): void
    {
        $this->container->method('get')
            ->willReturn($this->createMock(TransactionService::class));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);
        $request->method('getParsedBody')->willReturn([]);

        $response = new Response();
        $result = $this->controller->validateTransaction($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['valid']);
        $this->assertArrayHasKey('transaction_date', $body['errors']);
        $this->assertArrayHasKey('amount', $body['errors']);
        $this->assertArrayHasKey('description', $body['errors']);
        $this->assertArrayHasKey('fund_id', $body['errors']);
        $this->assertArrayHasKey('category_id', $body['errors']);
    }

    public function testValidateTransactionReturnsErrorForInvalidDate(): void
    {
        $this->container->method('get')
            ->willReturn($this->createMock(TransactionService::class));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);
        $request->method('getParsedBody')
            ->willReturn([
                'transaction_date' => 'invalid-date',
                'amount' => '100.00',
                'description' => 'Test',
                'fund_id' => 1,
                'category_id' => 1,
            ]);

        $response = new Response();
        $result = $this->controller->validateTransaction($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['valid']);
        $this->assertStringContainsString('Invalid date', $body['errors']['transaction_date']);
    }

    public function testValidateTransactionReturnsWarningForDuplicate(): void
    {
        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('findDuplicate')
            ->willReturn([
                'id' => 99,
                'description' => 'Similar transaction',
                'transaction_date' => '2025-01-14',
            ]);

        $this->container->method('get')
            ->with(TransactionService::class)
            ->willReturn($transactionService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);
        $request->method('getParsedBody')
            ->willReturn([
                'transaction_date' => '2025-01-15',
                'amount' => '100.00',
                'description' => 'Test donation',
                'fund_id' => 1,
                'category_id' => 1,
            ]);

        $response = new Response();
        $result = $this->controller->validateTransaction($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['valid']); // Still valid but with warning
        $this->assertArrayHasKey('_warning', $body['errors']);
        $this->assertStringContainsString('Similar transaction', $body['errors']['_warning']);
    }

    // ========================================
    // getCategories tests
    // ========================================

    public function testGetCategoriesReturnsReceiptCategories(): void
    {
        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getOperationalCategories')
            ->with(1, 'receipt')
            ->willReturn([
                ['id' => 1, 'name' => 'Donations'],
                ['id' => 2, 'name' => 'Grants'],
            ]);

        $this->container->method('get')
            ->with(CategoryService::class)
            ->willReturn($categoryService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);

        $response = new Response();
        $result = $this->controller->getCategories($request, $response, ['type' => 'receipt']);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('categories', $body);
        $this->assertCount(2, $body['categories']);
    }

    public function testGetCategoriesReturnsPaymentCategories(): void
    {
        $categoryService = $this->createMock(CategoryService::class);
        $categoryService->expects($this->once())
            ->method('getOperationalCategories')
            ->with(1, 'payment')
            ->willReturn([]);

        $this->container->method('get')
            ->with(CategoryService::class)
            ->willReturn($categoryService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);

        $response = new Response();
        $result = $this->controller->getCategories($request, $response, ['type' => 'payment']);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testGetCategoriesReturnsErrorForInvalidType(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(1);

        $response = new Response();
        $result = $this->controller->getCategories($request, $response, ['type' => 'invalid']);

        $this->assertEquals(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
