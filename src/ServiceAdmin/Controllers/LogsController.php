<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Log viewer controller
 */
class LogsController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $params = $this->getQueryParams($request);
        
        $logsDir = dirname(__DIR__, 3) . '/logs';
        $logType = $params['type'] ?? 'app';
        $date = $params['date'] ?? date('Y-m-d');
        $lines = min(1000, (int) ($params['lines'] ?? 100));
        
        // List available log files
        $logFiles = [];
        if (is_dir($logsDir)) {
            $files = glob($logsDir . '/*.log');
            foreach ($files as $file) {
                $logFiles[] = basename($file, '.log');
            }
        }
        
        // Read selected log file
        $logContent = [];
        $logFile = $logsDir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $logType) . '.log';
        
        if (file_exists($logFile)) {
            $content = file($logFile, FILE_IGNORE_NEW_LINES);
            
            // Filter by date if applicable
            $filtered = [];
            foreach ($content as $line) {
                if (empty($date) || strpos($line, "[$date") !== false) {
                    $filtered[] = $line;
                }
            }
            
            // Get last N lines
            $logContent = array_slice($filtered, -$lines);
            $logContent = array_reverse($logContent); // Most recent first
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/logs.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'log_files' => $logFiles,
            'log_type' => $logType,
            'date' => $date,
            'lines' => $lines,
            'log_content' => $logContent,
        ]);
    }
}
