<?php

declare(strict_types=1);

namespace CharityCashbook\Auth\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Auth\OAuthProviderService;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Charity\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Handles OAuth callback processing
 */
class OAuthCallbackController extends BaseController
{
    public function handle(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) $args['provider'];
        $params = $request->getQueryParams();

        // Check if this is a linking request
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $isLinking = $_SESSION['oauth_linking'] ?? false;
        unset($_SESSION['oauth_linking']);

        // Verify state
        $providerService = $this->get(OAuthProviderService::class);
        if (!$providerService->verifyState($params['state'] ?? '')) {
            $this->flash('error', 'Invalid authentication state. Please try again.');
            return $this->redirect($response, $isLinking ? '/account' : '/login');
        }

        // Determine redirect for errors - if user has a valid session, go to charities not login
        $sessionManager = $this->get(SessionManager::class);
        $existingSession = $sessionManager->validate();
        $errorRedirect = $isLinking ? '/account' : ($existingSession ? '/charities' : '/login');

        // Check for error from OAuth provider (including user clicking Cancel)
        if (isset($params['error'])) {
            // Clear any pending reauth context so user isn't immediately redirected back
            unset($_SESSION['charity_reauth']);
            unset($_SESSION['service_admin_reauth']);
            unset($_SESSION['pending_sensitive_action']);
            unset($_SESSION['oauth_is_reauth']);

            // Don't show scary error for user-initiated cancel
            if ($params['error'] === 'access_denied') {
                $this->flash('info', 'Authentication cancelled.');
            } else {
                $this->flash('error', 'Authentication failed: ' . ($params['error_description'] ?? $params['error']));
            }
            return $this->redirect($response, $errorRedirect);
        }

        if (!isset($params['code'])) {
            // Clear any pending reauth context
            unset($_SESSION['charity_reauth']);
            unset($_SESSION['service_admin_reauth']);
            unset($_SESSION['pending_sensitive_action']);
            unset($_SESSION['oauth_is_reauth']);

            $this->flash('error', 'No authorization code received');
            return $this->redirect($response, $errorRedirect);
        }

