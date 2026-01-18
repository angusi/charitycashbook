<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * Middleware to verify the charity session hasn't expired
 */
class CharitySessionMiddleware implements MiddlewareInterface
{
    private SessionManager $sessionManager;

    public function __construct(SessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        
        $charityId = (int) $route->getArgument('charity_id');
        $session = $request->getAttribute('session');
        $charity = $request->getAttribute('charity');

        // Check if charity session is still valid
        if (!$this->sessionManager->checkCharitySession($session, $charityId, $charity['session_timeout_minutes'])) {
            // Session expired - need re-authentication
            $response = new Response();
            return $response
                ->withHeader('Location', '/charities/' . $charityId . '/enter?expired=1')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
