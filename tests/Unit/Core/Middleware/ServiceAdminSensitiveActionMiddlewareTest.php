<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Middleware\ServiceAdminSensitiveActionMiddleware;
use CharityCashbook\Auth\Session;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ServiceAdminSensitiveActionMiddlewareTest extends TestCase
{
    private ServiceAdminSensitiveActionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ServiceAdminSensitiveActionMiddleware();
    }

    private function createRequest(string $method, string $pattern, array $args = [], ?int $userId = 1): ServerRequestInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getPattern')->willReturn($pattern);
        $route->method('getArguments')->willReturn($args);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $session = null;
        if ($userId !== null) {
            $session = $this->createMock(Session::class);
            $session->method('getUserId')->willReturn($userId);
        }

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults, $session) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'session' => $session,
                    default => null,
                };
            });
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaderLine')
            ->with('Referer')
            ->willReturn('');

        return $request;
    }

    public function testAlwaysRedirectsToConfirmAction(): void
    {
        $request = $this->createRequest('POST', '/service-admin/charities/{id}/suspend', ['id' => '5']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/service-admin/confirm-action', $response->getHeaderLine('Location'));
    }

    public function testStoresPendingActionInSession(): void
    {
        $request = $this->createRequest('POST', '/service-admin/charities/{id}/suspend', ['id' => '5'], 42);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertArrayHasKey('pending_sensitive_action', $_SESSION);
        $this->assertEquals('suspend_charity', $_SESSION['pending_sensitive_action']['action']);
        $this->assertEquals(['charity_id' => 5], $_SESSION['pending_sensitive_action']['params']);
        $this->assertEquals(42, $_SESSION['pending_sensitive_action']['user_id']);
    }

    public function testRecognizesReactivateCharityAction(): void
    {
        $request = $this->createRequest('POST', '/service-admin/charities/{id}/reactivate', ['id' => '3']);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('reactivate_charity', $_SESSION['pending_sensitive_action']['action']);
        $this->assertEquals(['charity_id' => 3], $_SESSION['pending_sensitive_action']['params']);
    }

    public function testRecognizesToggleProviderAction(): void
    {
        $request = $this->createRequest('POST', '/service-admin/providers/{id}/toggle', ['id' => '2']);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('toggle_provider', $_SESSION['pending_sensitive_action']['action']);
        $this->assertEquals(['provider_id' => 2], $_SESSION['pending_sensitive_action']['params']);
    }

    public function testRecognizesDeleteUserDataAction(): void
    {
        $request = $this->createRequest('POST', '/service-admin/privacy/users/{id}/delete', ['id' => '99']);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('delete_user_data', $_SESSION['pending_sensitive_action']['action']);
        $this->assertEquals(['user_id' => 99], $_SESSION['pending_sensitive_action']['params']);
    }

    public function testHandlesUnknownRouteAction(): void
    {
        $request = $this->createRequest('POST', '/service-admin/unknown/route', []);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('unknown', $_SESSION['pending_sensitive_action']['action']);
    }

    public function testUsesCancelUrlFromReferer(): void
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getPattern')->willReturn('/service-admin/charities/{id}/suspend');
        $route->method('getArguments')->willReturn(['id' => '1']);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $session = $this->createMock(Session::class);
        $session->method('getUserId')->willReturn(1);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults, $session) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'session' => $session,
                    default => null,
                };
            });
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')
            ->with('Referer')
            ->willReturn('https://example.com/service-admin/charities/1');

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('/service-admin/charities/1', $_SESSION['pending_sensitive_action']['cancel_url']);
    }

    public function testDefaultsCancelUrlWhenRefererNotServiceAdmin(): void
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getPattern')->willReturn('/service-admin/charities/{id}/suspend');
        $route->method('getArguments')->willReturn(['id' => '1']);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $session = $this->createMock(Session::class);
        $session->method('getUserId')->willReturn(1);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults, $session) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'session' => $session,
                    default => null,
                };
            });
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')
            ->with('Referer')
            ->willReturn('https://example.com/some/other/page');

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertEquals('/service-admin', $_SESSION['pending_sensitive_action']['cancel_url']);
    }

    public function testHandlesNullSession(): void
    {
        $request = $this->createRequest('POST', '/service-admin/charities/{id}/suspend', ['id' => '1'], null);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->middleware->process($request, $handler);

        $this->assertNull($_SESSION['pending_sensitive_action']['user_id']);
    }
}
