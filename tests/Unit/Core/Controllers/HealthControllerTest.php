<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Controllers;

use CharityCashbook\Core\Container;
use CharityCashbook\Core\Controllers\HealthController;
use CharityCashbook\Core\Database;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class HealthControllerTest extends TestCase
{
    private MockObject&Container $container;
    private HealthController $controller;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->controller = new HealthController($this->container);
    }

    public function testCheckReturnsOkWhenDatabaseWorks(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('queryValue')->willReturn(1);
        
        $this->container->method('get')
            ->with(Database::class)
            ->willReturn($db);

        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $result = $this->controller->check($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));
        
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('ok', $body['status']);
        $this->assertEquals('ok', $body['database']);
        $this->assertEquals('1.0.0', $body['version']);
        $this->assertArrayHasKey('timestamp', $body);
    }

    public function testCheckReturnsDegradedWhenDatabaseFails(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('queryValue')
            ->willThrowException(new \Exception('Database connection failed'));
        
        $this->container->method('get')
            ->with(Database::class)
            ->willReturn($db);

        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $result = $this->controller->check($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('degraded', $body['status']);
        $this->assertEquals('error', $body['database']);
    }
}
