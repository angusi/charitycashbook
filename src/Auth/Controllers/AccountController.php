<?php

declare(strict_types=1);

namespace CharityCashbook\Auth\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Auth\AccountLinkingService;
use CharityCashbook\Auth\OAuthProviderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Account management controller
 */
class AccountController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $session = $request->getAttribute('session');
        
        $identityService = $this->get(IdentityService::class);
        $sessionManager = $this->get(SessionManager::class);
        $accountLinking = $this->get(AccountLinkingService::class);
        
        $user = $identityService->getUser($userId);
        $identities = $identityService->getIdentitiesForUser($userId);
        $sessions = $sessionManager->getSessionsForUser($userId);
        $pendingLinks = $accountLinking->getPendingLinks($userId);
        $charities = $identityService->getUserCharities($userId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'auth/account.html.twig', [
            'user' => [
                'display_name' => $user['display_name'],
                'email' => $user['primary_email'],
                'created_at' => $user['created_at'],
                'is_super_admin' => (bool) $user['is_super_admin'],
            ],
            'identities' => $identities,
            'sessions' => $sessions,
            'pending_links' => $pendingLinks,
            'charities' => $charities,
            'flash' => $this->getFlash(),
        ]);
    }

    public function linkStart(Request $request, Response $response): Response
    {
        $providerService = $this->get(OAuthProviderService::class);
        $providers = $providerService->getEnabledProviders();
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'auth/link_start.html.twig', [
            'providers' => $providers,
            'flash' => $this->getFlash(),
        ]);
    }

    public function linkRedirect(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) $args['provider'];
        
        $providerService = $this->get(OAuthProviderService::class);
        
        try {
            // Use the standard provider instance (same redirect URI as login)
            $provider = $providerService->getProviderInstance($providerId);
            
            $state = $providerService->generateState();
            
            // Store that this is a linking request
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['oauth_linking'] = true;
            $_SESSION['oauth_provider_id'] = $providerId;
            
            $authUrl = $provider->getAuthorizationUrl(['state' => $state]);
            
            return $response
                ->withHeader('Location', $authUrl)
                ->withStatus(302);
        } catch (\Exception $e) {
            $this->flash('error', 'Unable to connect to provider: ' . $e->getMessage());
            return $this->redirect($response, '/account');
        }
    }

    public function linkConfirm(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];
        
        try {
            $accountLinking = $this->get(AccountLinkingService::class);
            $user = $accountLinking->confirmLink($token);
            
            $this->flash('success', 'Account linked successfully!');
            return $this->redirect($response, '/account');
            
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, '/account');
        }
    }

    public function linkCancel(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        $token = $data['token'] ?? '';
        $userId = $request->getAttribute('user_id');
        
        $accountLinking = $this->get(AccountLinkingService::class);
        $accountLinking->cancelPendingLink($userId, $token);
        
        $this->flash('success', 'Pending link cancelled.');
        return $this->redirect($response, '/account');
    }

    public function sessions(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        $sessionManager = $this->get(SessionManager::class);
        $sessions = $sessionManager->getSessionsForUser($userId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'auth/sessions.html.twig', [
            'sessions' => $sessions,
            'flash' => $this->getFlash(),
        ]);
    }

    public function logoutAll(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        $sessionManager = $this->get(SessionManager::class);
        $sessionManager->destroyAllForUser($userId);
        
        // The current session is now destroyed, redirect to login
        return $this->redirect($response, '/login');
    }
}
