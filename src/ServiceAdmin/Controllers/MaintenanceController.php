<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * System maintenance controller for service admins
 */
class MaintenanceController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $db = $this->get(DatabaseInterface::class);
        $config = $this->get('config');
        
        // Gather stats for display
        $stats = $this->getMaintenanceStats($db, $config);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/maintenance.html.twig', [
            'user' => [
                'display_name' => $session->getDisplayName(),
                'is_super_admin' => $session->isSuperAdmin(),
            ],
            'stats' => $stats,
            'flash' => $this->getFlash(),
        ]);
    }

    public function clearCache(Request $request, Response $response): Response
    {
        $config = $this->get('config');
        $cacheDir = $config['paths']['cache'];
        
        $cleared = 0;
        
        // Clear Twig cache
        $twigCache = $cacheDir . '/twig';
        if (is_dir($twigCache)) {
            $cleared += $this->clearDirectory($twigCache);
        }
        
        $this->logAction('cache_clear', ['files_cleared' => $cleared]);
        $this->flash('success', "Cache cleared: $cleared files removed.");
        
        return $this->redirect($response, '/service-admin/maintenance');
    }

    public function cleanSessions(Request $request, Response $response): Response
    {
        $db = $this->get(DatabaseInterface::class);
        
        $count = $db->execute("DELETE FROM sessions WHERE expires_at < NOW()");
        
        $this->logAction('clean_sessions', ['sessions_removed' => $count]);
        $this->flash('success', "Cleaned $count expired sessions.");
        
        return $this->redirect($response, '/service-admin/maintenance');
    }

    public function cleanTokens(Request $request, Response $response): Response
    {
        $db = $this->get(DatabaseInterface::class);
        
        $count = $db->execute("DELETE FROM pending_identity_links WHERE expires_at < NOW()");
        
        $this->logAction('clean_tokens', ['tokens_removed' => $count]);
        $this->flash('success', "Cleaned $count expired pending links.");
        
        return $this->redirect($response, '/service-admin/maintenance');
    }

    /**
     * Force all users globally to re-authenticate by deleting all sessions
     */
    public function forceGlobalReauth(Request $request, Response $response): Response
    {
        $db = $this->get(DatabaseInterface::class);
        $session = $request->getAttribute('session');
        $currentSessionId = $session->getId();
        
        // Delete all sessions except the current admin's session
        $count = $db->execute(
            "DELETE FROM sessions WHERE id != ?",
            [$currentSessionId]
        );
        
        $this->logAction('force_global_reauth', [
            'sessions_terminated' => $count,
            'admin_user_id' => $session->getUserId(),
        ]);
        
        $this->flash('success', "Forced re-authentication for all users. $count sessions terminated.");
        
        return $this->redirect($response, '/service-admin/maintenance');
    }

    /**
     * Force all users of a specific charity to re-authenticate
     */
    public function forceCharityReauth(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $db = $this->get(DatabaseInterface::class);
        $session = $request->getAttribute('session');
        
        // Get charity name for logging
        $charity = $db->queryOne("SELECT name FROM charities WHERE id = ?", [$charityId]);
        if (!$charity) {
            $this->flash('error', 'Charity not found.');
            return $this->redirect($response, '/service-admin/charities');
        }
        
        // Find all user IDs who have access to this charity
        $userIds = $db->query(
            "SELECT DISTINCT user_id FROM charity_users WHERE charity_id = ?",
            [$charityId]
        );
        
        if (empty($userIds)) {
            $this->flash('info', 'No users found for this charity.');
            return $this->redirect($response, '/service-admin/charities/' . $charityId);
        }
        
        // Delete their sessions (except current admin if they happen to be one)
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
            'users_affected' => count($ids),
        ]);
        
        $this->flash('success', "Forced re-authentication for {$charity['name']}. $count sessions terminated.");
        
        return $this->redirect($response, '/service-admin/charities/' . $charityId);
    }

    private function getMaintenanceStats(DatabaseInterface $db, array $config): array
    {
        $stats = [];
        
        // Expired sessions count
        $stats['expired_sessions'] = (int) $db->queryValue(
            "SELECT COUNT(*) FROM sessions WHERE expires_at < NOW()"
        );
        
        // Active sessions count
        $stats['active_sessions'] = (int) $db->queryValue(
            "SELECT COUNT(*) FROM sessions WHERE expires_at >= NOW()"
        );
        
        // Expired tokens count
        $stats['expired_tokens'] = (int) $db->queryValue(
            "SELECT COUNT(*) FROM pending_identity_links WHERE expires_at < NOW()"
        );
        
        // Cache directory size
        $cacheDir = $config['paths']['cache'];
        $twigCache = $cacheDir . '/twig';
        if (is_dir($twigCache)) {
            $stats['cache_files'] = $this->countFiles($twigCache);
            $stats['cache_size'] = $this->formatBytes($this->getDirectorySize($twigCache));
        } else {
            $stats['cache_files'] = 0;
            $stats['cache_size'] = '0 B';
        }
        
        // Logs directory size
        $logsDir = $config['paths']['logs'];
        if (is_dir($logsDir)) {
            $stats['log_files'] = $this->countFiles($logsDir);
            $stats['log_size'] = $this->formatBytes($this->getDirectorySize($logsDir));
        } else {
            $stats['log_files'] = 0;
            $stats['log_size'] = '0 B';
        }
        
        // Audit log counts
        $stats['audit_log_total'] = (int) $db->queryValue("SELECT COUNT(*) FROM audit_log");
        $stats['audit_log_old'] = (int) $db->queryValue(
            "SELECT COUNT(*) FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 YEAR)"
        );
        
        return $stats;
    }

    private function clearDirectory(string $dir): int
    {
        $count = 0;
        
        if (!is_dir($dir)) {
            return 0;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
                $count++;
            }
        }
        
        return $count;
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        
        return $count;
    }

    private function getDirectorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function logAction(string $action, array $details): void
    {
        $db = $this->get(DatabaseInterface::class);
        $db->insert('audit_log', [
            'user_id' => null,
            'action' => 'maintenance.' . $action,
            'entity_type' => 'system',
            'entity_id' => null,
            'details' => json_encode($details),
        ]);
    }
}
