<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use CharityCashbook\Auth\Controllers\OAuthCallbackController;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Container;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OAuthCallbackControllerTest extends TestCase
{
    private OAuthCallbackController $controller;
    private MockObject&Container $container;
    private MockObject $identityService;
    private MockObject $sessionManager;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->identityService = $this->createMock(IdentityService::class);
        $this->sessionManager = $this->createMock(SessionManager::class);

        $this->container->method('get')
            ->willReturnMap([
                [IdentityService::class, $this->identityService],
                [SessionManager::class, $this->sessionManager],
            ]);

        $this->controller = new OAuthCallbackController($this->container);
    }

    public function testCreateAccountRedirectsWhenNoSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        // No new_identity in session
        $result = $this->controller->createAccount($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
    }

    public function testCreateAccountCreatesUserAndSession(): void
    {
        // Start session before setting data
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION['new_identity'] = [
            'display_name' => 'John Doe',
            'oauth_email' => 'john@example.com',
            'provider_id' => 1,
            'oauth_subject' => 'oauth123',
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $this->identityService->expects($this->once())
            ->method('createUser')
            ->with('John Doe', 'john@example.com', 1, 'oauth123', 'john@example.com')
            ->willReturn(['id' => 42, 'display_name' => 'John Doe']);

        $this->sessionManager->expects($this->once())
            ->method('create')
            ->with(42);

        $result = $this->controller->createAccount($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/charities', $result->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('new_identity', $_SESSION);
    }

    public function testCreateAccountHandlesException(): void
    {
        $_SESSION['new_identity'] = [
            'display_name' => 'John Doe',
            'oauth_email' => 'john@example.com',
            'provider_id' => 1,
            'oauth_subject' => 'oauth123',
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $this->identityService->method('createUser')
            ->willThrowException(new \Exception('Email already exists'));

        $result = $this->controller->createAccount($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
    }

    public function testLinkToExistingRedirectsWhenNoSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        // No new_identity in session
        $result = $this->controller->linkToExisting($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
    }

    public function testLinkToExistingMovesIdentityAndRedirects(): void
    {
        // Start session before setting data
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION['new_identity'] = [
            'display_name' => 'John Doe',
            'oauth_email' => 'john@example.com',
            'provider_id' => 1,
            'oauth_subject' => 'oauth123',
            'charities' => [],
            'can_link' => true,
        ];

        $request = $this->createMock(ServerRequestInterface::class);
        $response = new Response();

        $result = $this->controller->linkToExisting($request, $response);

        $this->assertEquals(302, $result->getStatusCode());
        $this->assertEquals('/login', $result->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('new_identity', $_SESSION);
        $this->assertArrayHasKey('link_new_identity', $_SESSION);
        $this->assertEquals('john@example.com', $_SESSION['link_new_identity']['oauth_email']);
    }
}
