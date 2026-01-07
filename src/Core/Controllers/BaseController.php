<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Controllers;

use CharityCashbook\Core\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;

/**
 * Base controller with common functionality
 */
abstract class BaseController
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Get a service from the container
     */
    protected function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    /**
     * Get common template data from request attributes
     */
    protected function getCommonTemplateData($request): array
    {
        $membership = $request->getAttribute('membership');
        $charityId = $request->getAttribute('charity_id');
        $permissionService = $request->getAttribute('permission_service');
        
        // Build permissions array for templates
        $permissions = $this->buildPermissionFlags($permissionService, $membership, $charityId !== null ? (int)$charityId : null);
        
        return [
            'charity' => $request->getAttribute('charity'),
            'user' => $this->getUserFromSession($request),
            'user_role' => $request->getAttribute('user_role'),
            'enabled_modules' => $request->getAttribute('enabled_modules', []),
            'permissions' => $permissions,
            // Legacy flags for backwards compatibility
            'can_export_privacy' => $permissions['privacy_export'] ?? false,
            'can_delete_privacy' => $permissions['privacy_delete'] ?? false,
            'flash' => $this->getFlash(),
        ];
    }

    /**
     * Build permission flags for templates
     */
    protected function buildPermissionFlags($permissionService, ?array $membership, ?int $charityId): array
    {
        $permissions = [
            // Transactions
            'transactions_view' => false,
            'transactions_create' => false,
            'transactions_edit' => false,
            'transactions_delete' => false,
            // Donors
            'donors_view_list' => false,
            'donors_view_detail' => false,
            'donors_create' => false,
            'donors_edit' => false,
            'donors_export' => false,
            // Members
            'members_view_list' => false,
            'members_view_detail' => false,
            'members_create' => false,
            'members_edit' => false,
            'members_manage_dues' => false,
            'members_manage_trustees' => false,
            // Gift Aid
            'giftaid_view' => false,
            'giftaid_manage_claims' => false,
            // Cash Books
            'cashbooks_view' => false,
            'cashbooks_manage' => false,
            'cashbooks_reconcile' => false,
            // Funds
            'funds_view' => false,
            'funds_manage' => false,
            // Categories
            'categories_view' => false,
            'categories_manage' => false,
            // Reports
            'reports_view' => false,
            'reports_export' => false,
            // Settings
            'settings_view' => false,
            'settings_manage' => false,
            'users_view' => false,
            'users_manage' => false,
            // Privacy
            'privacy_export' => false,
            'privacy_delete' => false,
        ];

        if (!$permissionService || !$membership) {
            return $permissions;
        }

        // Check each permission
        foreach ($permissions as $key => $value) {
            // Convert template key (underscore) to permission key (dot)
            // Only replace the FIRST underscore (e.g., members_manage_trustees -> members.manage_trustees)
            $permissionKey = preg_replace('/_/', '.', $key, 1);
            $permissions[$key] = $permissionService->hasPermission($membership, $permissionKey, $charityId);
        }

        return $permissions;
    }

    /**
     * Render a Twig template
     */
    protected function render(Response $response, string $template, array $data = [], $request = null): Response
    {
        // Add flash messages to data
        $data['flash'] = $this->getFlash();
        
        $view = Twig::fromRequest($request ?? $this->currentRequest ?? null);
        if ($view) {
            return $view->render($response, $template, $data);
        }
        
        // Fallback if Twig not available from request
        $response->getBody()->write("Template: $template");
        return $response;
    }

    /**
     * Return JSON response
     */
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Redirect to a URL
     */
    protected function redirect(Response $response, string $url): Response
    {
        return $response
            ->withHeader('Location', $url)
            ->withStatus(302);
    }

    /**
     * Get POST body as array
     */
    protected function getPostData($request): array
    {
        return $request->getParsedBody() ?? [];
    }

    /**
     * Get query parameters
     */
    protected function getQueryParams($request): array
    {
        return $request->getQueryParams();
    }

    /**
     * Add flash message (stored in session)
     */
    protected function flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['flash'][$type][] = $message;
    }

    /**
     * Get and clear flash messages
     */
    protected function getFlash(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }

    /**
     * Get user data for templates from session
     */
    protected function getUserFromSession($request): array
    {
        $session = $request->getAttribute('session');
        if (!$session) {
            return [];
        }
        return [
            'display_name' => $session->getDisplayName(),
            'is_super_admin' => $session->isSuperAdmin(),
        ];
    }
}
