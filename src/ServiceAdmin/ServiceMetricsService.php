<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Service-wide metrics for operational monitoring.
 * Focuses on aggregate/anonymous data - no PII exposed.
 */
class ServiceMetricsService
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Get security alerts - anomalies that might indicate attacks
     */
    public function getSecurityAlerts(): array
    {
        $alerts = [];

        // Check for IPs with many failed logins in last 24h
        $suspiciousIps = $this->db->query(
            "SELECT ip_address, COUNT(*) as failed_count
             FROM login_history
             WHERE success = FALSE AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY ip_address
             HAVING failed_count >= 5
             ORDER BY failed_count DESC
             LIMIT 10"
        );
        foreach ($suspiciousIps as $ip) {
            $alerts[] = [
                'type' => 'brute_force_ip',
                'severity' => $ip['failed_count'] >= 20 ? 'high' : 'medium',
                'message' => "IP {$ip['ip_address']} has {$ip['failed_count']} failed logins in 24h",
                'details' => ['ip' => $ip['ip_address'], 'count' => $ip['failed_count']],
                'action_url' => '/service-admin/security?ip=' . urlencode($ip['ip_address']),
                'action_label' => 'Investigate',
            ];
        }

        // Check for users with many failed logins (potential account targeting)
        $targetedAccounts = $this->db->query(
            "SELECT oauth_email, COUNT(*) as failed_count
             FROM login_history
             WHERE success = FALSE 
               AND oauth_email IS NOT NULL
               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY oauth_email
             HAVING failed_count >= 5
             ORDER BY failed_count DESC
             LIMIT 5"
        );
        foreach ($targetedAccounts as $account) {
            $alerts[] = [
                'type' => 'targeted_account',
                'severity' => $account['failed_count'] >= 10 ? 'high' : 'medium',
                'message' => "Account targeted: {$account['failed_count']} failed attempts in 24h",
                'details' => ['email' => $account['oauth_email'], 'count' => $account['failed_count']],
                'action_url' => '/service-admin/security?email=' . urlencode($account['oauth_email']),
                'action_label' => 'Investigate',
            ];
        }

        // Check for charities with unusual activity spikes
        $activitySpikes = $this->db->query(
            "SELECT c.id, c.name,
                    COUNT(*) as today_actions,
                    (SELECT COUNT(*) FROM audit_log 
                     WHERE charity_id = c.id 
                     AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 8 DAY) AND DATE_SUB(NOW(), INTERVAL 1 DAY)
                    ) / 7 as avg_daily_actions
             FROM charities c
             JOIN audit_log al ON al.charity_id = c.id
             WHERE al.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY c.id
             HAVING today_actions > 100 AND today_actions > avg_daily_actions * 3"
        );
        foreach ($activitySpikes as $spike) {
            $alerts[] = [
                'type' => 'activity_spike',
                'severity' => 'low',
                'message' => "Charity #{$spike['id']} has unusual activity spike ({$spike['today_actions']} actions today vs {$spike['avg_daily_actions']} avg)",
                'details' => ['charity_id' => $spike['id']],
            ];
        }

        return $alerts;
    }

    /**
     * Get database storage metrics
     */
    public function getStorageMetrics(): array
    {
        // Get row counts for main tables
        $tables = [
            'charities' => 'Charities',
            'users' => 'Users',
            'transactions' => 'Transactions',
            'donors' => 'Donors',
            'members' => 'Members',
            'audit_log' => 'Audit Log',
            'login_history' => 'Login History',
            'sessions' => 'Sessions',
        ];

        $counts = [];
        foreach ($tables as $table => $label) {
            $result = $this->db->queryOne("SELECT COUNT(*) as cnt FROM $table");
            $counts[$table] = [
                'label' => $label,
                'count' => (int) ($result['cnt'] ?? 0),
            ];
        }

        return $counts;
    }

    /**
     * Get activity metrics for last 7 days
     */
    public function getActivityMetrics(): array
    {
        // Logins per day
        $loginsByDay = $this->db->query(
            "SELECT DATE(created_at) as date, 
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed
             FROM login_history
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        );

        // Transactions per day
        $transactionsByDay = $this->db->query(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM transactions
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        );

        // Active users per day (users who did something)
        $activeUsersByDay = $this->db->query(
            "SELECT DATE(created_at) as date, COUNT(DISTINCT user_id) as count
             FROM audit_log
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        );

        return [
            'logins' => $loginsByDay,
            'transactions' => $transactionsByDay,
            'active_users' => $activeUsersByDay,
        ];
    }

    /**
     * Get charity leaderboard - anonymous activity rankings
     */
    public function getCharityLeaderboard(): array
    {
        // Most active charities (by audit log entries this month)
        $mostActive = $this->db->query(
            "SELECT c.id, c.name, COUNT(al.id) as action_count
             FROM charities c
             LEFT JOIN audit_log al ON al.charity_id = c.id 
                AND al.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE c.is_active = TRUE
             GROUP BY c.id
             ORDER BY action_count DESC
             LIMIT 10"
        );

        // Charities by transaction volume this month
        $byTransactions = $this->db->query(
            "SELECT c.id, c.name, COUNT(t.id) as transaction_count
             FROM charities c
             LEFT JOIN transactions t ON t.charity_id = c.id 
                AND t.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE c.is_active = TRUE
             GROUP BY c.id
             ORDER BY transaction_count DESC
             LIMIT 10"
        );

        // Charities by user count
        $byUsers = $this->db->query(
            "SELECT c.id, c.name, COUNT(cu.user_id) as user_count
             FROM charities c
             LEFT JOIN charity_users cu ON cu.charity_id = c.id AND cu.is_active = TRUE
             WHERE c.is_active = TRUE
             GROUP BY c.id
             ORDER BY user_count DESC
             LIMIT 10"
        );

        // Newest charities
        $newest = $this->db->query(
            "SELECT id, name, created_at
             FROM charities
             WHERE is_active = TRUE
             ORDER BY created_at DESC
             LIMIT 5"
        );

        // Dormant charities (no activity in 30 days)
        $dormant = $this->db->query(
            "SELECT c.id, c.name, MAX(al.created_at) as last_activity
             FROM charities c
             LEFT JOIN audit_log al ON al.charity_id = c.id
             WHERE c.is_active = TRUE
             GROUP BY c.id
             HAVING last_activity IS NULL OR last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY last_activity ASC
             LIMIT 10"
        );

        return [
            'most_active' => $mostActive,
            'by_transactions' => $byTransactions,
            'by_users' => $byUsers,
            'newest' => $newest,
            'dormant' => $dormant,
        ];
    }

    /**
     * Get summary stats for dashboard cards
     */
    public function getDashboardStats(): array
    {
        $stats = [];

        // Active charities
        $stats['charities_active'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM charities WHERE is_active = TRUE"
        );

        // Total users
        $stats['users_total'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM users WHERE is_active = TRUE"
        );

        // Active sessions
        $stats['sessions_active'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()"
        );

        // Logins today
        $stats['logins_today'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM login_history WHERE DATE(created_at) = CURDATE() AND success = TRUE"
        );

        // Failed logins today
        $stats['logins_failed_today'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM login_history WHERE DATE(created_at) = CURDATE() AND success = FALSE"
        );

        // Transactions this week
        $stats['transactions_week'] = (int) $this->db->queryValue(
            "SELECT COUNT(*) FROM transactions WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // Active users today
        $stats['active_users_today'] = (int) $this->db->queryValue(
            "SELECT COUNT(DISTINCT user_id) FROM audit_log WHERE DATE(created_at) = CURDATE()"
        );

        return $stats;
    }

    /**
     * Get login attempts for a specific IP address
     */
    public function getLoginsByIp(string $ip, int $hours = 24): array
    {
        return $this->db->query(
            "SELECT lh.*, op.provider_type
             FROM login_history lh
             LEFT JOIN oauth_providers op ON op.id = lh.oauth_provider_id
             WHERE lh.ip_address = ? AND lh.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY lh.created_at DESC",
            [$ip, $hours]
        );
    }

    /**
     * Get login attempts for a specific email
     */
    public function getLoginsByEmail(string $email, int $hours = 24): array
    {
        return $this->db->query(
            "SELECT lh.*, op.provider_type
             FROM login_history lh
             LEFT JOIN oauth_providers op ON op.id = lh.oauth_provider_id
             WHERE lh.oauth_email = ? AND lh.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY lh.created_at DESC",
            [$email, $hours]
        );
    }

    /**
     * Get user by email (for taking action)
     */
    public function getUserByEmail(string $email): ?array
    {
        // First check user_identities for OAuth email
        $identity = $this->db->queryOne(
            "SELECT ui.user_id, u.id, u.display_name, u.primary_email, u.is_active, u.is_super_admin
             FROM user_identities ui
             JOIN users u ON u.id = ui.user_id
             WHERE ui.oauth_email = ?",
            [$email]
        );
        if ($identity) {
            return $identity;
        }

        // Fall back to primary email
        return $this->db->queryOne(
            "SELECT id, display_name, primary_email, is_active, is_super_admin
             FROM users WHERE primary_email = ?",
            [$email]
        );
    }

    /**
     * Get active sessions for a user
     */
    public function getUserSessions(int $userId): array
    {
        return $this->db->query(
            "SELECT id, created_at, last_active_at, expires_at, ip_address, user_agent
             FROM sessions
             WHERE user_id = ? AND expires_at > NOW()
             ORDER BY last_active_at DESC",
            [$userId]
        );
    }

    /**
     * Get summary of IPs with failed logins
     */
    public function getFailedLoginIps(int $hours = 24, int $minAttempts = 3): array
    {
        return $this->db->query(
            "SELECT ip_address, 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
                    COUNT(DISTINCT oauth_email) as unique_emails,
                    MIN(created_at) as first_attempt,
                    MAX(created_at) as last_attempt
             FROM login_history
             WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY ip_address
             HAVING failed_attempts >= ?
             ORDER BY failed_attempts DESC",
            [$hours, $minAttempts]
        );
    }

    /**
     * Get summary of emails with failed logins
     */
    public function getFailedLoginEmails(int $hours = 24, int $minAttempts = 3): array
    {
        return $this->db->query(
            "SELECT oauth_email,
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    MIN(created_at) as first_attempt,
                    MAX(created_at) as last_attempt
             FROM login_history
             WHERE oauth_email IS NOT NULL 
               AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY oauth_email
             HAVING failed_attempts >= ?
             ORDER BY failed_attempts DESC",
            [$hours, $minAttempts]
        );
    }
}
