<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Core\Exceptions\AuthorisationException;
use CharityCashbook\Charity\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to check user has required permission
 */
class PermissionMiddleware implements MiddlewareInterface
{
    private string $permission;

    public function __construct(string $permission)
    {
        $this->permission = $permission;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $membership = $request->getAttribute('membership');
        $charityId = $request->getAttribute('charity_id');
        $permissionService = $request->getAttribute('permission_service');

        if (!$permissionService instanceof PermissionService) {
            throw new AuthorisationException('Permission service not available');
        }

        if (!$permissionService->hasPermission($membership, $this->permission, $charityId)) {
            throw new AuthorisationException('You do not have permission to perform this action');
        }

        return $handler->handle($request);
    }
}
