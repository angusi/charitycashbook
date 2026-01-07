<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ModuleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * Middleware to verify user has access to the requested charity
 */
class CharityAccessMiddleware implements MiddlewareInterface
{
    private CharityRepository $charityRepo;
    private PermissionService $permissionService;
    private ModuleService $moduleService;

    public function __construct(
        CharityRepository $charityRepo, 
        PermissionService $permissionService,
        ModuleService $moduleService
    ) {
        $this->charityRepo = $charityRepo;
        $this->permissionService = $permissionService;
        $this->moduleService = $moduleService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        
        $charityId = (int) $route->getArgument('charity_id');
        $userId = $request->getAttribute('user_id');

        // Check charity exists and is active
        $charity = $this->charityRepo->findById($charityId);
        if ($charity === null || !$charity['is_active']) {
            $response = new Response();
            return $response
                ->withHeader('Location', '/charities')
                ->withStatus(302);
        }

        // Check user has access to this charity
        $membership = $this->charityRepo->getUserMembership($charityId, $userId);
        if ($membership === null || !$membership['is_active']) {
            $response = new Response();
            return $response
                ->withHeader('Location', '/charities')
                ->withStatus(302);
        }

        // Check if user is pending approval
        if ($membership['role'] === 'pending') {
            $response = new Response();
            return $response
                ->withHeader('Location', "/charity/$charityId/pending")
                ->withStatus(302);
        }

        // Get enabled modules for navigation
        $enabledModules = $this->moduleService->getEnabledModules($charityId);

        // Add charity and membership to request
        $request = $request->withAttribute('charity', $charity);
        $request = $request->withAttribute('charity_id', $charityId);
        $request = $request->withAttribute('membership', $membership);
        $request = $request->withAttribute('user_role', $membership['role']);
        $request = $request->withAttribute('permission_service', $this->permissionService);
        $request = $request->withAttribute('module_service', $this->moduleService);
        $request = $request->withAttribute('enabled_modules', $enabledModules);

        return $handler->handle($request);
    }
}
