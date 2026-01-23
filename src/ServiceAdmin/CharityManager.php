<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ModuleService;

/**
 * Service for managing charities at the service level
 */
class CharityManager
{
    private DatabaseInterface $db;
    private Logger $logger;
    private CategoryService $categoryService;
    private PermissionService $permissionService;
    private ModuleService $moduleService;

    public function __construct(
        DatabaseInterface $db, 
        Logger $logger, 
        CategoryService $categoryService,
        PermissionService $permissionService,
        ModuleService $moduleService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->categoryService = $categoryService;
        $this->permissionService = $permissionService;
        $this->moduleService = $moduleService;
    }

    /**
     * Get all charities (metadata only)
     */
    public function getAllCharities(): array
    {
        return $this->db->query(
            "SELECT c.id, c.name, c.registration_number, c.is_active, c.created_at,
                    (SELECT COUNT(*) FROM charity_users cu WHERE cu.charity_id = c.id AND cu.is_active = TRUE) as user_count,
                    (SELECT COUNT(*) FROM transactions t WHERE t.charity_id = c.id AND t.is_deleted = FALSE) as transaction_count
             FROM charities c
             ORDER BY c.name"
        );
    }

    /**
     * List charities with optional filters
     */
    public function listCharities(?string $search = null, ?string $status = null): array
    {
        $sql = "SELECT c.id, c.name, c.registration_number as charity_number, 
                       CASE WHEN c.is_active THEN 'active' ELSE 'suspended' END as status, 
                       c.created_at,
                       (SELECT COUNT(*) FROM charity_users cu WHERE cu.charity_id = c.id AND cu.is_active = TRUE) as user_count
                FROM charities c
                WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (c.name LIKE ? OR c.registration_number LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($status === 'active') {
            $sql .= " AND c.is_active = TRUE";
        } elseif ($status === 'suspended') {
            $sql .= " AND c.is_active = FALSE";
        }

        $sql .= " ORDER BY c.name";

        return $this->db->query($sql, $params);
    }

    /**
     * Get charity metadata by ID
     */
    public function getCharity(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM charity_users cu WHERE cu.charity_id = c.id AND cu.is_active = TRUE) as user_count
             FROM charities c
             WHERE c.id = ?",
            [$id]
        );
    }

    /**
     * Create a new charity
     */
    public function createCharity(array $data, string $adminEmail): int
    {
        $this->db->beginTransaction();

        try {
            // Create charity
            $charityId = $this->db->insert('charities', [
                'name' => $data['name'],
                'registration_number' => $data['charity_number'] ?? null,
                'hmrc_reference' => $data['hmrc_reference'] ?? null,
                'address' => $data['address'] ?? null,
                'year_end_month' => (int) ($data['year_end_month'] ?? 3),
                'year_end_day' => (int) ($data['year_end_day'] ?? 31),
                'is_active' => true,
            ]);

            // Create default roles for this charity
            $this->permissionService->createDefaultRoles($charityId);

            // Create default module settings for this charity
            $this->moduleService->createDefaultModules($charityId);

            // Add the admin email as allowed
            $domain = '@' . substr($adminEmail, strpos($adminEmail, '@') + 1);
            $this->db->insert('charity_allowed_emails', [
                'charity_id' => $charityId,
                'email_pattern' => $adminEmail,
                'pattern_type' => 'email',
            ]);

            // Allow all enabled OAuth providers by default
            $providers = $this->db->query(
                "SELECT id FROM oauth_providers WHERE is_enabled = TRUE"
            );
            foreach ($providers as $provider) {
                $this->db->insert('charity_oauth_providers', [
                    'charity_id' => $charityId,
                    'oauth_provider_id' => $provider['id'],
                ]);
            }

            // If user already exists, add them as admin
            $user = $this->db->queryOne(
                "SELECT id FROM users WHERE primary_email = ?",
                [$adminEmail]
            );

            if ($user) {
                $this->db->insert('charity_users', [
                    'charity_id' => $charityId,
                    'user_id' => $user['id'],
                    'role' => 'admin',
                    'is_active' => true,
                ]);
            }

            // Apply category template if specified
            $templateId = $data['template_id'] ?? null;
            if ($templateId && $templateId !== 'none') {
                $this->categoryService->applyTemplate($charityId, (int) $templateId);
            }

            $this->db->commit();

            $this->logger->info('Charity created', [
                'charity_id' => $charityId,
                'name' => $data['name'],
                'admin_email' => $adminEmail,
                'template_id' => $templateId,
            ]);

            return $charityId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Suspend a charity
     */
    public function suspendCharity(int $id, ?string $reason = null): void
    {
        $this->db->execute(
            "UPDATE charities SET is_active = FALSE WHERE id = ?",
            [$id]
        );

        $this->logger->info('Charity suspended', ['charity_id' => $id, 'reason' => $reason]);
    }

    /**
     * Reactivate a charity
     */
    public function reactivateCharity(int $id): void
    {
        $this->db->execute(
            "UPDATE charities SET is_active = TRUE WHERE id = ?",
            [$id]
        );

        $this->logger->info('Charity reactivated', ['charity_id' => $id]);
    }

    /**
     * Update charity details (metadata only, no transaction data)
     */
    public function updateCharity(int $id, array $data): void
    {
        $allowedFields = ['name', 'registration_number', 'hmrc_reference', 'address', 'year_end_day', 'year_end_month'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if (empty($updateData)) {
            return;
        }

        $this->db->update('charities', $updateData, 'id = ?', [$id]);
        $this->logger->info('Charity updated', ['charity_id' => $id, 'fields' => array_keys($updateData)]);
    }

    /**
     * Get charity statistics (excludes financial data for privacy)
     */
    public function getCharityStats(int $charityId): array
    {
        return [
            'user_count' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM charity_users WHERE charity_id = ? AND is_active = TRUE",
                [$charityId]
            ),
            'account_count' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM funds WHERE charity_id = ? AND is_active = TRUE",
                [$charityId]
            ),
            'transaction_count' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM transactions WHERE charity_id = ? AND is_deleted = FALSE",
                [$charityId]
            ),
        ];
    }

    /**
     * Get users for a charity
     */
    public function getCharityUsers(int $charityId): array
    {
        return $this->db->query(
            "SELECT u.id as user_id, u.display_name, u.primary_email as email, cu.role, cu.is_active, cu.created_at
             FROM charity_users cu
             JOIN users u ON u.id = cu.user_id
             WHERE cu.charity_id = ?
             ORDER BY cu.role DESC, u.display_name",
            [$charityId]
        );
    }

    /**
     * Set user role for a charity (service admin override)
     */
    public function setUserRole(int $charityId, int $userId, string $role): void
    {
        // Verify the role exists for this charity
        $roleExists = $this->db->queryOne(
            "SELECT id FROM charity_roles WHERE charity_id = ? AND name = ?",
            [$charityId, $role]
        );
        
        if (!$roleExists) {
            throw new \InvalidArgumentException("Role '$role' does not exist for this charity");
        }
        
        // Verify user is a member of this charity
        $membership = $this->db->queryOne(
            "SELECT id FROM charity_users WHERE charity_id = ? AND user_id = ?",
            [$charityId, $userId]
        );
        
        if (!$membership) {
            throw new \InvalidArgumentException("User is not a member of this charity");
        }
        
        $this->db->execute(
            "UPDATE charity_users SET role = ? WHERE charity_id = ? AND user_id = ?",
            [$role, $charityId, $userId]
        );
        
        $this->logger->info('User role changed by service admin', [
            'charity_id' => $charityId,
            'user_id' => $userId,
            'new_role' => $role,
        ]);
    }

    /**
     * Get service statistics
     */
    public function getStatistics(): array
    {
        return [
            'charities_total' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM charities"
            ),
            'charities_active' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM charities WHERE is_active = TRUE"
            ),
            'users_total' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM users WHERE is_active = TRUE"
            ),
            'sessions_active' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()"
            ),
            'transactions_total' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM transactions WHERE is_deleted = FALSE"
            ),
            'logins_today' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM login_history 
                 WHERE success = TRUE AND created_at >= CURDATE()"
            ),
            'logins_failed_today' => (int) $this->db->queryValue(
                "SELECT COUNT(*) FROM login_history 
                 WHERE success = FALSE AND created_at >= CURDATE()"
            ),
        ];
    }

    /**
     * Get recent login activity (for security monitoring)
     */
    public function getRecentLogins(?int $charityId = null, int $limit = 50): array
    {
        if ($charityId !== null) {
            return $this->db->query(
                "SELECT lh.*, u.display_name, op.provider_type
                 FROM login_history lh
                 LEFT JOIN users u ON u.id = lh.user_id
                 LEFT JOIN oauth_providers op ON op.id = lh.oauth_provider_id
                 WHERE lh.user_id IN (SELECT user_id FROM charity_users WHERE charity_id = ?)
                 ORDER BY lh.created_at DESC
                 LIMIT ?",
                [$charityId, $limit]
            );
        }
        
        return $this->db->query(
            "SELECT lh.*, u.display_name, op.provider_type
             FROM login_history lh
             LEFT JOIN users u ON u.id = lh.user_id
             LEFT JOIN oauth_providers op ON op.id = lh.oauth_provider_id
             ORDER BY lh.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Get failed login attempts grouped by IP
     */
    public function getFailedLoginsByIp(int $hours = 24): array
    {
        return $this->db->query(
            "SELECT ip_address, COUNT(*) as attempt_count, 
                    MAX(created_at) as last_attempt,
                    GROUP_CONCAT(DISTINCT oauth_email) as emails
             FROM login_history
             WHERE success = FALSE 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY ip_address
             HAVING attempt_count >= 3
             ORDER BY attempt_count DESC",
            [$hours]
        );
    }

    /**
     * Send invitation email to charity admin
     */
    public function sendInvitation(int $charityId, string $email, string $appUrl): void
    {
        // Validate email to prevent header injection
        if (!$this->isValidEmailForHeaders($email)) {
            throw new \InvalidArgumentException('Invalid email address');
        }

        $charity = $this->getCharity($charityId);
        if (!$charity) {
            throw new \RuntimeException("Charity not found: $charityId");
        }

        $loginUrl = rtrim($appUrl, '/') . '/login';

        // Sanitize charity name to prevent header injection in subject
        $charityName = $this->sanitizeForEmailHeader($charity['name']);

        $subject = "You've been invited to manage {$charityName} on Charity Cashbook";
        $message = "Hello,\n\n";
        $message .= "You have been set up as an administrator for {$charity['name']} on Charity Cashbook.\n\n";
        $message .= "Charity Cashbook is a simple accounting system for UK charities, helping you track income and expenditure, manage Gift Aid, and produce reports for your trustees and regulators.\n\n";
        $message .= "To get started, visit the link below and sign in using your {$email} email address:\n\n";
        $message .= "{$loginUrl}\n\n";
        $message .= "You can sign in using Google, Microsoft, or another supported login provider linked to this email address.\n\n";
        $message .= "If you have any questions, please contact your service administrator.\n\n";
        $message .= "Regards,\nCharity Cashbook";

        $headers = "From: noreply@charitycashbook.org\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        mail($email, $subject, $message, $headers);

        $this->logger->info('Charity invitation sent', [
            'charity_id' => $charityId,
            'charity_name' => $charity['name'],
            'email' => $email,
        ]);
    }

    /**
     * Validate email address and check for CRLF injection attempts
     */
    private function isValidEmailForHeaders(string $email): bool
    {
        // Check for CRLF injection attempts
        if (preg_match('/[\r\n]/', $email)) {
            return false;
        }

        // Validate email format
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sanitize a string for use in email headers (removes CRLF characters)
     */
    private function sanitizeForEmailHeader(string $value): string
    {
        return preg_replace('/[\r\n]+/', ' ', $value);
    }

    /**
     * Get allowed emails for a charity with registration status
     */
    public function getAllowedEmails(int $charityId): array
    {
        return $this->db->query(
            "SELECT ae.*, 
                    u.id as user_id,
                    u.display_name,
                    cu.role,
                    cu.is_active as user_active
             FROM charity_allowed_emails ae
             LEFT JOIN users u ON ae.pattern_type = 'email' AND u.primary_email = ae.email_pattern
             LEFT JOIN charity_users cu ON cu.charity_id = ae.charity_id AND cu.user_id = u.id
             WHERE ae.charity_id = ? 
             ORDER BY ae.email_pattern",
            [$charityId]
        );
    }
}
