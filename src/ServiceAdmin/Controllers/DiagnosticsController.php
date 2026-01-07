<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * System diagnostics controller
 */
class DiagnosticsController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $db = $this->get(Database::class);
        
        $diagnostics = [];
        
        // PHP info
        $diagnostics['php'] = [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ];
        
        // Database info
        try {
            $dbVersion = $db->queryOne("SELECT VERSION() as version");
            $diagnostics['database'] = [
                'status' => 'connected',
                'version' => $dbVersion['version'] ?? 'unknown',
            ];
            
            // Table counts
            $tables = [
                'users' => 'SELECT COUNT(*) as count FROM users',
                'charities' => 'SELECT COUNT(*) as count FROM charities',
                'transactions' => 'SELECT COUNT(*) as count FROM transactions WHERE is_deleted = FALSE',
                'sessions' => 'SELECT COUNT(*) as count FROM sessions WHERE expires_at > NOW()',
            ];
            
            foreach ($tables as $table => $sql) {
                try {
                    $result = $db->queryOne($sql);
                    $diagnostics['database']['tables'][$table] = $result['count'] ?? 0;
                } catch (\Exception $e) {
                    $diagnostics['database']['tables'][$table] = 'error';
                }
            }
        } catch (\Exception $e) {
            $diagnostics['database'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
        
        // Disk space
        $diagnostics['disk'] = [
            'free' => $this->formatBytes(disk_free_space('/')),
            'total' => $this->formatBytes(disk_total_space('/')),
            'used_percent' => round((1 - disk_free_space('/') / disk_total_space('/')) * 100, 1) . '%',
        ];
        
        // Cache directories
        $cacheDir = dirname(__DIR__, 3) . '/cache';
        $logsDir = dirname(__DIR__, 3) . '/logs';
        
        $diagnostics['directories'] = [
            'cache' => [
                'path' => $cacheDir,
                'writable' => is_writable($cacheDir),
            ],
            'logs' => [
                'path' => $logsDir,
                'writable' => is_writable($logsDir),
            ],
        ];
        
        // Required extensions
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'openssl', 'mbstring'];
        $diagnostics['extensions'] = [];
        foreach ($requiredExtensions as $ext) {
            $diagnostics['extensions'][$ext] = extension_loaded($ext);
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/diagnostics.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'diagnostics' => $diagnostics,
        ]);
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
