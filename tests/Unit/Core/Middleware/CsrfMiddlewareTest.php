<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\Exceptions\CsrfException;
use CharityCashbook\Core\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsrfMiddlewareTest extends TestCase
{
    public function testProcessAllowsGetRequests(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessAllowsHeadRequests(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('HEAD');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessAllowsOptionsRequests(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('OPTIONS');

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessThrowsExceptionForPostWithoutToken(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionForPostWithInvalidToken(): void
    {
        session_start();
        $_SESSION['csrf_token'] = 'valid_token_123';

        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['csrf_token' => 'invalid_token']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessAllowsPostWithValidToken(): void
    {
        session_start();
        $_SESSION['csrf_token'] = 'valid_token_123';

        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['csrf_token' => 'valid_token_123']);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessThrowsExceptionForPutWithoutToken(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('PUT');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionForDeleteWithoutToken(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('DELETE');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionForPatchWithoutToken(): void
    {
        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('PATCH');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testProcessThrowsExceptionForEmptyTokenInSession(): void
    {
        session_start();
        $_SESSION['csrf_token'] = '';

        $middleware = new CsrfMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['csrf_token' => '']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->expectException(CsrfException::class);

        $middleware->process($request, $handler);
    }

    public function testGenerateTokenCreatesNewToken(): void
    {
        $token = CsrfMiddleware::generateToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testGenerateTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $token1 = CsrfMiddleware::generateToken();
        $token2 = CsrfMiddleware::generateToken();

        $this->assertSame($token1, $token2);
    }

    public function testGetTokenReturnsToken(): void
    {
        $token = CsrfMiddleware::getToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
    }
}
