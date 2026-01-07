<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use CharityCashbook\Auth\Controllers\LoginController;
use CharityCashbook\Auth\OAuthProviderService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Container;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use League\OAuth2\Client\Provider\AbstractProvider;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoginControllerTest extends TestCase
{
    private LoginController $controller;
    private MockObject&Container $container;
    private MockObject $sessionManager;
    private MockObject $providerService;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->providerService = $this->createMock(OAuthProviderService::class);

        $this->container->method('get')
            ->willReturnMap([
                [SessionManager::class, $this->sessionManager],
                [OAuthProviderService::class, $this->providerService],
            ]);

        $this->controller = new LoginController($this->container);
    }

    public function testLogoutDestroysSessionAndRedirects(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $this->sessionManager->expects($this->once())
            ->method('destroy');

        $result = $this->controller->logout($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
    }

    public function testRedirectToProviderRedirectsToOAuthUrl(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $mockProvider = $this->createMock(AbstractProvider::class);
        $mockProvider->method('getAuthorizationUrl')
            ->willReturn('https://oauth.example.com/authorize?state=abc123');

        $this->providerService->method('getProviderInstance')
            ->with(1)
            ->willReturn($mockProvider);

        $this->providerService->method('generateState')
            ->willReturn('abc123');

        $result = $this->controller->redirectToProvider($request, $response, ['provider' => '1']);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertStringContainsString('oauth.example.com', $result->getHeaderLine('Location'));
    }

    public function testRedirectToProviderHandlesException(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $this->providerService->method('getProviderInstance')
            ->willThrowException(new \Exception('Provider not found'));

        $result = $this->controller->redirectToProvider($request, $response, ['provider' => '999']);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
    }
}
