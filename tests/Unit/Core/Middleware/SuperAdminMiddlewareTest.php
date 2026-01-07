<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Exceptions\AuthorisationException;
use CharityCashbook\Core\Middleware\SuperAdminMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class SuperAdminMiddlewareTest extends TestCase
{
    private SuperAdminMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new SuperAdminMiddleware();
    }

    public function testProcessAllowsSuperAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('is_super_admin', false)
            ->willReturn(true);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessThrowsExceptionForNonSuperAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('is_super_admin', false)
            ->willReturn(false);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage('Super-admin access required');

        $this->middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionWhenAttributeMissing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('is_super_admin', false)
            ->willReturn(false); // Default value returned

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);

        $this->middleware->process($request, $handler);
    }
}
