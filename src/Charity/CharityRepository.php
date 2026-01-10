<?php

declare(strict_types=1);

namespace CharityCashbook\Charity;

use CharityCashbook\Core\DatabaseInterface;

/**
 * Repository for charity data access
 */
class CharityRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Find charity by ID
     */
    public function findById(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT *, registration_number AS charity_number, 
                    (session_timeout_hours * 60) AS session_timeout_minutes 
             FROM charities WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get user's membership in a charity
     */
    public function getUserMembership(int $charityId, int $userId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM charity_users WHERE charity_id = ? AND user_id = ?",
            [$charityId, $userId]
        );
    }

    /**
     * Update charity settings
     */
    public function updateSettings(int $charityId, array $data): void
    {
        $this->db->update('charities', $data, 'id = ?', [$charityId]);
    }

    /**
     * Update charity - alias for updateSettings
     */
    public function update(int $charityId, array $data): void
    {
        $this->updateSettings($charityId, $data);
    }

    /**
     * Get allowed email patterns for a charity
     */
    public function getAllowedEmails(int $charityId): array
    {
        return $this->db->query(
            "SELECT * FROM charity_allowed_emails WHERE charity_id = ? ORDER BY email_pattern",
            [$charityId]
        );
    }

    /**
     * Add allowed email pattern
     */
    public function addAllowedEmail(int $charityId, string $pattern, string $type): int
    {
        return $this->db->insert('charity_allowed_emails', [
            'charity_id' => $charityId,
            'email_pattern' => $pattern,
            'pattern_type' => $type,
        ]);
    }

    /**
     * Remove allowed email pattern
     */
    public function removeAllowedEmail(int $charityId, int $patternId): void
    {
        $this->db->execute(
            "DELETE FROM charity_allowed_emails WHERE id = ? AND charity_id = ?",
            [$patternId, $charityId]
        );
    }

    /**
     * Get allowed OAuth providers for a charity
     */
    public function getAllowedProviders(int $charityId): array
    {
        return $this->db->query(
            "SELECT op.* FROM oauth_providers op
             JOIN charity_oauth_providers cop ON cop.oauth_provider_id = op.id
             WHERE cop.charity_id = ?",
            [$charityId]
        );
    }

    /**
     * Set allowed OAuth providers for a charity
     */
    public function setAllowedProviders(int $charityId, array $providerIds): void
    {
        // Remove existing
        $this->db->execute(
            "DELETE FROM charity_oauth_providers WHERE charity_id = ?",
            [$charityId]
        );

        // Add new
        foreach ($providerIds as $providerId) {
            $this->db->insert('charity_oauth_providers', [
                'charity_id' => $charityId,
                'oauth_provider_id' => $providerId,
            ]);
        }
    }

    /**
     * Get all users for a charity
     */
    public function getUsers(int $charityId): array
    {
        return $this->db->query(
            "SELECT u.id as user_id, COALESCE(cu.display_name, u.display_name) as display_name,
                    COALESCE(cu.email, u.primary_email) as user_email,
                    u.is_super_admin, cu.role, cu.is_active,
                    cu.last_authenticated_at, cu.created_at
             FROM users u
             JOIN charity_users cu ON cu.user_id = u.id
             WHERE cu.charity_id = ?
             ORDER BY COALESCE(cu.display_name, u.display_name)",
            [$charityId]
        );
    }

    /**
     * Update user role in charity
     */
    public function updateUserRole(int $charityId, int $userId, string $role): void
    {
        $this->db->execute(
            "UPDATE charity_users SET role = ? WHERE charity_id = ? AND user_id = ?",
            [$role, $charityId, $userId]
        );
    }

    /**
     * Deactivate user in charity
     */
    public function deactivateUser(int $charityId, int $userId): void
    {
        $this->db->execute(
            "UPDATE charity_users SET is_active = FALSE WHERE charity_id = ? AND user_id = ?",
            [$charityId, $userId]
        );
    }

    /**
     * Activate user in charity
     */
    public function activateUser(int $charityId, int $userId): void
    {
        $this->db->execute(
            "UPDATE charity_users SET is_active = TRUE WHERE charity_id = ? AND user_id = ?",
            [$charityId, $userId]
        );
    }

    /**
     * Add user to charity
     */
    public function addUser(int $charityId, int $userId, string $role): void
    {
        $this->db->insert('charity_users', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /**
     * Update last authenticated timestamp and optionally sync display name from OAuth
     */
    public function updateLastAuthenticated(int $charityId, int $userId, ?string $displayName = null): void
    {
        if ($displayName !== null) {
            $this->db->execute(
                "UPDATE charity_users SET last_authenticated_at = NOW(), display_name = ?
                 WHERE charity_id = ? AND user_id = ?",
                [$displayName, $charityId, $userId]
            );
        } else {
            $this->db->execute(
                "UPDATE charity_users SET last_authenticated_at = NOW()
                 WHERE charity_id = ? AND user_id = ?",
                [$charityId, $userId]
            );
        }
    }

    /**
     * Update user's OAuth identity and email for a specific charity
     *
     * Called when user authenticates for a charity, storing which OAuth identity
     * they used and the email from that identity for display.
     */
    public function updateUserIdentity(int $charityId, int $userId, int $identityId, string $email): void
    {
        $this->db->execute(
            "UPDATE charity_users SET identity_id = ?, email = ? WHERE charity_id = ? AND user_id = ?",
            [$identityId, $email, $charityId, $userId]
        );
    }

    /**
     * Update user's display name for a specific charity
     */
    public function updateUserDisplayName(int $charityId, int $userId, string $displayName): void
    {
        $this->db->execute(
            "UPDATE charity_users SET display_name = ? WHERE charity_id = ? AND user_id = ?",
            [$displayName, $charityId, $userId]
        );
    }

    /**
     * Get user's display name for a charity (with fallback to global name)
     */
    public function getUserDisplayName(int $charityId, int $userId): ?string
    {
        return $this->db->queryValue(
            "SELECT COALESCE(cu.display_name, u.display_name) 
             FROM charity_users cu
             JOIN users u ON u.id = cu.user_id
             WHERE cu.charity_id = ? AND cu.user_id = ?",
            [$charityId, $userId]
        );
    }
}
