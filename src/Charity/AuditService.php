<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Service for audit logging within a charity
 */
class AuditService
{
    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct(DatabaseInterface $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Log an action
     */
    public function log(
        ?int $charityId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $details = null
    ): void {
        $this->db->insert('audit_log', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details ? json_encode($details) : null,
            'ip_address' => $this->getClientIp(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        $this->logger->audit($action, [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    /**
     * Log transaction audit trail
     */
    public function logTransaction(
        int $charityId,
        int $transactionId,
        string $action,
        int $userId,
        ?array $changes = null
    ): void {
        $this->db->insert('transaction_audit', [
            'transaction_id' => $transactionId,
            'action' => $action,
            'changed_fields' => $changes ? json_encode($changes) : null,
            'user_id' => $userId,
        ]);

        $this->log($charityId, $userId, "transaction.$action", 'transaction', $transactionId, $changes);
    }

    /**
     * Log donor access (PII tracking)
     */
    public function logDonorAccess(
        int $charityId,
        int $userId,
        ?int $donorId,
        string $accessType,
        ?array $details = null
    ): void {
        // Only log when accessing a specific donor (donor_id is required in schema)
        if ($donorId === null) {
            return;
        }
        
        $this->db->insert('donor_access_log', [
            'charity_id' => $charityId,
            'donor_id' => $donorId,
            'user_id' => $userId,
            'access_type' => $accessType,
            'ip_address' => $this->getClientIp(),
        ]);
    }

    /**
     * Get audit entries for a charity
     */
    public function getEntries(int $charityId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT al.*, COALESCE(cu.display_name, u.display_name) as user_name
                FROM audit_log al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN charity_users cu ON cu.user_id = al.user_id AND cu.charity_id = al.charity_id
                WHERE al.charity_id = ?";
        
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Get count of audit entries
     */
    public function getEntriesCount(int $charityId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM audit_log WHERE charity_id = ?";
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }

        $result = $this->db->queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get distinct action types for filtering
     */
    public function getDistinctActions(int $charityId): array
    {
        $results = $this->db->query(
            "SELECT DISTINCT action FROM audit_log WHERE charity_id = ? ORDER BY action",
            [$charityId]
        );
        return array_column($results, 'action');
    }

    /**
     * Get users with activity in this charity
     */
    public function getUsersWithActivity(int $charityId): array
    {
        return $this->db->query(
            "SELECT DISTINCT u.id, COALESCE(cu.display_name, u.display_name) as display_name
             FROM audit_log al
             JOIN users u ON u.id = al.user_id
             LEFT JOIN charity_users cu ON cu.user_id = al.user_id AND cu.charity_id = al.charity_id
             WHERE al.charity_id = ?
             ORDER BY COALESCE(cu.display_name, u.display_name)",
            [$charityId]
        );
    }

    /**
     * Get audit log for a charity
     */
    public function getCharityLog(int $charityId, array $filters = []): array
    {
        $sql = "SELECT al.*, COALESCE(cu.display_name, u.display_name) as user_name
                FROM audit_log al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN charity_users cu ON cu.user_id = al.user_id AND cu.charity_id = al.charity_id
                WHERE al.charity_id = ?";
        
        $params = [$charityId];

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action LIKE ?";
            $params[] = $filters['action'] . '%';
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        $sql .= " ORDER BY al.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
        } else {
            $sql .= " LIMIT 500";
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Get transaction audit history
     */
    public function getTransactionHistory(int $transactionId): array
    {
        return $this->db->query(
            "SELECT ta.*, COALESCE(cu.display_name, u.display_name) as user_name
             FROM transaction_audit ta
             JOIN users u ON u.id = ta.user_id
             JOIN transactions t ON t.id = ta.transaction_id
             LEFT JOIN charity_users cu ON cu.user_id = ta.user_id AND cu.charity_id = t.charity_id
             WHERE ta.transaction_id = ?
             ORDER BY ta.created_at",
            [$transactionId]
        );
    }

    /**
     * Get donor access history
     */
    public function getDonorAccessHistory(int $charityId, int $donorId): array
    {
        return $this->db->query(
            "SELECT dal.*, COALESCE(cu.display_name, u.display_name) as user_name
             FROM donor_access_log dal
             JOIN users u ON u.id = dal.user_id
             LEFT JOIN charity_users cu ON cu.user_id = dal.user_id AND cu.charity_id = dal.charity_id
             WHERE dal.charity_id = ? AND dal.donor_id = ?
             ORDER BY dal.created_at DESC
             LIMIT 100",
            [$charityId, $donorId]
        );
    }

    /**
     * Log login attempt
     */
    public function logLogin(
        ?int $userId,
        ?int $providerId,
        string $email,
        bool $success,
        ?string $failureReason = null
    ): void {
        $this->db->insert('login_history', [
            'user_id' => $userId,
            'oauth_provider_id' => $providerId,
            'oauth_email' => $email,
            'success' => $success,
            'failure_reason' => $failureReason,
            'ip_address' => $this->getClientIp(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }

    /**
     * Clean old audit logs (respects retention policy)
     */
    public function cleanOldLogs(int $yearsToKeep = 7): int
    {
        $cutoffDate = date('Y-m-d', strtotime("-$yearsToKeep years"));
        
        $count = $this->db->execute(
            "DELETE FROM audit_log WHERE created_at < ?",
            [$cutoffDate]
        );

        $count += $this->db->execute(
            "DELETE FROM login_history WHERE created_at < ?",
            [$cutoffDate]
        );

        $count += $this->db->execute(
            "DELETE FROM donor_access_log WHERE created_at < ?",
            [$cutoffDate]
        );

        return $count;
    }

    private function getClientIp(): ?string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip ? substr($ip, 0, 45) : null;
    }
}
