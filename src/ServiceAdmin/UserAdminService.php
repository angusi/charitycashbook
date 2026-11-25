<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Exceptions\ValidationException;
use CharityCashbook\Core\Logger;

/**
 * Service-admin actions on user accounts (used by the security and privacy tools
 * and by the sensitive-action confirmation flow).
 */
class UserAdminService
{
    public function __construct(
        private DatabaseInterface $db,
        private Logger $logger
    ) {
    }

    /**
     * Get the user an action targets
     */
    public function getUser(int $userId): array
    {
        $user = $this->db->queryOne(
            "SELECT id, display_name, primary_email, is_super_admin, is_active FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user) {
            throw new ValidationException(['user' => 'User not found.']);
        }

        return $user;
    }

    /**
     * Terminate all of a user's sessions (except the acting admin's current session)
     *
     * @return int Number of sessions terminated
     */
    public function terminateSessions(int $userId, int $adminUserId, int $currentSessionId, string $reason): int
    {
        $user = $this->getUser($userId);

        $count = $this->db->execute(
            "DELETE FROM sessions WHERE user_id = ? AND id != ?",
            [$userId, $currentSessionId]
        );

        $this->audit($adminUserId, $reason === 'privacy' ? 'user_sessions_terminated' : 'security_terminate_sessions', $userId, [
            'target_user' => $user['primary_email'],
            'sessions_terminated' => $count,
            'reason' => $reason,
        ]);

        return $count;
    }

    /**
     * Disable a user account and end all of its sessions
     */
    public function disableUser(int $userId, int $adminUserId): void
    {
        $user = $this->getUser($userId);

        if ($userId === $adminUserId) {
            throw new ValidationException(['user' => 'You cannot disable your own account.']);
        }
        if ($user['is_super_admin']) {
            throw new ValidationException(['user' => 'Cannot disable a super admin through security tools.']);
        }

        $this->db->execute("UPDATE users SET is_active = FALSE WHERE id = ?", [$userId]);
        $this->db->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);

        $this->audit($adminUserId, 'security_disable_user', $userId, [
            'target_user' => $user['primary_email'],
        ]);
    }

    /**
     * Re-enable a user account
     */
    public function enableUser(int $userId, int $adminUserId): void
    {
        $user = $this->getUser($userId);

        $this->db->execute("UPDATE users SET is_active = TRUE WHERE id = ?", [$userId]);

        $this->audit($adminUserId, 'security_enable_user', $userId, [
            'target_user' => $user['primary_email'],
        ]);
    }

    private function audit(int $adminUserId, string $action, int $userId, array $details): void
    {
        $this->db->insert('audit_log', [
            'charity_id' => null,
            'user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => json_encode($details),
        ]);

        $this->logger->info("Service admin action: $action", ['target_user_id' => $userId] + $details);
    }
}
