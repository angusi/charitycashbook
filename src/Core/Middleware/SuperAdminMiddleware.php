<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Core\Exceptions\AuthorisationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to ensure user is a super-admin
 */
class SuperAdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isSuperAdmin = $request->getAttribute('is_super_admin', false);

        if (!$isSuperAdmin) {
            throw new AuthorisationException('Super-admin access required');
        }

        return $handler->handle($request);
    }
}
