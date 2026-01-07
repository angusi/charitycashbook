<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Middleware to ensure user is authenticated
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    private SessionManager $sessionManager;

    public function __construct(SessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->sessionManager->validate();

        if ($session === null) {
            // Not authenticated - redirect to login
            $response = new Response();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        // Add session data to request attributes
        $request = $request->withAttribute('session', $session);
        $request = $request->withAttribute('user_id', $session->getUserId());
        $request = $request->withAttribute('is_super_admin', $session->isSuperAdmin());

        return $handler->handle($request);
    }
}
