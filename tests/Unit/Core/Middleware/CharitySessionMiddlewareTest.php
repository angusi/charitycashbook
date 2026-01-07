<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Auth\Session;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Middleware\CharitySessionMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Response;
use Slim\Routing\RoutingResults;

class CharitySessionMiddlewareTest extends TestCase
{
    private MockObject&SessionManager $sessionManager;
    private CharitySessionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->middleware = new CharitySessionMiddleware($this->sessionManager);
    }

    private function createRequest(int $charityId, ?Session $session = null, ?array $charity = null): ServerRequestInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getArgument')
            ->with('charity_id')
            ->willReturn((string) $charityId);

        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($route, $routeParser, $routingResults, $session, $charity) {
                return match ($name) {
                    '__route__' => $route,
                    '__routeParser__' => $routeParser,
                    '__routingResults__' => $routingResults,
                    'session' => $session,
                    'charity' => $charity ?? ['id' => 1, 'session_timeout_hours' => 24],
                    default => null,
                };
            });

        return $request;
    }

    public function testMiddlewareCanBeInstantiated(): void
    {
        $this->assertInstanceOf(CharitySessionMiddleware::class, $this->middleware);
    }

    public function testAllowsAccessWhenSessionValid(): void
    {
        $session = $this->createMock(Session::class);
        $charity = ['id' => 1, 'session_timeout_hours' => 24];
        $request = $this->createRequest(1, $session, $charity);

        $this->sessionManager->method('checkCharitySession')
            ->with($session, 1, 24)
            ->willReturn(true);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testRedirectsWhenSessionExpired(): void
    {
        $session = $this->createMock(Session::class);
        $charity = ['id' => 5, 'session_timeout_hours' => 8];
        $request = $this->createRequest(5, $session, $charity);

        $this->sessionManager->method('checkCharitySession')
            ->with($session, 5, 8)
            ->willReturn(false);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/charities/5/enter?expired=1', $response->getHeaderLine('Location'));
    }

    public function testRedirectsWithCorrectCharityId(): void
    {
        $session = $this->createMock(Session::class);
        $charity = ['id' => 42, 'session_timeout_hours' => 12];
        $request = $this->createRequest(42, $session, $charity);

        $this->sessionManager->method('checkCharitySession')
            ->willReturn(false);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals('/charities/42/enter?expired=1', $response->getHeaderLine('Location'));
    }

    public function testSessionManagerCheckCharitySessionLogic(): void
    {
        // Test the logic that the middleware uses via SessionManager
        $session = new Session([
            'id' => 1,
            'user_id' => 42,
            'charity_sessions' => json_encode([
                '10' => ['authenticated_at' => time() - 3600], // 1 hour ago
            ]),
        ]);

        // Verify session has the charity session data
        $this->assertTrue($session->hasCharitySession(10));
        $this->assertFalse($session->hasCharitySession(999));
    }

    public function testSessionCharitySessionDataStructure(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 42,
            'charity_sessions' => json_encode([
                '10' => [
                    'role' => 'admin',
                    'authenticated_at' => 1234567890,
                    'timeout_hours' => 24,
                ],
            ]),
        ]);

        $charitySession = $session->getCharitySession(10);

        $this->assertSame('admin', $charitySession['role']);
        $this->assertSame(1234567890, $charitySession['authenticated_at']);
        $this->assertSame(24, $charitySession['timeout_hours']);
    }
}
