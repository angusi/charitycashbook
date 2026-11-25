<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\ServiceAdmin\ServiceMetricsService;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Core\Database;
use CharityCashbook\ServiceAdmin\UserAdminService;
use CharityCashbook\Core\Exceptions\ValidationException;
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
        return $this->runUserAction($request, $response, (int) $args['user_id'], 'terminate');
    }

    /**
     * Disable a user account
     */
    public function disableUser(Request $request, Response $response, array $args): Response
    {
        return $this->runUserAction($request, $response, (int) $args['user_id'], 'disable');
    }

    /**
     * Re-enable a user account
     */
    public function enableUser(Request $request, Response $response, array $args): Response
    {
        return $this->runUserAction($request, $response, (int) $args['user_id'], 'enable');
    }

    /**
     * Shared implementation, also used by ConfirmActionController
     */
    public static function performUserAction(
        UserAdminService $service,
        int $userId,
        int $adminUserId,
        int $currentSessionId,
        string $action
    ): string {
        $user = $service->getUser($userId);

        switch ($action) {
            case 'terminate':
                $count = $service->terminateSessions($userId, $adminUserId, $currentSessionId, 'security_investigation');
                return "Terminated {$count} session(s) for {$user['display_name']}.";
            case 'disable':
                $service->disableUser($userId, $adminUserId);
                return "Disabled account for {$user['display_name']} and ended their sessions.";
            case 'enable':
                $service->enableUser($userId, $adminUserId);
                return "Re-enabled account for {$user['display_name']}.";
        }

        throw new \InvalidArgumentException("Unknown action: $action");
    }

    private function runUserAction(Request $request, Response $response, int $userId, string $action): Response
    {
        $session = $request->getAttribute('session');
        $service = $this->get(UserAdminService::class);

        try {
            $message = self::performUserAction($service, $userId, $session->getUserId(), $session->getId(), $action);
            $this->flash('success', $message);
            $user = $service->getUser($userId);
            return $this->redirect($response, '/service-admin/security?email=' . urlencode($user['primary_email']));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->getErrors()));
            return $this->redirect($response, '/service-admin/security');
        }
    }
}
