<?php

declare(strict_types=1);

namespace CharityCashbook\Auth\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Auth\OAuthProviderService;
use CharityCashbook\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Handles login page and OAuth redirects
 */
class LoginController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $isReauth = isset($params['reauth']);
        
        $sessionManager = $this->get(SessionManager::class);
        $session = $sessionManager->validate();
        
        // Get reauth context before destroying session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Set reauth flag for the OAuth callback to use
        // This must be set here (when user arrives at /login?reauth=1) not in redirectToProvider
        if ($isReauth) {
            $_SESSION['oauth_is_reauth'] = true;
        } else {
            // Normal login - clear any stale reauth data
            unset($_SESSION['oauth_is_reauth']);
            unset($_SESSION['pending_sensitive_action']);
            unset($_SESSION['charity_reauth']);
            unset($_SESSION['service_admin_reauth']);
            unset($_SESSION['reauth_redirect']);
        }
        
        $reauthContext = null;
        if ($isReauth) {
            // Build context for display - what action triggered this?
            if (isset($_SESSION['pending_sensitive_action'])) {
                $pendingAction = $_SESSION['pending_sensitive_action'];
                $reauthContext = [
                    'type' => 'service_admin',
                    'cancel_url' => $pendingAction['cancel_url'] ?? '/service-admin',
                    'description' => $this->getActionDescription($pendingAction['action'] ?? ''),
                ];
            } elseif (isset($_SESSION['charity_reauth'])) {
                $reauthContext = [
                    'type' => 'charity',
                    'cancel_url' => '/charities',
                    'description' => 'access this charity',
                ];
            }
        }
        
        // For reauth requests, DON'T destroy the session - we want to keep existing
        // charity sessions intact. The user just needs to prove their identity via OAuth.
        // Only a full logout should destroy the session.

        // If logged in and not a reauth request, redirect to charities
        if ($session && !$isReauth) {
            return $this->redirect($response, '/charities');
        }

        // Get available OAuth providers
        $providerService = $this->get(OAuthProviderService::class);
        $providers = $providerService->getEnabledProviders();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'auth/login.html.twig', [
            'providers' => $providers,
            'flash' => $this->getFlash(),
            'is_reauth' => $isReauth,
            'reauth_context' => $reauthContext,
        ]);
    }

    public function redirectToProvider(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) $args['provider'];

        $providerService = $this->get(OAuthProviderService::class);

        try {
            $provider = $providerService->getProviderInstance($providerId);
            $providerInfo = $providerService->getProviderById($providerId);
            $state = $providerService->generateState();

            // Store provider ID in session for callback
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['oauth_provider_id'] = $providerId;

            // The oauth_is_reauth flag should have been set by the login page
            // if the user arrived via /login?reauth=1. If not set, this is a normal login.
            // Don't check for pending_sensitive_action here as it may be stale from a cancelled action.

            // Build authorization URL options
            $authOptions = ['state' => $state];

            // Force account picker so users can switch between accounts
            // This is essential for multi-charity users who need different Google/Microsoft accounts
            if ($providerInfo) {
                $authOptions['prompt'] = match ($providerInfo['provider_type']) {
                    'google' => 'select_account',
                    'microsoft' => 'select_account',
                    default => 'login',  // Generic OIDC - try 'login' prompt
                };
            }

            $authUrl = $provider->getAuthorizationUrl($authOptions);

            return $response
                ->withHeader('Location', $authUrl)
                ->withStatus(302);
        } catch (\Exception $e) {
            $this->flash('error', 'Unable to connect to login provider');
            return $this->redirect($response, '/login');
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        $sessionManager = $this->get(SessionManager::class);
        $sessionManager->destroy();
        
        $this->flash('success', 'You have been logged out');
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    /**
     * Get human-readable description for a pending action type
     */
    private function getActionDescription(string $actionType): string
    {
        $descriptions = [
            'force_charity_reauth' => 'force all users of this charity to re-authenticate',
            'suspend_charity' => 'suspend this charity',
            'reactivate_charity' => 'reactivate this charity',
            'make_user_admin' => 'promote this user to admin',
            'toggle_provider' => 'toggle this OAuth provider',
            'delete_user_data' => 'permanently delete this user\'s data',
            'terminate_user_sessions' => 'terminate all sessions for this user',
            'force_global_reauth' => 'force all users to re-authenticate',
        ];
        
        return $descriptions[$actionType] ?? 'perform this administrative action';
    }
}
