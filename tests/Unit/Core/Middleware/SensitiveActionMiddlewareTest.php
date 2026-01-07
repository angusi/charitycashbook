<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Middleware\SensitiveActionMiddleware;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CharityCashbook\Auth\Session;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SensitiveActionMiddlewareTest extends TestCase
{
    private SensitiveActionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new SensitiveActionMiddleware();
    }

    private function createRequestWithRoute(?int $charityId, ?array $charitySession = null, string $method = 'GET'): ServerRequestInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn($charityId !== null ? (string) $charityId : null);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $session = null;
        if ($charitySession !== null) {
            $session = $this->createMock(Session::class);
            $session->method('getCharitySessions')
                ->willReturn($charityId !== null ? [(string) $charityId => $charitySession] : []);
        }

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/charity/' . $charityId . '/settings');

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
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')
            ->with('Referer')
            ->willReturn('');

        return $request;
    }

    public function testAllowsAccessWithRecentAuthentication(): void
    {
        $charitySession = [
            'authenticated_at' => time() - 60, // 1 minute ago
        ];
        $request = $this->createRequestWithRoute(1, $charitySession);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testRedirectsWithExpiredAuthentication(): void
    {
        $charitySession = [
            'authenticated_at' => time() - 1000, // More than 15 minutes ago
        ];
        $request = $this->createRequestWithRoute(1, $charitySession);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/charities/1/enter', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWithNoSession(): void
    {
        $request = $this->createRequestWithRoute(1, null);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/charities/1/enter', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWithNoCharitySession(): void
    {
        // Session exists but no charity session for this charity
        $session = $this->createMock(Session::class);
        $session->method('getCharitySessions')->willReturn([]);

        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn('1');

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/charity/1/settings');

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
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testRedirectsToLoginWithNoCharityId(): void
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn(null);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'session' => null,
                    default => null,
                };
            });
        $request->method('getMethod')->willReturn('GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/login', $response->getHeaderLine('Location'));
    }

    public function testAllowsAccessAtExactlyMaxAge(): void
    {
        $charitySession = [
            'authenticated_at' => time() - 900, // Exactly 15 minutes ago
        ];
        $request = $this->createRequestWithRoute(1, $charitySession);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }
}
