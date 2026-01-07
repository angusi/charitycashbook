<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Audit log viewer controller
 */
class AuditController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $auditService = $this->get(AuditService::class);
        
        $filters = [
            'date_from' => $params['date_from'] ?? date('Y-m-d', strtotime('-30 days')),
            'date_to' => $params['date_to'] ?? date('Y-m-d'),
            'action' => $params['action'] ?? null,
            'user_id' => !empty($params['user_id']) ? (int) $params['user_id'] : null,
            'entity_type' => $params['entity_type'] ?? null,
        ];
        
        $limit = 100;
        $offset = isset($params['page']) ? ((int) $params['page'] - 1) * $limit : 0;
        
        $entries = $auditService->getEntries($charityId, $filters, $limit, $offset);
        $totalCount = $auditService->getEntriesCount($charityId, $filters);
        
        // Get available actions for filter dropdown
        $actions = $auditService->getDistinctActions($charityId);
        
        // Get users for filter dropdown
        $users = $auditService->getUsersWithActivity($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/audit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'entries' => $entries,
                'filters' => $filters,
                'actions' => $actions,
                'users' => $users,
                'total_count' => $totalCount,
                'current_page' => isset($params['page']) ? (int) $params['page'] : 1,
                'total_pages' => ceil($totalCount / $limit),
            ]
        ));
    }
}
