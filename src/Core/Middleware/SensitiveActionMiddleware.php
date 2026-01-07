<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * Middleware requiring recent authentication for sensitive/destructive actions
 * 
 * This protects against session hijacking by ensuring the user has
 * authenticated recently before performing irreversible operations.
 */
class SensitiveActionMiddleware implements MiddlewareInterface
{
    /** Maximum age of authentication for sensitive actions (in seconds) */
    private const MAX_AUTH_AGE_SECONDS = 900; // 15 minutes

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute('session');
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        
        $charityId = $route ? $route->getArgument('charity_id') : null;
        
        if ($charityId && $session) {
            $charitySessions = $session->getCharitySessions();
            $charitySession = $charitySessions[(string) $charityId] ?? null;
            
            if ($charitySession) {
                $authenticatedAt = $charitySession['authenticated_at'] ?? 0;
                $authAge = time() - $authenticatedAt;
                
                if ($authAge <= self::MAX_AUTH_AGE_SECONDS) {
                    // Recent authentication - allow the action
                    return $handler->handle($request);
                }
            }
        }
        
        // Authentication too old or missing - redirect to re-authenticate
        $response = new Response();
        
        if ($charityId) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            
            // For POST requests, redirect back to the referring page after reauth
            // (we can't replay the POST, so user will need to click the button again)
            $method = $request->getMethod();
            $charityPrefix = '/charity/' . $charityId;
            
            if ($method === 'POST') {
                $referer = $request->getHeaderLine('Referer');
                $refererPath = $referer ? parse_url($referer, PHP_URL_PATH) : null;
                $redirectUrl = ($refererPath && str_starts_with($refererPath, $charityPrefix)) 
                    ? $refererPath 
                    : $charityPrefix;
            } else {
                $redirectUrl = (string) $request->getUri();
            }
            
            $_SESSION['reauth_redirect'] = $redirectUrl;
            $_SESSION['flash']['warning'][] = 'This action requires recent authentication. Please sign in again, then repeat the action.';
            
            return $response
                ->withHeader('Location', '/charities/' . $charityId . '/enter?reauth=1')
                ->withStatus(302);
        }
        
        // Fallback for non-charity routes
        $_SESSION['flash']['warning'][] = 'This action requires recent authentication.';
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
}
