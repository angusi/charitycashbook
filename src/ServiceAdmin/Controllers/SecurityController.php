<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\ServiceAdmin\ServiceMetricsService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Security investigation controller for service admins
 */
class SecurityController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $params = $this->getQueryParams($request);
        $metricsService = $this->get(ServiceMetricsService::class);

        $ip = $params['ip'] ?? null;
        $email = $params['email'] ?? null;
        $hours = (int) ($params['hours'] ?? 24);
        $hours = max(1, min(168, $hours)); // 1 hour to 7 days

        $data = [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'hours' => $hours,
            'ip' => $ip,
            'email' => $email,
            'login_attempts' => [],
            'target_user' => null,
            'user_sessions' => [],
            'failed_ips' => [],
            'failed_emails' => [],
        ];

        if ($ip) {
            // Investigating specific IP
            $data['login_attempts'] = $metricsService->getLoginsByIp($ip, $hours);
        } elseif ($email) {
            // Investigating specific email/account
            $data['login_attempts'] = $metricsService->getLoginsByEmail($email, $hours);
            $data['target_user'] = $metricsService->getUserByEmail($email);
            if ($data['target_user']) {
                $data['user_sessions'] = $metricsService->getUserSessions($data['target_user']['id']);
            }
        } else {
            // Overview of suspicious activity
            $data['failed_ips'] = $metricsService->getFailedLoginIps($hours, 3);
            $data['failed_emails'] = $metricsService->getFailedLoginEmails($hours, 3);
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/security.html.twig', $data);
    }

    /**
     * Terminate all sessions for a user
     */
    public function terminateSessions(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['user_id'];
        $session = $request->getAttribute('session');
        
        $sessionManager = $this->get(SessionManager::class);
        $db = $this->get(Database::class);

        // Get user info for audit
        $user = $db->queryOne("SELECT display_name, primary_email FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/security');
        }

        // Terminate all sessions
        $count = $sessionManager->destroyAllForUser($userId);

        // Log the action
        $db->insert('audit_log', [
            'charity_id' => null,
            'user_id' => $session->getUserId(),
            'action' => 'security_terminate_sessions',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode([
                'target_user' => $user['primary_email'],
                'sessions_terminated' => $count,
                'reason' => 'security_investigation',
            ]),
        ]);

        $this->flash('success', "Terminated {$count} session(s) for {$user['display_name']}.");
        return $this->redirect($response, '/service-admin/security?email=' . urlencode($user['primary_email']));
    }

    /**
     * Disable a user account
     */
    public function disableUser(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['user_id'];
        $session = $request->getAttribute('session');
        
        $db = $this->get(Database::class);
        $sessionManager = $this->get(SessionManager::class);

        // Get user info
        $user = $db->queryOne("SELECT display_name, primary_email, is_super_admin FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/security');
        }

        // Don't allow disabling super admins through this interface
        if ($user['is_super_admin']) {
            $this->flash('error', 'Cannot disable a super admin through security tools.');
            return $this->redirect($response, '/service-admin/security?email=' . urlencode($user['primary_email']));
        }

        // Disable the user
        $db->execute("UPDATE users SET is_active = FALSE WHERE id = ?", [$userId]);

        // Terminate all their sessions
        $sessionManager->destroyAllForUser($userId);

        // Log the action
        $db->insert('audit_log', [
            'charity_id' => null,
            'user_id' => $session->getUserId(),
            'action' => 'security_disable_user',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode([
                'target_user' => $user['primary_email'],
                'reason' => 'security_investigation',
            ]),
        ]);

        $this->flash('success', "Disabled account for {$user['display_name']} and terminated all sessions.");
        return $this->redirect($response, '/service-admin/security?email=' . urlencode($user['primary_email']));
    }

    /**
     * Re-enable a user account
     */
    public function enableUser(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['user_id'];
        $session = $request->getAttribute('session');
        
        $db = $this->get(Database::class);

        // Get user info
        $user = $db->queryOne("SELECT display_name, primary_email FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, '/service-admin/security');
        }

        // Enable the user
        $db->execute("UPDATE users SET is_active = TRUE WHERE id = ?", [$userId]);

        // Log the action
        $db->insert('audit_log', [
            'charity_id' => null,
            'user_id' => $session->getUserId(),
            'action' => 'security_enable_user',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode([
                'target_user' => $user['primary_email'],
            ]),
        ]);

        $this->flash('success', "Re-enabled account for {$user['display_name']}.");
        return $this->redirect($response, '/service-admin/security?email=' . urlencode($user['primary_email']));
    }
}
