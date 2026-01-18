<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Charity\CharityRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Charity selection controller
 */
class CharitySelectionController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $session = $request->getAttribute('session');

        // Clear any pending reauth context - user is back at charity selection
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['charity_reauth']);
        unset($_SESSION['oauth_is_reauth']);

        $identityService = $this->get(IdentityService::class);
        $sessionManager = $this->get(SessionManager::class);
        $charities = $identityService->getUserCharities($userId);
        $suspendedCharities = $identityService->getUserSuspendedCharities($userId);

        // Check session status for each charity
        foreach ($charities as &$charity) {
            $charity['has_valid_session'] = $sessionManager->checkCharitySession(
                $session,
                $charity['id'],
                $charity['session_timeout_minutes'] ?? 480
            );
        }
        unset($charity); // Break reference

        // If user only has one charity (and no suspended ones), redirect directly
        if (count($charities) === 1 && count($suspendedCharities) === 0) {
            return $this->redirect($response, '/charity/' . $charities[0]['id']);
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/select.html.twig', [
            'charities' => $charities,
            'suspended_charities' => $suspendedCharities,
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'flash' => $this->getFlash(),
        ]);
    }

    public function enter(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $userId = $request->getAttribute('user_id');
        $session = $request->getAttribute('session');
        $params = $this->getQueryParams($request);
        
        $charityRepo = $this->get(CharityRepository::class);
        $charity = $charityRepo->findById($charityId);
        
        if (!$charity || !$charity['is_active']) {
            $this->flash('error', 'Charity not found or inactive.');
            return $this->redirect($response, '/charities');
        }
        
        $membership = $charityRepo->getUserMembership($charityId, $userId);
        if (!$membership || !$membership['is_active']) {
            $this->flash('error', 'You do not have access to this charity.');
            return $this->redirect($response, '/charities');
        }
        
        // Check if this is a re-authentication request for sensitive action (not just expired session)
        $isSensitiveReauth = isset($params['reauth']);
        
        // Check if charity session exists and is valid
        $sessionManager = $this->get(SessionManager::class);
        $hasValidSession = $sessionManager->checkCharitySession(
            $session,
            $charityId,
            $charity['session_timeout_minutes']
        );
        
        if ($hasValidSession && !$isSensitiveReauth) {
            // Session is valid and not a sensitive action reauth request, enter charity
            return $this->redirect($response, '/charity/' . $charityId);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Check if user just logged in with an identity that authorizes this charity
        // This avoids requiring a second OAuth when user just authenticated
        if (!$isSensitiveReauth) {
            $recentIdentity = $_SESSION['recent_oauth_identity'] ?? null;

            if ($recentIdentity) {
                // Only use if authenticated within the last 5 minutes
                $identityAge = time() - ($recentIdentity['authenticated_at'] ?? 0);
                if ($identityAge < 300) {
                    $identityService = $this->get(IdentityService::class);

                    // Check if this identity authorizes the charity
                    if ($identityService->isIdentityAuthorizedForCharity($recentIdentity['identity_id'], $charityId)) {
                        // Great! Use this identity to establish the charity session
                        $charityRepo->updateUserIdentity(
                            $charityId,
                            $userId,
                            $recentIdentity['identity_id'],
                            $recentIdentity['oauth_email']
                        );

                        $sessionManager->refreshCharitySession(
                            $session->getId(),
                            $charityId,
                            $membership['role'],
                            $recentIdentity['identity_id'],
                            $charity['session_timeout_minutes']
                        );

                        // Update last authenticated timestamp and sync display name
                        $charityRepo->updateLastAuthenticated(
                            $charityId,
                            $userId,
                            $recentIdentity['display_name'] ?? null
                        );

                        return $this->redirect($response, '/charity/' . $charityId);
                    }
                }
            }
        }

        // No valid session and no recent authorized identity - require OAuth authentication
        // Store charity context for post-OAuth processing
        // Include user_id to verify same user completes the reauth
        $_SESSION['charity_reauth'] = [
            'user_id' => $userId,
            'charity_id' => $charityId,
            'redirect_url' => $isSensitiveReauth ? ($_SESSION['reauth_redirect'] ?? null) : null,
        ];
        unset($_SESSION['reauth_redirect']);

        // Redirect to login with reauth flag (this forces OAuth)
        return $this->redirect($response, '/login?reauth=1');
    }
}
