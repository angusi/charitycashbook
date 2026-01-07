<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Auth\Session;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Middleware\AuthenticationMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthenticationMiddlewareTest extends TestCase
{
    private AuthenticationMiddleware $middleware;
    private MockObject&SessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->middleware = new AuthenticationMiddleware($this->sessionManager);
    }

    public function testProcessRedirectsToLoginWhenNoSession(): void
    {
        $this->sessionManager->method('validate')->willReturn(null);

        $request = $this->createMock(ServerRequestInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testProcessAddsSessionAttributesWhenAuthenticated(): void
    {
        $sessionData = [
            'id' => 1,
            'user_id' => 42,
            'display_name' => 'Test User',
            'is_super_admin' => true,
            'charity_sessions' => '{}',
        ];
        $session = new Session($sessionData);

        $this->sessionManager->method('validate')->willReturn($session);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->exactly(3))
            ->method('withAttribute')
            ->willReturnCallback(function ($name, $value) use ($request, $session) {
                if ($name === 'session') {
                    $this->assertSame($session, $value);
                } elseif ($name === 'user_id') {
                    $this->assertSame(42, $value);
                } elseif ($name === 'is_super_admin') {
                    $this->assertTrue($value);
                }
                return $request;
            });

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessSetsIsSuperAdminFalseForRegularUser(): void
    {
        $sessionData = [
            'id' => 1,
            'user_id' => 42,
            'display_name' => 'Regular User',
            'is_super_admin' => false,
            'charity_sessions' => '{}',
        ];
        $session = new Session($sessionData);

        $this->sessionManager->method('validate')->willReturn($session);

        $capturedAttributes = [];
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')
            ->willReturnCallback(function ($name, $value) use ($request, &$capturedAttributes) {
                $capturedAttributes[$name] = $value;
                return $request;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $this->middleware->process($request, $handler);

        $this->assertFalse($capturedAttributes['is_super_admin']);
    }

    public function testMiddlewareImplementsPsrMiddlewareInterface(): void
    {
        $this->assertInstanceOf(
            \Psr\Http\Server\MiddlewareInterface::class,
            $this->middleware
        );
    }

    public function testProcessSetsUserIdFromSession(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 999,
            'display_name' => 'User',
            'is_super_admin' => false,
            'charity_sessions' => '{}',
        ]);

        $this->sessionManager->method('validate')->willReturn($session);

        $capturedAttributes = [];
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')
            ->willReturnCallback(function ($name, $value) use ($request, &$capturedAttributes) {
                $capturedAttributes[$name] = $value;
                return $request;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $this->middleware->process($request, $handler);

        $this->assertSame(999, $capturedAttributes['user_id']);
    }

    public function testProcessPassesRequestToHandler(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 1,
            'charity_sessions' => '{}',
        ]);

        $this->sessionManager->method('validate')->willReturn($session);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('withAttribute')->willReturn($request);

        $expectedResponse = new Response();
        $expectedResponse = $expectedResponse->withStatus(201);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testProcessReturns302RedirectOnUnauthenticated(): void
    {
        $this->sessionManager->method('validate')->willReturn(null);

        $request = $this->createMock(ServerRequestInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->middleware->process($request, $handler);

        // Check status and location
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
    }
}
