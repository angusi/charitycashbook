<?php

declare(strict_types=1);

namespace CharityCashbook\Auth;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\AuthenticationException;

/**
 * Handles identity resolution and user management
 */
class IdentityService
{
    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct(DatabaseInterface $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Resolve an OAuth identity to an internal user
     */
    public function resolveIdentity(int $providerId, string $oauthSubject, string $oauthEmail): array
    {
        // 1. Look for existing identity by OAuth subject
        $identity = $this->findIdentity($providerId, $oauthSubject);
        
        if ($identity) {
            $user = $this->getUser($identity['user_id']);
            if (!$user || !$user['is_active']) {
                throw new AuthenticationException('User account is inactive');
            }
            
            return [
                'status' => 'existing_user',
                'user' => $user,
                'identity' => $identity,
            ];
        }

        // 2. Check if a user exists with this email (e.g., created via CLI)
        $existingUser = $this->getUserByEmail($oauthEmail);
        
        if ($existingUser) {
            if (!$existingUser['is_active']) {
                throw new AuthenticationException('User account is inactive');
            }
            
            // User exists but no OAuth identity - create the identity link
            $this->db->insert('user_identities', [
                'user_id' => $existingUser['id'],
                'oauth_provider_id' => $providerId,
                'oauth_subject' => $oauthSubject,
                'oauth_email' => $oauthEmail,
                'is_verified' => true,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);
            
            $this->logger->auth('OAuth identity linked to existing user', [
                'user_id' => $existingUser['id'],
                'email' => $oauthEmail,
                'provider_id' => $providerId,
            ]);
            
            return [
                'status' => 'existing_user',
                'user' => $existingUser,
                'identity' => null,
            ];
        }

        // 3. Unknown identity - check if email is authorised for any charity
        $authorisedCharities = $this->findCharitiesForEmail($oauthEmail);
        
        if (empty($authorisedCharities)) {
            $this->logger->auth('Login rejected - email not authorised', [
                'email' => $oauthEmail,
                'provider_id' => $providerId,
            ]);
            throw new AuthenticationException('Email address not authorised for any charity');
        }

        // 4. Check if any of those charities allow linking
        $linkingAllowed = $this->anyCharityAllowsLinking($authorisedCharities);

        // 5. Return state for UI to handle
        return [
            'status' => 'new_identity',
            'email' => $oauthEmail,
            'oauth_subject' => $oauthSubject,
            'provider_id' => $providerId,
            'charities' => $authorisedCharities,
            'can_link' => $linkingAllowed,
        ];
    }

    /**
     * Create a new user with an OAuth identity
     */
    public function createUser(
        string $displayName,
        string $email,
        int $providerId,
        string $oauthSubject,
        string $oauthEmail
    ): array {
        $this->db->beginTransaction();
        
        try {
            // Create user
            $userId = $this->db->insert('users', [
                'display_name' => $displayName,
                'primary_email' => $email,
                'is_super_admin' => false,
                'is_active' => true,
            ]);

            // Create identity
            $identityId = $this->db->insert('user_identities', [
                'user_id' => $userId,
                'oauth_provider_id' => $providerId,
                'oauth_subject' => $oauthSubject,
                'oauth_email' => $oauthEmail,
                'is_verified' => true,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);

            // Add user to authorised charities
            $charities = $this->findCharitiesForEmail($oauthEmail);
            foreach ($charities as $charity) {
                // First user for a charity becomes admin
                $existingUsers = $this->db->queryValue(
                    "SELECT COUNT(*) FROM charity_users WHERE charity_id = ?",
                    [$charity['id']]
                );
                $role = ($existingUsers == 0) ? 'admin' : 'standard';
                
                $this->db->insert('charity_users', [
                    'charity_id' => $charity['id'],
                    'user_id' => $userId,
                    'role' => $role,
                    'is_active' => true,
                ]);
            }

            $this->db->commit();

            $this->logger->auth('User created', [
                'user_id' => $userId,
                'email' => $email,
                'provider_id' => $providerId,
            ]);

            return $this->getUser($userId);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Find an OAuth identity
     */
    public function findIdentity(int $providerId, string $oauthSubject): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM user_identities 
             WHERE oauth_provider_id = ? AND oauth_subject = ?",
            [$providerId, $oauthSubject]
        );
    }

    /**
     * Find an identity for a specific user
     */
    public function findIdentityForUser(int $userId, int $providerId, string $oauthEmail): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM user_identities 
             WHERE user_id = ? AND oauth_provider_id = ? AND oauth_email = ?",
            [$userId, $providerId, $oauthEmail]
        );
    }

    /**
     * Get all identities for a user
     */
    public function getIdentitiesForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT ui.*, op.name as provider_name, op.provider_type
             FROM user_identities ui
             JOIN oauth_providers op ON op.id = ui.oauth_provider_id
             WHERE ui.user_id = ?
             ORDER BY ui.created_at",
            [$userId]
        );
    }

    /**
     * Get a user by ID
     */
    public function getUser(int $userId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM users WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Get a user by email
     */
    public function getUserByEmail(string $email): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM users WHERE primary_email = ?",
            [$email]
        );
    }

    /**
     * Find charities that allow a specific email
     */
    public function findCharitiesForEmail(string $email): array
    {
        $domain = '@' . substr($email, strpos($email, '@') + 1);

        return $this->db->query(
            "SELECT DISTINCT c.* 
             FROM charities c
             JOIN charity_allowed_emails cae ON cae.charity_id = c.id
             WHERE c.is_active = TRUE
             AND (
                 (cae.pattern_type = 'email' AND cae.email_pattern = ?)
                 OR (cae.pattern_type = 'domain' AND cae.email_pattern = ?)
             )",
            [$email, $domain]
        );
    }

    /**
     * Check if email is authorised for a specific charity
     */
    public function isEmailAuthorisedForCharity(string $email, int $charityId): bool
    {
        $domain = '@' . substr($email, strpos($email, '@') + 1);

        $count = $this->db->queryValue(
            "SELECT COUNT(*)
             FROM charity_allowed_emails
             WHERE charity_id = ?
             AND (
                 (pattern_type = 'email' AND email_pattern = ?)
                 OR (pattern_type = 'domain' AND email_pattern = ?)
             )",
            [$charityId, $email, $domain]
        );

        return $count > 0;
    }

    /**
     * Check if an OAuth identity is authorized for a specific charity
     *
     * Looks up the identity's email and checks against charity_allowed_emails.
     */
    public function isIdentityAuthorizedForCharity(int $identityId, int $charityId): bool
    {
        // Get the identity's email
        $identity = $this->db->queryOne(
            "SELECT oauth_email FROM user_identities WHERE id = ?",
            [$identityId]
        );

        if (!$identity) {
            return false;
        }

        return $this->isEmailAuthorisedForCharity($identity['oauth_email'], $charityId);
    }

    /**
     * Check if any of the charities allow account linking
     */
    private function anyCharityAllowsLinking(array $charities): bool
    {
        foreach ($charities as $charity) {
            if ($charity['allow_account_linking']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Update user display name
     */
    public function updateDisplayName(int $userId, string $displayName): void
    {
        $this->db->execute(
            "UPDATE users SET display_name = ? WHERE id = ?",
            [$displayName, $userId]
        );
    }

    /**
     * Get charities user belongs to
     */
    public function getUserCharities(int $userId): array
    {
        return $this->db->query(
            "SELECT c.*, cu.role, cu.last_authenticated_at
             FROM charities c
             JOIN charity_users cu ON cu.charity_id = c.id
             WHERE cu.user_id = ? AND cu.is_active = TRUE AND c.is_active = TRUE
             ORDER BY c.name",
            [$userId]
        );
    }

    /**
     * Get suspended charities the user is a member of
     */
    public function getUserSuspendedCharities(int $userId): array
    {
        return $this->db->query(
            "SELECT c.*, cu.role, cu.last_authenticated_at
             FROM charities c
             JOIN charity_users cu ON cu.charity_id = c.id
             WHERE cu.user_id = ? AND cu.is_active = TRUE AND c.is_active = FALSE
             ORDER BY c.name",
            [$userId]
        );
    }

    /**
     * Add an OAuth identity to an existing user
     */
    public function addIdentityToUser(int $userId, int $providerId, string $oauthSubject, string $oauthEmail): int
    {
        $identityId = $this->db->insert('user_identities', [
            'user_id' => $userId,
            'oauth_provider_id' => $providerId,
            'oauth_subject' => $oauthSubject,
            'oauth_email' => $oauthEmail,
            'is_verified' => true,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->auth('Identity added to user', [
            'user_id' => $userId,
            'identity_id' => $identityId,
            'email' => $oauthEmail,
            'provider_id' => $providerId,
        ]);

        return $identityId;
    }

    /**
     * Add a user to a charity if they're not already a member
     */
    public function addUserToCharityIfNotMember(int $userId, int $charityId): bool
    {
        // Check if already a member
        $existing = $this->db->queryOne(
            "SELECT id FROM charity_users WHERE user_id = ? AND charity_id = ?",
            [$userId, $charityId]
        );

        if ($existing) {
            return false; // Already a member
        }

        // Check if first user (becomes admin)
        $existingUsers = $this->db->queryValue(
            "SELECT COUNT(*) FROM charity_users WHERE charity_id = ?",
            [$charityId]
        );
        $role = ($existingUsers == 0) ? 'admin' : 'standard';

        $this->db->insert('charity_users', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'role' => $role,
            'is_active' => true,
        ]);

        $this->logger->auth('User added to charity', [
            'user_id' => $userId,
            'charity_id' => $charityId,
            'role' => $role,
        ]);

        return true;
    }
}
