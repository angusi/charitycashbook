<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\PrivacyService;
use CharityCashbook\Core\DatabaseInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Controller for GDPR privacy operations at the service admin level
 */
class PrivacyController extends BaseController
{
    /**
     * Privacy dashboard with search
     */
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $params = $this->getQueryParams($request);
        
        $searchResults = null;
        $searchTerm = $params['search'] ?? '';

        if ($searchTerm) {
            $privacyService = $this->get(PrivacyService::class);
            $searchResults = $privacyService->searchAllCharities($searchTerm);
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/privacy/index.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'search_term' => $searchTerm,
            'search_results' => $searchResults,
            'flash' => $this->getFlash(),
        ]);
    }

    /**
     * View user data details
     */
    public function showUserData(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $userId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $rawData = $privacyService->findUserData($userId);

        if (!$rawData['user']) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/privacy');
        }

        // Transform data structure for template
        $userData = [
            'account' => $rawData['user'],
            'identities' => $rawData['identities'],
            'charity_access' => $rawData['charity_access'],
            'sessions' => $rawData['sessions'],
            'login_history' => $rawData['login_history'],
            'audit_log' => $rawData['audit_log_created'],
        ];

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/privacy/user_data.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'user_data' => $userData,
            'csrf_token' => $this->getCsrfToken($request),
            'flash' => $this->getFlash(),
        ]);
    }

    /**
     * Export user data as ZIP
     */
    public function exportUserData(Request $request, Response $response, array $args): Response
    {
        $adminUserId = $request->getAttribute('session')->getUserId();
        $userId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $db = $this->get(DatabaseInterface::class);

        try {
            $zipPath = $privacyService->exportUserData($userId);

            // Log the export
            $db->insert('audit_log', [
                'charity_id' => null,
                'user_id' => $adminUserId,
                'action' => 'user_data_exported',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'details' => json_encode(['reason' => 'SAR/data export request']),
            ]);

            $filename = 'user_data_' . $userId . '_' . date('Y-m-d') . '.zip';
            
            $response = $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) filesize($zipPath));

            $response->getBody()->write(file_get_contents($zipPath));
            unlink($zipPath);

            return $response;

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to export data: ' . $e->getMessage());
            return $this->redirect($response, '/service-admin/privacy/users/' . $userId);
        }
    }

    /**
     * Show user deletion confirmation
     */
    public function showUserDeletion(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $userId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $rawData = $privacyService->findUserData($userId);

        if (!$rawData['user']) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/privacy');
        }

        // Can't delete yourself
        if ($userId === $session->getUserId()) {
            $this->flash('error', 'You cannot delete your own account.');
            return $this->redirect($response, '/service-admin/privacy/users/' . $userId);
        }

        // Transform data structure for template
        $userData = [
            'account' => $rawData['user'],
            'identities' => $rawData['identities'],
            'charity_access' => $rawData['charity_access'],
            'sessions' => $rawData['sessions'],
            'login_history' => $rawData['login_history'],
            'audit_log' => $rawData['audit_log_created'],
        ];

        // Check if can be fully deleted (no audit log within retention period)
        $canDelete = empty($rawData['audit_log_created']);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/privacy/user_deletion.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'user_data' => $userData,
            'can_delete' => $canDelete,
            'csrf_token' => $this->getCsrfToken($request),
            'flash' => $this->getFlash(),
        ]);
    }

    /**
     * Process user deletion/anonymisation
     */
    public function processUserDeletion(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $adminUserId = $session->getUserId();
        $userId = (int) $args['id'];
        $data = $this->getPostData($request);

        // Can't delete yourself
        if ($userId === $adminUserId) {
            $this->flash('error', 'You cannot delete your own account.');
            return $this->redirect($response, '/service-admin/privacy/users/' . $userId);
        }

        if (empty($data['confirm'])) {
            $this->flash('error', 'You must confirm you understand the implications.');
            return $this->redirect($response, '/service-admin/privacy/users/' . $userId . '/delete');
        }

        $privacyService = $this->get(PrivacyService::class);

        try {
            $changes = $privacyService->anonymiseUserData($userId, $adminUserId);

            $this->flash('success', 'User account has been anonymised and deactivated. ' . count($changes) . ' change(s) made.');
            return $this->redirect($response, '/service-admin/privacy');

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete user: ' . $e->getMessage());
            return $this->redirect($response, '/service-admin/privacy/users/' . $userId . '/delete');
        }
    }

    /**
     * Terminate all sessions for a specific user
     */
    public function terminateUserSessions(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $adminUserId = $session->getUserId();
        $userId = (int) $args['id'];

        $db = $this->get(DatabaseInterface::class);

        // Get user info for logging
        $user = $db->queryOne("SELECT display_name FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/privacy');
        }

        // Delete all sessions for this user (except current admin's if they happen to be viewing their own)
        $currentSessionId = $session->getId();
        $count = $db->execute(
            "DELETE FROM sessions WHERE user_id = ? AND id != ?",
            [$userId, $currentSessionId]
        );

        // Log the action
        $db->insert('audit_log', [
            'user_id' => $adminUserId,
            'action' => 'user_sessions_terminated',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode([
                'sessions_terminated' => $count,
                'target_user' => $user['display_name'],
            ]),
        ]);

        $this->flash('success', "Terminated $count session(s) for {$user['display_name']}.");
        return $this->redirect($response, '/service-admin/privacy/users/' . $userId);
    }
}
