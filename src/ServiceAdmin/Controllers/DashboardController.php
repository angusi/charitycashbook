<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\ServiceAdmin\ServiceMetricsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Service admin dashboard controller
 */
class DashboardController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $metricsService = $this->get(ServiceMetricsService::class);
        
        $stats = $metricsService->getDashboardStats();
        $securityAlerts = $metricsService->getSecurityAlerts();
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/dashboard.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'stats' => $stats,
            'security_alerts' => $securityAlerts,
        ]);
    }

    public function statistics(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $metricsService = $this->get(ServiceMetricsService::class);
        
        $stats = $metricsService->getDashboardStats();
        $storageMetrics = $metricsService->getStorageMetrics();
        $leaderboard = $metricsService->getCharityLeaderboard();
        $activityMetrics = $metricsService->getActivityMetrics();
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/statistics.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'stats' => $stats,
            'storage' => $storageMetrics,
            'leaderboard' => $leaderboard,
            'activity' => $activityMetrics,
        ]);
    }
}
