<?php

declare(strict_types=1);

namespace CharityCashbook\Auth;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Exceptions\AuthenticationException;

/**
 * Handles account linking between OAuth identities
 */
class AccountLinkingService
{
    private DatabaseInterface $db;
    private Logger $logger;
    private IdentityService $identityService;

    public function __construct(
        DatabaseInterface $db,
        Logger $logger,
        IdentityService $identityService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->identityService = $identityService;
    }

    /**
     * Create a pending link request
     */
    public function createPendingLink(
        int $existingUserId,
        int $newProviderId,
        string $newOauthSubject,
        string $newOauthEmail
    ): string {
        // Check if this OAuth identity is already linked
        $existing = $this->identityService->findIdentity($newProviderId, $newOauthSubject);
        if ($existing) {
            throw new AuthenticationException('This OAuth account is already linked to another user');
        }

        // Generate confirmation token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 3600)); // 24 hours

        // Remove any existing pending links for this combination
        $this->db->execute(
            "DELETE FROM pending_identity_links 
             WHERE existing_user_id = ? AND new_oauth_provider_id = ? AND new_oauth_subject = ?",
            [$existingUserId, $newProviderId, $newOauthSubject]
        );

        // Create new pending link
        $this->db->insert('pending_identity_links', [
            'existing_user_id' => $existingUserId,
            'new_oauth_provider_id' => $newProviderId,
            'new_oauth_subject' => $newOauthSubject,
            'new_oauth_email' => $newOauthEmail,
            'confirmation_token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $this->logger->auth('Pending identity link created', [
            'user_id' => $existingUserId,
            'new_email' => $newOauthEmail,
        ]);

        return $token;
    }

    /**
     * Confirm a pending link
     */
    public function confirmLink(string $token): array
    {
        $pending = $this->db->queryOne(
            "SELECT * FROM pending_identity_links WHERE confirmation_token = ?",
            [$token]
        );

        if (!$pending) {
            throw new AuthenticationException('Invalid or expired link confirmation token');
        }

        if (strtotime($pending['expires_at']) < time()) {
            $this->db->execute(
                "DELETE FROM pending_identity_links WHERE id = ?",
                [$pending['id']]
            );
            throw new AuthenticationException('Link confirmation token has expired');
        }

        // Check if this OAuth identity is still unlinked
        $existing = $this->identityService->findIdentity(
            $pending['new_oauth_provider_id'],
            $pending['new_oauth_subject']
        );
        if ($existing) {
            $this->db->execute(
                "DELETE FROM pending_identity_links WHERE id = ?",
                [$pending['id']]
            );
            throw new AuthenticationException('This OAuth account has already been linked');
        }

        $this->db->beginTransaction();

        try {
            // Create the identity link
            $this->db->insert('user_identities', [
                'user_id' => $pending['existing_user_id'],
                'oauth_provider_id' => $pending['new_oauth_provider_id'],
                'oauth_subject' => $pending['new_oauth_subject'],
                'oauth_email' => $pending['new_oauth_email'],
                'is_verified' => true,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);

            // Remove pending link
            $this->db->execute(
                "DELETE FROM pending_identity_links WHERE id = ?",
                [$pending['id']]
            );

            // Add user to any new charities this email grants access to
            $charities = $this->identityService->findCharitiesForEmail($pending['new_oauth_email']);
            foreach ($charities as $charity) {
                // Check if user already has access
                $existingAccess = $this->db->queryOne(
                    "SELECT id FROM charity_users WHERE charity_id = ? AND user_id = ?",
                    [$charity['id'], $pending['existing_user_id']]
                );

                if (!$existingAccess) {
                    $this->db->insert('charity_users', [
                        'charity_id' => $charity['id'],
                        'user_id' => $pending['existing_user_id'],
                        'role' => 'standard',
                        'is_active' => true,
                    ]);
                }
            }

            $this->db->commit();

            $this->logger->auth('Identity link confirmed', [
                'user_id' => $pending['existing_user_id'],
                'new_email' => $pending['new_oauth_email'],
            ]);

            return $this->identityService->getUser($pending['existing_user_id']);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Cancel a pending link
     */
    public function cancelPendingLink(int $userId, string $token): void
    {
        $this->db->execute(
            "DELETE FROM pending_identity_links 
             WHERE existing_user_id = ? AND confirmation_token = ?",
            [$userId, $token]
        );

        $this->logger->auth('Pending identity link cancelled', ['user_id' => $userId]);
    }

    /**
     * Get pending links for a user
     */
    public function getPendingLinks(int $userId): array
    {
        return $this->db->query(
            "SELECT pil.*, op.name as provider_name, op.provider_type
             FROM pending_identity_links pil
             JOIN oauth_providers op ON op.id = pil.new_oauth_provider_id
             WHERE pil.existing_user_id = ? AND pil.expires_at > NOW()
             ORDER BY pil.created_at DESC",
            [$userId]
        );
    }

    /**
     * Remove an identity from a user
     */
    public function removeIdentity(int $userId, int $identityId): void
    {
        // Check user has more than one identity
        $count = $this->db->queryValue(
            "SELECT COUNT(*) FROM user_identities WHERE user_id = ?",
            [$userId]
        );

        if ($count <= 1) {
            throw new AuthenticationException('Cannot remove the only login method');
        }

        // Verify identity belongs to user
        $identity = $this->db->queryOne(
            "SELECT * FROM user_identities WHERE id = ? AND user_id = ?",
            [$identityId, $userId]
        );

        if (!$identity) {
            throw new AuthenticationException('Identity not found');
        }

        $this->db->execute(
            "DELETE FROM user_identities WHERE id = ?",
            [$identityId]
        );

        $this->logger->auth('Identity removed', [
            'user_id' => $userId,
            'identity_id' => $identityId,
            'email' => $identity['oauth_email'],
        ]);
    }

    /**
     * Clean up expired pending links
     */
    public function cleanExpiredLinks(): int
    {
        return $this->db->execute(
            "DELETE FROM pending_identity_links WHERE expires_at < NOW()"
        );
    }

    /**
     * Send confirmation email
     */
    public function sendConfirmationEmail(
        string $email,
        string $token,
        string $newEmail,
        string $baseUrl
    ): void {
        $confirmUrl = rtrim($baseUrl, '/') . '/account/link/confirm/' . $token;
        
        $subject = 'Confirm account linking - Charity Cashbook';
        $message = "Hello,\n\n";
        $message .= "A request has been made to link the email address $newEmail to your Charity Cashbook account.\n\n";
        $message .= "If you made this request, please click the link below to confirm:\n\n";
        $message .= "$confirmUrl\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        $message .= "If you did not make this request, you can safely ignore this email.\n\n";
        $message .= "Regards,\nCharity Cashbook";

        // Use PHP mail() - in production, this would use a proper mail service
        mail($email, $subject, $message, "From: noreply@charitycashbook.org");

        $this->logger->info('Link confirmation email sent', [
            'to' => $email,
            'new_email' => $newEmail,
        ]);
    }
}
