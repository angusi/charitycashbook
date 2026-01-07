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
 * Middleware requiring recent authentication for sensitive service admin actions
 * 
 * When auth is stale, stores the pending action in session and redirects to
 * a confirmation page where the user can verify their identity and execute the action.
 */
class ServiceAdminSensitiveActionMiddleware implements MiddlewareInterface
{
    /** Maximum age of authentication for sensitive actions (in seconds) */
    private const MAX_AUTH_AGE_SECONDS = 900; // 15 minutes

    /** Map route patterns to action types */
    private const ROUTE_ACTIONS = [
        'POST /service-admin/charities/{id}/suspend' => 'suspend_charity',
        'POST /service-admin/charities/{id}/reactivate' => 'reactivate_charity',
        'POST /service-admin/charities/{id}/force-reauth' => 'force_charity_reauth',
        'POST /service-admin/charities/{id}/users/{user_id}/make-admin' => 'make_user_admin',
        'POST /service-admin/providers/{id}/toggle' => 'toggle_provider',
        'POST /service-admin/maintenance/force-global-reauth' => 'force_global_reauth',
        'POST /service-admin/privacy/users/{id}/delete' => 'delete_user_data',
        'POST /service-admin/privacy/users/{id}/terminate-sessions' => 'terminate_user_sessions',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute('session');
        
        // Always redirect to confirmation page for sensitive actions
        // The confirmation page will show "Confirm Identity" or "Execute" based on auth age
        $response = new Response();
        
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Determine action type and parameters from route
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $method = $request->getMethod();
        $routePattern = $route ? $route->getPattern() : '';
        
        $actionKey = $method . ' ' . $routePattern;
        $actionType = self::ROUTE_ACTIONS[$actionKey] ?? 'unknown';
        
        // Extract parameters from route
        $params = [];
        if ($route) {
            $args = $route->getArguments();
            if (isset($args['id'])) {
                // Determine if this is charity_id, provider_id, or user_id based on route
                if (str_contains($routePattern, '/charities/')) {
                    $params['charity_id'] = (int) $args['id'];
                } elseif (str_contains($routePattern, '/providers/')) {
                    $params['provider_id'] = (int) $args['id'];
                } elseif (str_contains($routePattern, '/privacy/users/')) {
                    $params['user_id'] = (int) $args['id'];
                }
            }
            if (isset($args['user_id'])) {
                $params['user_id'] = (int) $args['user_id'];
            }
        }
        
        // Determine cancel URL from referer or default
        $referer = $request->getHeaderLine('Referer');
        $refererPath = $referer ? parse_url($referer, PHP_URL_PATH) : null;
        $cancelUrl = ($refererPath && str_starts_with($refererPath, '/service-admin')) 
            ? $refererPath 
            : '/service-admin';
        
        // Store pending action
        $_SESSION['pending_sensitive_action'] = [
            'action' => $actionType,
            'params' => $params,
            'user_id' => $session ? $session->getUserId() : null,
            'cancel_url' => $cancelUrl,
            'created_at' => time(),
        ];
        
        return $response
            ->withHeader('Location', '/service-admin/confirm-action')
            ->withStatus(302);
    }
}
