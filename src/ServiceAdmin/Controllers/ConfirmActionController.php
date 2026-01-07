<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\DatabaseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Handles confirmation flow for sensitive service admin actions
 * 
 * Flow:
 * 1. User clicks action button (e.g. "Force Reauth All")
 * 2. If auth is stale, ServiceAdminSensitiveActionMiddleware stores action and redirects here
 * 3. This shows confirmation page with "Confirm Identity" button
 * 4. User authenticates via OAuth
 * 5. Returns here, now shows "Execute" button
 * 6. User clicks Execute, action is performed
 */
class ConfirmActionController extends BaseController
{
    /** 
     * Action definitions - what each action does and how to execute it
     * Key is the action type stored in session
     */
    private const ACTIONS = [
        'force_charity_reauth' => [
            'title' => 'Force Re-authentication',
            'description' => 'All users of this charity will be logged out and required to sign in again.',
            'confirm_button' => 'Force Re-authentication',
            'icon' => 'warning',
        ],
        'suspend_charity' => [
            'title' => 'Suspend Charity',
            'description' => 'This charity will be suspended. Users will not be able to access it until reactivated.',
            'confirm_button' => 'Suspend Charity',
            'icon' => 'danger',
        ],
        'reactivate_charity' => [
            'title' => 'Reactivate Charity',
            'description' => 'This charity will be reactivated and users will regain access.',
            'confirm_button' => 'Reactivate Charity',
            'icon' => 'success',
        ],
        'make_user_admin' => [
            'title' => 'Promote User to Admin',
            'description' => 'This user will be given full administrative access to the charity.',
            'confirm_button' => 'Make Admin',
            'icon' => 'warning',
        ],
        'toggle_provider' => [
            'title' => 'Toggle OAuth Provider',
            'description' => 'This will enable or disable this authentication provider for all users.',
            'confirm_button' => 'Toggle Provider',
            'icon' => 'warning',
        ],
        'delete_user_data' => [
            'title' => 'Delete User Data (GDPR)',
            'description' => 'This will permanently delete all personal data for this user. This cannot be undone.',
            'confirm_button' => 'Permanently Delete',
            'icon' => 'danger',
        ],
        'terminate_user_sessions' => [
            'title' => 'Terminate User Sessions',
            'description' => 'All active sessions for this user will be terminated immediately.',
            'confirm_button' => 'Terminate Sessions',
            'icon' => 'warning',
        ],
        'force_global_reauth' => [
            'title' => 'Force Global Re-authentication',
            'description' => 'ALL users across ALL charities will be logged out. Use with extreme caution.',
            'confirm_button' => 'Force Global Re-auth',
            'icon' => 'danger',
        ],
    ];

