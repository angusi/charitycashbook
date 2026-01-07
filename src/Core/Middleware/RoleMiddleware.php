<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Core\Exceptions\AuthorisationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to check user has required role
 */
class RoleMiddleware implements MiddlewareInterface
{
    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userRole = $request->getAttribute('user_role');

        if (!in_array($userRole, $this->allowedRoles, true)) {
            throw new AuthorisationException('You do not have permission to perform this action');
        }

        return $handler->handle($request);
    }
}
