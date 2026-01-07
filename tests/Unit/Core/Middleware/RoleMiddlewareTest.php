<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Exceptions\AuthorisationException;
use CharityCashbook\Core\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class RoleMiddlewareTest extends TestCase
{
    public function testProcessAllowsUserWithAllowedRole(): void
    {
        $middleware = new RoleMiddleware(['admin', 'treasurer']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn('admin');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessAllowsSecondRoleInList(): void
    {
        $middleware = new RoleMiddleware(['admin', 'treasurer']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn('treasurer');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessThrowsExceptionForUnauthorisedRole(): void
    {
        $middleware = new RoleMiddleware(['admin', 'treasurer']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn('viewer');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage('You do not have permission to perform this action');

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionWhenNoRoleSet(): void
    {
        $middleware = new RoleMiddleware(['admin']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessUsesStrictComparison(): void
    {
        $middleware = new RoleMiddleware(['admin']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn('Admin'); // Wrong case

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessWithSingleAllowedRole(): void
    {
        $middleware = new RoleMiddleware(['admin']);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('user_role')
            ->willReturn('admin');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }
}