    /**
     * Show confirmation page
     */
    public function show(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $pendingAction = $_SESSION['pending_sensitive_action'] ?? null;
        
        if (!$pendingAction) {
            $this->flash('error', 'No pending action to confirm.');
            return $this->redirect($response, '/service-admin');
        }
        
        $actionType = $pendingAction['action'] ?? null;
        $actionDef = self::ACTIONS[$actionType] ?? null;
        
        if (!$actionDef) {
            unset($_SESSION['pending_sensitive_action']);
            $this->flash('error', 'Unknown action type.');
            return $this->redirect($response, '/service-admin');
        }
        
        // Check if user has recently authenticated (within 15 minutes)
        $createdAt = $session->getCreatedAt();
        $authTimestamp = $createdAt ? strtotime($createdAt) : 0;
        $isRecentlyAuthenticated = (time() - $authTimestamp) <= 900;
        
        // Get additional context for display
        $context = $this->getActionContext($pendingAction);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/confirm-action.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'action' => $actionDef,
            'action_type' => $actionType,
            'context' => $context,
            'is_recently_authenticated' => $isRecentlyAuthenticated,
            'cancel_url' => $pendingAction['cancel_url'] ?? '/service-admin',
            'flash' => $this->getFlash(),
        ]);
    }

    /**
     * Execute the confirmed action
     */
    public function execute(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $pendingAction = $_SESSION['pending_sensitive_action'] ?? null;
        
        if (!$pendingAction) {
            $this->flash('error', 'No pending action to execute.');
            return $this->redirect($response, '/service-admin');
        }
        
        // Verify recent authentication
        $createdAt = $session->getCreatedAt();
        $authTimestamp = $createdAt ? strtotime($createdAt) : 0;
        if ((time() - $authTimestamp) > 900) {
            $this->flash('error', 'Authentication has expired. Please confirm your identity again.');
            return $this->redirect($response, '/service-admin/confirm-action');
        }
        
        // Verify same user
        $originalUserId = $pendingAction['user_id'] ?? null;
        if ($originalUserId !== null && $originalUserId !== $session->getUserId()) {
            unset($_SESSION['pending_sensitive_action']);
            $this->flash('error', 'Session mismatch. Please try the action again.');
            return $this->redirect($response, '/service-admin');
        }
        
        // Clear the pending action
        unset($_SESSION['pending_sensitive_action']);
        
        // Execute the action
        $actionType = $pendingAction['action'];
        $params = $pendingAction['params'] ?? [];
        
        return $this->executeAction($request, $response, $session, $actionType, $params);
    }

    /**
     * Get display context for the pending action
     */
    private function getActionContext(array $pendingAction): array
    {
        $db = $this->get(DatabaseInterface::class);
        $context = [];
        $params = $pendingAction['params'] ?? [];
        
        // Load charity name if applicable
        if (isset($params['charity_id'])) {
            $charity = $db->queryOne(
                "SELECT name FROM charities WHERE id = ?",
                [$params['charity_id']]
            );
            $context['charity_name'] = $charity['name'] ?? 'Unknown Charity';
        }
        
        // Load user name if applicable
        if (isset($params['user_id'])) {
            $user = $db->queryOne(
                "SELECT display_name, id FROM users WHERE id = ?",
                [$params['user_id']]
            );
            $context['target_user_name'] = $user['display_name'] ?? 'Unknown User';
        }
        
        // Load provider name if applicable
        if (isset($params['provider_id'])) {
            $provider = $db->queryOne(
                "SELECT name FROM oauth_providers WHERE id = ?",
                [$params['provider_id']]
            );
            $context['provider_name'] = $provider['name'] ?? 'Unknown Provider';
        }
        
        return $context;
    }

    /**
     * Execute the actual action after confirmation
     */
    private function executeAction(Request $request, Response $response, $session, string $actionType, array $params): Response
    {
        $db = $this->get(DatabaseInterface::class);
        
        switch ($actionType) {
            case 'force_charity_reauth':
                return $this->doForceCharityReauth($response, $db, $session, $params);
                
            case 'suspend_charity':
                return $this->doSuspendCharity($response, $db, $params);
                
            case 'reactivate_charity':
                return $this->doReactivateCharity($response, $db, $params);
                
            case 'make_user_admin':
                return $this->doMakeUserAdmin($response, $db, $params);
                
            case 'toggle_provider':
                return $this->doToggleProvider($response, $db, $params);
                
            case 'force_global_reauth':
                return $this->doForceGlobalReauth($response, $db, $session);
                
            case 'delete_user_data':
            case 'terminate_user_sessions':
                // These need more complex handling - redirect to their original controllers
                $this->flash('error', 'This action type requires direct handling.');
                return $this->redirect($response, '/service-admin');
                
            default:
                $this->flash('error', 'Unknown action type.');
                return $this->redirect($response, '/service-admin');
        }
    }

    private function doForceCharityReauth(Response $response, DatabaseInterface $db, $session, array $params): Response
    {
        $charityId = $params['charity_id'];
        
        $charity = $db->queryOne("SELECT name FROM charities WHERE id = ?", [$charityId]);
        if (!$charity) {
            $this->flash('error', 'Charity not found.');
            return $this->redirect($response, '/service-admin/charities');
        }
        
        $userIds = $db->query(
            "SELECT DISTINCT user_id FROM charity_users WHERE charity_id = ?",
            [$charityId]
        );
        
        if (empty($userIds)) {
            $this->flash('info', 'No users found for this charity.');
            return $this->redirect($response, '/service-admin/charities/' . $charityId);
        }
        
        $currentSessionId = $session->getId();
        $ids = array_column($userIds, 'user_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $count = $db->execute(
            "DELETE FROM sessions WHERE user_id IN ($placeholders) AND id != ?",
            [...$ids, $currentSessionId]
        );
        
        $this->logAction('force_charity_reauth', [
            'charity_id' => $charityId,
            'charity_name' => $charity['name'],
            'sessions_terminated' => $count,
        ]);
        
        $this->flash('success', "Forced re-authentication for {$charity['name']}. $count sessions terminated.");
        return $this->redirect($response, '/service-admin/charities/' . $charityId);
    }

    private function doSuspendCharity(Response $response, DatabaseInterface $db, array $params): Response
    {
        $charityId = $params['charity_id'];
        
        $db->execute("UPDATE charities SET is_active = FALSE WHERE id = ?", [$charityId]);
        
        $charity = $db->queryOne("SELECT name FROM charities WHERE id = ?", [$charityId]);
        
        $this->logAction('suspend_charity', ['charity_id' => $charityId, 'charity_name' => $charity['name'] ?? '']);
        $this->flash('success', 'Charity suspended.');
        
        return $this->redirect($response, '/service-admin/charities/' . $charityId);
    }

    private function doReactivateCharity(Response $response, DatabaseInterface $db, array $params): Response
    {
        $charityId = $params['charity_id'];
        
        $db->execute("UPDATE charities SET is_active = TRUE WHERE id = ?", [$charityId]);
        
        $charity = $db->queryOne("SELECT name FROM charities WHERE id = ?", [$charityId]);
        
        $this->logAction('reactivate_charity', ['charity_id' => $charityId, 'charity_name' => $charity['name'] ?? '']);
        $this->flash('success', 'Charity reactivated.');
        
        return $this->redirect($response, '/service-admin/charities/' . $charityId);
    }

    private function doMakeUserAdmin(Response $response, DatabaseInterface $db, array $params): Response
    {
        $charityId = $params['charity_id'];
        $userId = $params['user_id'];
        
        $db->execute(
            "UPDATE charity_users SET role = 'admin' WHERE charity_id = ? AND user_id = ?",
            [$charityId, $userId]
        );
        
        $user = $db->queryOne("SELECT display_name FROM users WHERE id = ?", [$userId]);
        
        $this->logAction('make_user_admin', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'user_name' => $user['display_name'] ?? '',
        ]);
        $this->flash('success', ($user['display_name'] ?? 'User') . ' promoted to admin.');
        
        return $this->redirect($response, '/service-admin/charities/' . $charityId);
    }

    private function doToggleProvider(Response $response, DatabaseInterface $db, array $params): Response
    {
        $providerId = $params['provider_id'];
        
        $provider = $db->queryOne("SELECT name, is_enabled FROM oauth_providers WHERE id = ?", [$providerId]);
        if (!$provider) {
            $this->flash('error', 'Provider not found.');
            return $this->redirect($response, '/service-admin/providers');
        }
        
        $newState = !$provider['is_enabled'];
        $db->execute("UPDATE oauth_providers SET is_enabled = ? WHERE id = ?", [$newState, $providerId]);
        
        $this->logAction('toggle_provider', [
            'provider_id' => $providerId,
            'provider_name' => $provider['name'],
            'new_state' => $newState ? 'enabled' : 'disabled',
        ]);
        
        $this->flash('success', $provider['name'] . ' ' . ($newState ? 'enabled' : 'disabled') . '.');
        return $this->redirect($response, '/service-admin/providers');
    }

    private function doForceGlobalReauth(Response $response, DatabaseInterface $db, $session): Response
    {
        $currentSessionId = $session->getId();
        
        $count = $db->execute("DELETE FROM sessions WHERE id != ?", [$currentSessionId]);
        
        $this->logAction('force_global_reauth', ['sessions_terminated' => $count]);
        $this->flash('success', "Global re-authentication forced. $count sessions terminated.");
        
        return $this->redirect($response, '/service-admin/maintenance');
    }

    private function logAction(string $action, array $details): void
    {
        $logger = $this->get(\CharityCashbook\Core\Logger::class);
        $logger->info("Service admin action: $action", $details);
    }
}
