<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Middleware\CharityAccessMiddleware;
use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ModuleService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

class CharityAccessMiddlewareTest extends TestCase
{
    private CharityAccessMiddleware $middleware;
    private MockObject $charityRepo;
    private MockObject $permissionService;
    private MockObject $moduleService;

    protected function setUp(): void
    {
        $this->charityRepo = $this->createMock(CharityRepository::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->moduleService = $this->createMock(ModuleService::class);

        $this->middleware = new CharityAccessMiddleware(
            $this->charityRepo,
            $this->permissionService,
            $this->moduleService
        );
    }

    private function createRequestWithRoute(int $charityId, int $userId): ServerRequestInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn((string) $charityId);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['__route__', null, $route],
                ['__routeParser__', null, $routeParser],
                ['__routingResults__', null, $routingResults],
                ['user_id', null, $userId],
            ]);

        return $request;
    }

    public function testRedirectsWhenCharityNotFound(): void
    {
        $request = $this->createRequestWithRoute(1, 42);

        $this->charityRepo->method('findById')
            ->with(1)
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charities', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWhenCharityInactive(): void
    {
        $request = $this->createRequestWithRoute(1, 42);

        $this->charityRepo->method('findById')
            ->willReturn(['id' => 1, 'is_active' => false]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charities', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWhenUserHasNoMembership(): void
    {
        $request = $this->createRequestWithRoute(1, 42);

        $this->charityRepo->method('findById')
            ->willReturn(['id' => 1, 'is_active' => true]);

        $this->charityRepo->method('getUserMembership')
            ->with(1, 42)
            ->willReturn(null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charities', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWhenMembershipInactive(): void
    {
        $request = $this->createRequestWithRoute(1, 42);

        $this->charityRepo->method('findById')
            ->willReturn(['id' => 1, 'is_active' => true]);

        $this->charityRepo->method('getUserMembership')
            ->willReturn(['id' => 1, 'is_active' => false, 'role' => 'admin']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charities', $response->getHeaderLine('Location'));
    }

    public function testRedirectsToPendingPageForPendingUsers(): void
    {
        $request = $this->createRequestWithRoute(5, 42);

        $this->charityRepo->method('findById')
            ->willReturn(['id' => 5, 'is_active' => true]);

        $this->charityRepo->method('getUserMembership')
            ->willReturn(['id' => 1, 'is_active' => true, 'role' => 'pending']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charity/5/pending', $response->getHeaderLine('Location'));
    }

    public function testAllowsAccessForActiveMembers(): void
    {
        $charity = ['id' => 1, 'is_active' => true];
        $membership = ['id' => 1, 'is_active' => true, 'role' => 'admin'];
        $enabledModules = ['donations' => true, 'members' => true];

        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn('1');

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        // Track the withAttribute calls
        $attributes = [];
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'user_id' => 42,
                    default => null,
                };
            });
        $request->method('withAttribute')
            ->willReturnCallback(function ($name, $value) use (&$attributes, $request) {
                $attributes[$name] = $value;
                return $request;
            });

        $this->charityRepo->method('findById')
            ->with(1)
            ->willReturn($charity);

        $this->charityRepo->method('getUserMembership')
            ->with(1, 42)
            ->willReturn($membership);

        $this->moduleService->method('getEnabledModules')
            ->with(1)
            ->willReturn($enabledModules);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
        $this->assertEquals($charity, $attributes['charity']);
        $this->assertEquals(1, $attributes['charity_id']);
        $this->assertEquals($membership, $attributes['membership']);
        $this->assertEquals('admin', $attributes['user_role']);
        $this->assertSame($this->permissionService, $attributes['permission_service']);
        $this->assertSame($this->moduleService, $attributes['module_service']);
        $this->assertEquals($enabledModules, $attributes['enabled_modules']);
    }
}