        try {
            // Exchange code for token
            $provider = $providerService->getProviderInstance($providerId);
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $params['code'],
            ]);

            // Get user info from provider
            $resourceOwner = $provider->getResourceOwner($token);
            $oauthData = $resourceOwner->toArray();
            
            $oauthSubject = $oauthData['sub'] ?? $oauthData['id'] ?? null;
            $oauthEmail = $oauthData['email'] ?? null;
            $displayName = $oauthData['name'] ?? $oauthEmail ?? 'User';

            if (!$oauthSubject || !$oauthEmail) {
                throw new \Exception('Could not get user information from provider');
            }

            // Handle account linking flow
            if ($isLinking) {
                return $this->handleLinking($request, $response, $providerId, $oauthSubject, $oauthEmail);
            }

            // Resolve identity for login
            $identityService = $this->get(IdentityService::class);
            $result = $identityService->resolveIdentity($providerId, $oauthSubject, $oauthEmail);

            // Log the attempt
            $auditService = $this->get(AuditService::class);

            if ($result['status'] === 'existing_user') {
                $sessionManager = $this->get(SessionManager::class);

                // Check if this is a reauth flow with an existing session
                $existingSession = $sessionManager->validate();
                if ($existingSession && $existingSession->getUserId() === $result['user']['id']) {
                    // Reauth flow - use existing session to preserve charity sessions
                    $sessionId = $existingSession->getId();
                } else {
                    // Fresh login - create new session
                    $newSession = $sessionManager->create($result['user']['id']);
                    $sessionId = $newSession['id'];
                }

                $auditService->logLogin($result['user']['id'], $providerId, $oauthEmail, true);

                // Regenerate PHP session ID to prevent session fixation
                // Preserve reauth data across regeneration
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }

                // Store the just-authenticated identity for use when entering charities
                // This allows skipping redundant OAuth when user just logged in
                $identity = $identityService->findIdentity($providerId, $oauthSubject);
                if ($identity) {
                    $_SESSION['recent_oauth_identity'] = [
                        'identity_id' => $identity['id'],
                        'provider_id' => $providerId,
                        'oauth_subject' => $oauthSubject,
                        'oauth_email' => $oauthEmail,
                        'display_name' => $displayName,
                        'authenticated_at' => time(),
                    ];
                }

                // Check if this login is to complete a "link new identity" flow
                $linkNewIdentity = $_SESSION['link_new_identity'] ?? null;
                if ($linkNewIdentity) {
                    unset($_SESSION['link_new_identity']);
                    return $this->completeLinkNewIdentity($response, $result['user']['id'], $linkNewIdentity);
                }

                $this->flash('success', 'Welcome back, ' . $result['user']['display_name']);

                // Check for re-auth redirects (charity or service admin)
                // Pass OAuth identity info for per-charity validation
                $redirectUrl = $this->getReauthRedirect(
                    $result['user'],
                    $sessionManager,
                    $sessionId,
                    $providerId,
                    $oauthSubject,
                    $oauthEmail,
                    $displayName
                );
                return $this->redirect($response, $redirectUrl);
            }

            // New identity - show account creation/linking options
            $_SESSION['new_identity'] = [
                'provider_id' => $providerId,
                'oauth_subject' => $oauthSubject,
                'oauth_email' => $oauthEmail,
                'display_name' => $displayName,
                'charities' => $result['charities'],
                'can_link' => $result['can_link'],
            ];

            // Render the new user page
            $view = Twig::fromRequest($request);
            return $view->render($response, 'auth/new_user.html.twig', [
                'email' => $oauthEmail,
                'display_name' => $displayName,
                'charities' => $result['charities'],
                'can_link' => $result['can_link'],
            ]);

        } catch (\Exception $e) {
            $auditService = $this->get(AuditService::class);
            $auditService->logLogin(null, $providerId, $oauthEmail ?? 'unknown', false, $e->getMessage());

            $this->flash('error', 'Authentication failed: ' . $e->getMessage());
            return $this->redirect($response, $errorRedirect);
        }
    }

    /**
     * Handle account linking callback
     */
    private function handleLinking(Request $request, Response $response, int $providerId, string $oauthSubject, string $oauthEmail): Response
    {
        // Get current user from session cookie (middleware not applied to this route)
        $sessionManager = $this->get(SessionManager::class);
        $cookies = $request->getCookieParams();
        $sessionToken = $cookies['charity_cashbook_session'] ?? null;
        
        if (!$sessionToken) {
            $this->flash('error', 'You must be logged in to link accounts.');
            return $this->redirect($response, '/login');
        }
        
        $session = $sessionManager->validate($sessionToken);
        if (!$session) {
            $this->flash('error', 'Your session has expired. Please log in again.');
            return $this->redirect($response, '/login');
        }

        $userId = $session->getUserId();
        
        try {
            $linkingService = $this->get(\CharityCashbook\Auth\AccountLinkingService::class);
            $token = $linkingService->createPendingLink($userId, $providerId, $oauthSubject, $oauthEmail);
            
            // Get user's primary email for confirmation
            $identityService = $this->get(IdentityService::class);
            $user = $identityService->getUser($userId);
            
            // Send confirmation email
            $config = $this->get('config');
            $linkingService->sendConfirmationEmail(
                $user['primary_email'],
                $token,
                $oauthEmail,
                $config['app']['url']
            );
            
            $this->flash('success', "Check your email at {$user['primary_email']} to confirm linking {$oauthEmail}.");
            return $this->redirect($response, '/account');
            
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to link account: ' . $e->getMessage());
            return $this->redirect($response, '/account');
        }
    }

    public function createAccount(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $newIdentity = $_SESSION['new_identity'] ?? null;
        if (!$newIdentity) {
            $this->flash('error', 'Session expired. Please try logging in again.');
            return $this->redirect($response, '/login');
        }

        try {
            $identityService = $this->get(IdentityService::class);
            $user = $identityService->createUser(
                $newIdentity['display_name'],
                $newIdentity['oauth_email'],
                $newIdentity['provider_id'],
                $newIdentity['oauth_subject'],
                $newIdentity['oauth_email']
            );

            // Clear the pending identity
            unset($_SESSION['new_identity']);

            // Create session
            $sessionManager = $this->get(SessionManager::class);
            $sessionManager->create($user['id']);

            $this->flash('success', 'Welcome to Charity Cashbook!');
            return $this->redirect($response, '/charities');

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to create account: ' . $e->getMessage());
            return $this->redirect($response, '/login');
        }
    }

    /**
     * Start the "link new identity to existing account" flow
     * 
     * This is called when a user with a NEW OAuth identity (not yet in the system)
     * wants to link it to their EXISTING account. They need to sign in with their
     * existing account to prove ownership.
     */
    public function linkToExisting(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $newIdentity = $_SESSION['new_identity'] ?? null;
        if (!$newIdentity) {
            $this->flash('error', 'Session expired. Please try logging in again.');
            return $this->redirect($response, '/login');
        }

        // Store that we're in a "link new identity" flow and redirect to login
        $_SESSION['link_new_identity'] = $newIdentity;
        unset($_SESSION['new_identity']);

        $this->flash('info', 'Sign in with your existing account to link it with ' . $newIdentity['oauth_email']);
        return $this->redirect($response, '/login');
    }

    /**
     * Complete the "link new identity to existing account" flow
     * 
     * Called when a user has signed in with their existing account after
     * initiating the link flow from a new OAuth identity.
     */
    private function completeLinkNewIdentity(Response $response, int $userId, array $newIdentity): Response
    {
        try {
            $identityService = $this->get(IdentityService::class);
            
            // Check if this identity is already linked (shouldn't happen, but safety check)
            $existing = $identityService->findIdentity(
                $newIdentity['provider_id'],
                $newIdentity['oauth_subject']
            );
            
            if ($existing) {
                $this->flash('error', 'This login method is already linked to another account.');
                return $this->redirect($response, '/charities');
            }
            
            // Link the new identity directly to the user's account
            $identityService->addIdentityToUser(
                $userId,
                $newIdentity['provider_id'],
                $newIdentity['oauth_subject'],
                $newIdentity['oauth_email']
            );
            
            // Also grant access to any charities the new email authorises
            foreach ($newIdentity['charities'] as $charity) {
                $identityService->addUserToCharityIfNotMember($userId, $charity['id']);
            }
            
            $this->flash('success', 'Successfully linked ' . $newIdentity['oauth_email'] . ' to your account!');
            return $this->redirect($response, '/charities');
            
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to link account: ' . $e->getMessage());
            return $this->redirect($response, '/charities');
        }
    }

    /**
     * Get the redirect URL after re-authentication
     *
     * Handles both service admin and charity re-auth flows.
     * Validates that the same user who initiated the reauth completes it,
     * preventing session fixation attacks on shared computers.
     *
     * @param array $user The authenticated user
     * @param SessionManager $sessionManager
     * @param int $sessionId
     * @param int $providerId OAuth provider ID
     * @param string $oauthSubject OAuth subject identifier
     * @param string $oauthEmail OAuth email
     * @param string|null $oauthDisplayName Display name from OAuth provider to sync
     */
    private function getReauthRedirect(
        array $user,
        SessionManager $sessionManager,
        int $sessionId,
        int $providerId,
        string $oauthSubject,
        string $oauthEmail,
        ?string $oauthDisplayName = null
    ): string {
        // Check if this was initiated as a reauth flow
        $isReauthFlow = $_SESSION['oauth_is_reauth'] ?? false;
        unset($_SESSION['oauth_is_reauth']);

        // If not a reauth flow, clear any stale reauth data and go to charities
        if (!$isReauthFlow) {
            unset($_SESSION['charity_reauth']);
            unset($_SESSION['pending_sensitive_action']);
            unset($_SESSION['service_admin_reauth']);
            unset($_SESSION['reauth_redirect']);
            return '/charities';
        }

        // Check for charity re-auth context first
        $charityReauth = $_SESSION['charity_reauth'] ?? null;
        unset($_SESSION['charity_reauth']);

        if ($charityReauth) {
            // Security: verify this is the same user who initiated the reauth
            $originalUserId = $charityReauth['user_id'] ?? null;
            if ($originalUserId !== null && $originalUserId !== $user['id']) {
                // Different user - ignore the reauth context (possible session fixation attempt)
                return '/charities';
            }

            $charityId = $charityReauth['charity_id'];
            $redirectUrl = $charityReauth['redirect_url'];

            $charityRepo = $this->get(\CharityCashbook\Charity\CharityRepository::class);
            $charity = $charityRepo->findById($charityId);
            $membership = $charityRepo->getUserMembership($charityId, $user['id']);

            if ($charity && $membership) {
                // Get the identity ID for this OAuth account
                $identityService = $this->get(IdentityService::class);
                $identity = $identityService->findIdentity($providerId, $oauthSubject);

                if (!$identity) {
                    // Identity not found - shouldn't happen but handle gracefully
                    $this->flash('error', 'Authentication error. Please try again.');
                    return '/charities';
                }

                // Verify this identity's email is authorized for this charity
                if (!$identityService->isIdentityAuthorizedForCharity($identity['id'], $charityId)) {
                    $this->flash('error', 'The account ' . $oauthEmail . ' is not authorized for ' . $charity['name'] . '. Please sign in with an authorized account.');
                    return '/charities';
                }

                // Store identity and email in charity_users
                $charityRepo->updateUserIdentity($charityId, $user['id'], $identity['id'], $oauthEmail);

                // Refresh the charity session with the identity ID
                $sessionManager->refreshCharitySession(
                    $sessionId,
                    $charityId,
                    $membership['role'],
                    $identity['id'],
                    $charity['session_timeout_hours']
                );

                // Sync display name from OAuth provider on reauth
                $charityRepo->updateLastAuthenticated($charityId, $user['id'], $oauthDisplayName);

                // Validate redirect URL is within this charity
                if ($redirectUrl && str_starts_with($redirectUrl, '/charity/' . $charityId)) {
                    return $redirectUrl;
                }

                return '/charity/' . $charityId;
            }
        }
        
        // Check for pending sensitive action (confirmation page flow)
        $pendingAction = $_SESSION['pending_sensitive_action'] ?? null;
        if ($pendingAction && ($user['is_super_admin'] ?? false)) {
            // Verify same user
            $originalUserId = $pendingAction['user_id'] ?? null;
            if ($originalUserId === null || $originalUserId === $user['id']) {
                // Redirect to confirmation page to complete the action
                return '/service-admin/confirm-action';
            }
            // Different user - clear the pending action
            unset($_SESSION['pending_sensitive_action']);
        }
        
        // Legacy: Check for service admin re-auth redirect (direct redirect flow)
        $serviceAdminReauth = $_SESSION['service_admin_reauth'] ?? null;
        unset($_SESSION['service_admin_reauth']);
        
        if ($serviceAdminReauth && ($user['is_super_admin'] ?? false)) {
            $originalUserId = $serviceAdminReauth['user_id'] ?? null;
            if ($originalUserId !== null && $originalUserId !== $user['id']) {
                return '/charities';
            }
            
            $redirectUrl = $serviceAdminReauth['redirect_url'] ?? null;
            if ($redirectUrl && str_starts_with($redirectUrl, '/service-admin')) {
                return $redirectUrl;
            }
        }
        
        return '/charities';
    }
}
