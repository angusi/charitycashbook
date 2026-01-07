<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use CharityCashbook\Charity\ModuleService;
use CharityCashbook\Core\Middleware\ModuleMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ModuleMiddlewareTest extends TestCase
{
    public function testProcessAllowsRequestWhenModuleEnabled(): void
    {
        $middleware = new ModuleMiddleware('giftaid');

        $moduleService = $this->createMock(ModuleService::class);
        $moduleService->method('isModuleEnabled')
            ->with(42, 'giftaid')
            ->willReturn(true);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['charity_id', null, 42],
                ['module_service', null, $moduleService],
            ]);

        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessRedirectsWhenModuleDisabled(): void
    {
        $middleware = new ModuleMiddleware('giftaid');

        $moduleService = $this->createMock(ModuleService::class);
        $moduleService->method('isModuleEnabled')
            ->with(42, 'giftaid')
            ->willReturn(false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['charity_id', null, 42],
                ['module_service', null, $moduleService],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/charity/42', $response->getHeaderLine('Location'));
    }

    public function testProcessRedirectsWhenModuleServiceMissing(): void
    {
        $middleware = new ModuleMiddleware('donors');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['charity_id', null, 99],
                ['module_service', null, null],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/charity/99', $response->getHeaderLine('Location'));
    }

    public function testProcessUsesCorrectModuleIdFromConstructor(): void
    {
        $middleware = new ModuleMiddleware('donors');

        $moduleService = $this->createMock(ModuleService::class);
        $moduleService->expects($this->once())
            ->method('isModuleEnabled')
            ->with(1, 'donors')
            ->willReturn(true);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnMap([
                ['charity_id', null, 1],
                ['module_service', null, $moduleService],
            ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $middleware->process($request, $handler);
    }
}
