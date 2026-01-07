<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Controllers;

use CharityCashbook\Auth\Session;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Core\Container;
use CharityCashbook\Core\Controllers\BaseController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BaseControllerTest extends TestCase
{
    private MockObject&Container $container;
    private ConcreteTestController $controller;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->controller = new ConcreteTestController($this->container);
    }

    // ========================================
    // json tests
    // ========================================

    public function testJsonReturnsJsonResponse(): void
    {
        $response = new Response();
        $data = ['key' => 'value', 'number' => 42];

        $result = $this->controller->publicJson($response, $data);

        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame(200, $result->getStatusCode());

        $body = (string) $result->getBody();
        $decoded = json_decode($body, true);
        $this->assertSame($data, $decoded);
    }

    public function testJsonWithCustomStatus(): void
    {
        $response = new Response();

        $result = $this->controller->publicJson($response, ['error' => 'Not found'], 404);

        $this->assertSame(404, $result->getStatusCode());
    }

    // ========================================
    // redirect tests
    // ========================================

    public function testRedirectReturns302(): void
    {
        $response = new Response();

        $result = $this->controller->publicRedirect($response, '/dashboard');

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard', $result->getHeaderLine('Location'));
    }

    // ========================================
    // getPostData tests
    // ========================================

    public function testGetPostDataReturnsParsedBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')
            ->willReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $this->controller->publicGetPostData($request);

        $this->assertSame(['field1' => 'value1', 'field2' => 'value2'], $result);
    }

    public function testGetPostDataReturnsEmptyArrayWhenNull(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);

        $result = $this->controller->publicGetPostData($request);

        $this->assertSame([], $result);
    }

    // ========================================
    // getQueryParams tests
    // ========================================

    public function testGetQueryParamsReturnsParams(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')
            ->willReturn(['page' => '1', 'sort' => 'name']);

        $result = $this->controller->publicGetQueryParams($request);

        $this->assertSame(['page' => '1', 'sort' => 'name'], $result);
    }

    // ========================================
    // flash tests
    // ========================================

    public function testFlashStoresMessage(): void
    {
        $this->controller->publicFlash('success', 'Operation completed');

        $this->assertSame(['Operation completed'], $_SESSION['flash']['success']);
    }

    public function testFlashMultipleMessages(): void
    {
        $this->controller->publicFlash('error', 'First error');
        $this->controller->publicFlash('error', 'Second error');

        $this->assertSame(['First error', 'Second error'], $_SESSION['flash']['error']);
    }

    // ========================================
    // getFlash tests
    // ========================================

    public function testGetFlashReturnsAndClearsMessages(): void
    {
        session_start();
        $_SESSION['flash'] = ['success' => ['Test message']];

        $result = $this->controller->publicGetFlash();

        $this->assertSame(['success' => ['Test message']], $result);
        $this->assertArrayNotHasKey('flash', $_SESSION);
    }

    public function testGetFlashReturnsEmptyArrayWhenNoMessages(): void
    {
        unset($_SESSION['flash']);

        $result = $this->controller->publicGetFlash();

        $this->assertSame([], $result);
    }

    // ========================================
    // getUserFromSession tests
    // ========================================

    public function testGetUserFromSessionReturnsUserData(): void
    {
        $session = new Session([
            'id' => 1,
            'user_id' => 42,
            'display_name' => 'John Doe',
            'is_super_admin' => true,
        ]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('session')
            ->willReturn($session);

        $result = $this->controller->publicGetUserFromSession($request);

        $this->assertSame('John Doe', $result['display_name']);
        $this->assertTrue($result['is_super_admin']);
    }

    public function testGetUserFromSessionReturnsEmptyWhenNoSession(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('session')
            ->willReturn(null);

        $result = $this->controller->publicGetUserFromSession($request);

        $this->assertSame([], $result);
    }

    // ========================================
    // buildPermissionFlags tests
    // ========================================

    public function testBuildPermissionFlagsReturnsAllFalseWithoutService(): void
    {
        $result = $this->controller->publicBuildPermissionFlags(null, null, null);

        // All permissions should be false
        $this->assertArrayHasKey('transactions_view', $result);
        $this->assertFalse($result['transactions_view']);
        $this->assertFalse($result['settings_manage']);
    }

    public function testBuildPermissionFlagsChecksAllPermissions(): void
    {
        $membership = ['id' => 1, 'role' => 'admin'];
        $charityId = 42;

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')
            ->willReturnCallback(function ($m, $permission, $cid) {
                // Return true for some specific permissions
                return in_array($permission, ['transactions.view', 'settings.manage']);
            });

        $result = $this->controller->publicBuildPermissionFlags($permissionService, $membership, $charityId);

        $this->assertTrue($result['transactions_view']);
        $this->assertTrue($result['settings_manage']);
        $this->assertFalse($result['users_manage']);
    }

    // ========================================
    // get tests
    // ========================================

    public function testGetReturnsServiceFromContainer(): void
    {
        $service = new \stdClass();
        $this->container->method('get')
            ->with('MyService')
            ->willReturn($service);

        $result = $this->controller->publicGet('MyService');

        $this->assertSame($service, $result);
    }

    // ========================================
    // getCommonTemplateData tests
    // ========================================

    public function testGetCommonTemplateDataReturnsExpectedStructure(): void
    {
        $charity = ['id' => 1, 'name' => 'Test Charity'];
        $session = new Session([
            'id' => 1,
            'user_id' => 42,
            'display_name' => 'John Doe',
            'is_super_admin' => false,
        ]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(function ($name) use ($charity, $session) {
                return match ($name) {
                    'charity' => $charity,
                    'session' => $session,
                    'user_role' => 'admin',
                    'enabled_modules' => ['members', 'giftaid'],
                    'membership' => ['id' => 1, 'role' => 'admin'],
                    'charity_id' => 1,
                    'permission_service' => null,
                    default => null,
                };
            });

        $result = $this->controller->publicGetCommonTemplateData($request);

        $this->assertArrayHasKey('charity', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('user_role', $result);
        $this->assertArrayHasKey('enabled_modules', $result);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayHasKey('flash', $result);
        $this->assertEquals($charity, $result['charity']);
        $this->assertEquals('admin', $result['user_role']);
    }
}

/**
 * Concrete implementation of BaseController for testing
 */
class ConcreteTestController extends BaseController
{
    public function publicJson($response, array $data, int $status = 200)
    {
        return $this->json($response, $data, $status);
    }

    public function publicRedirect($response, string $url)
    {
        return $this->redirect($response, $url);
    }

    public function publicGetPostData($request)
    {
        return $this->getPostData($request);
    }

    public function publicGetQueryParams($request)
    {
        return $this->getQueryParams($request);
    }

    public function publicFlash(string $type, string $message)
    {
        return $this->flash($type, $message);
    }

    public function publicGetFlash()
    {
        return $this->getFlash();
    }

    public function publicGetUserFromSession($request)
    {
        return $this->getUserFromSession($request);
    }

    public function publicBuildPermissionFlags($permissionService, ?array $membership, ?int $charityId)
    {
        return $this->buildPermissionFlags($permissionService, $membership, $charityId);
    }

    public function publicGet(string $id)
    {
        return $this->get($id);
    }

    public function publicGetCommonTemplateData($request)
    {
        return $this->getCommonTemplateData($request);
    }
}
