<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Core\Exceptions\AuthorisationException;
use CharityCashbook\Core\Middleware\PermissionMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class PermissionMiddlewareTest extends TestCase
{
    public function testProcessAllowsUserWithPermission(): void
    {
        $middleware = new PermissionMiddleware('transactions.create');

        $membership = ['id' => 1, 'role' => 'treasurer'];
        $charityId = 42;

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')
            ->with($membership, 'transactions.create', $charityId)
            ->willReturn(true);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['membership', null, $membership],
                ['charity_id', null, $charityId],
                ['permission_service', null, $permissionService],
            ]);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessThrowsExceptionWithoutPermission(): void
    {
        $middleware = new PermissionMiddleware('settings.update');

        $membership = ['id' => 1, 'role' => 'viewer'];
        $charityId = 42;

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')
            ->with($membership, 'settings.update', $charityId)
            ->willReturn(false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['membership', null, $membership],
                ['charity_id', null, $charityId],
                ['permission_service', null, $permissionService],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage('You do not have permission to perform this action');

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionWhenPermissionServiceMissing(): void
    {
        $middleware = new PermissionMiddleware('transactions.create');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['membership', null, ['id' => 1]],
                ['charity_id', null, 42],
                ['permission_service', null, null],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage('Permission service not available');

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionWhenPermissionServiceWrongType(): void
    {
        $middleware = new PermissionMiddleware('transactions.create');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['membership', null, ['id' => 1]],
                ['charity_id', null, 42],
                ['permission_service', null, new \stdClass()],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage('Permission service not available');

        $middleware->process($request, $handler);
    }

    public function testProcessPassesMembershipAndCharityIdToPermissionService(): void
    {
        $middleware = new PermissionMiddleware('reports.view');

        $membership = ['id' => 99, 'role' => 'admin', 'member_id' => 5];
        $charityId = 123;

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects($this->once())
            ->method('hasPermission')
            ->with(
                $this->equalTo($membership),
                $this->equalTo('reports.view'),
                $this->equalTo(123)
            )
            ->willReturn(true);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['membership', null, $membership],
                ['charity_id', null, $charityId],
                ['permission_service', null, $permissionService],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $middleware->process($request, $handler);
    }
}
