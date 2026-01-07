<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Core\DebugToolbar;
use CharityCashbook\Core\Middleware\DebugToolbarMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

class DebugToolbarMiddlewareTest extends TestCase
{
    private MockObject&DebugToolbar $toolbar;

    protected function setUp(): void
    {
        $this->toolbar = $this->createMock(DebugToolbar::class);
    }

    public function testProcessInjectsToolbarWhenDebugEnabled(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->method('mark');
        $this->toolbar->method('render')->willReturn('<div id="debug-toolbar">Toolbar</div>');

        $request = $this->createMock(ServerRequestInterface::class);

        $htmlBody = '<!DOCTYPE html><html><body><p>Content</p></body></html>';
        $response = $this->createHtmlResponse($htmlBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $body = (string) $result->getBody();
        $this->assertStringContainsString('<div id="debug-toolbar">Toolbar</div></body>', $body);
    }

    public function testProcessDoesNotInjectToolbarWhenDebugDisabled(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, false);

        $this->toolbar->method('mark');
        $this->toolbar->expects($this->never())->method('render');

        $request = $this->createMock(ServerRequestInterface::class);

        $htmlBody = '<!DOCTYPE html><html><body><p>Content</p></body></html>';
        $response = $this->createHtmlResponse($htmlBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessDoesNotInjectToolbarForNonHtmlResponses(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->method('mark');
        $this->toolbar->expects($this->never())->method('render');

        $request = $this->createMock(ServerRequestInterface::class);

        $jsonBody = '{"key": "value"}';
        $response = $this->createJsonResponse($jsonBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    public function testProcessAppendsToolbarWhenNoBodyTag(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->method('mark');
        $this->toolbar->method('render')->willReturn('<div>Toolbar</div>');

        $request = $this->createMock(ServerRequestInterface::class);

        // HTML fragment without </body> tag
        $htmlBody = '<!DOCTYPE html><html><p>Content</p></html>';
        $response = $this->createHtmlResponse($htmlBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $body = (string) $result->getBody();
        $this->assertStringEndsWith('<div>Toolbar</div>', $body);
    }

    public function testProcessCallsMarkForTimingMeasurement(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->expects($this->exactly(2))
            ->method('mark')
            ->willReturnCallback(function ($name) {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    $this->assertSame('middleware_start', $name);
                } elseif ($calls === 2) {
                    $this->assertSame('controller_complete', $name);
                }
            });

        $this->toolbar->method('render')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createHtmlResponse('<!DOCTYPE html><html><body></body></html>');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);
    }

    public function testProcessUpdatesContentLengthHeader(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->method('mark');
        $this->toolbar->method('render')->willReturn('<div>Toolbar</div>');

        $request = $this->createMock(ServerRequestInterface::class);

        $htmlBody = '<!DOCTYPE html><html><body></body></html>';
        $response = $this->createHtmlResponse($htmlBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $expectedLength = strlen($htmlBody) + strlen('<div>Toolbar</div>');
        $this->assertSame((string) $expectedLength, $result->getHeaderLine('Content-Length'));
    }

    public function testProcessDetectsHtmlWithHtmlTag(): void
    {
        $middleware = new DebugToolbarMiddleware($this->toolbar, true);

        $this->toolbar->method('mark');
        $this->toolbar->method('render')->willReturn('<div>Toolbar</div>');

        $request = $this->createMock(ServerRequestInterface::class);

        // HTML without DOCTYPE but with <html tag
        $htmlBody = '<html><body><p>Content</p></body></html>';
        $response = $this->createHtmlResponse($htmlBody);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        $body = (string) $result->getBody();
        $this->assertStringContainsString('<div>Toolbar</div>', $body);
    }

    private function createHtmlResponse(string $body): Response
    {
        $streamFactory = new StreamFactory();
        $response = new Response();
        return $response->withBody($streamFactory->createStream($body));
    }

    private function createJsonResponse(string $body): Response
    {
        $streamFactory = new StreamFactory();
        $response = new Response();
        return $response
            ->withBody($streamFactory->createStream($body))
            ->withHeader('Content-Type', 'application/json');
    }
}
