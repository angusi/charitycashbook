<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\Database;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Charity user management controller
 */
class UserController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $db = $this->get(Database::class);
        
        // Get active users (non-pending)
        $users = $db->query(
            "SELECT cu.*, COALESCE(cu.display_name, u.display_name) as display_name, 
                    u.primary_email as user_email, u.is_super_admin
             FROM charity_users cu
             JOIN users u ON cu.user_id = u.id
             WHERE cu.charity_id = ? AND cu.role != 'pending'
             ORDER BY cu.role, COALESCE(cu.display_name, u.display_name)",
            [$charityId]
        );
        
        // Get pending users awaiting approval
        $pendingUsers = $db->query(
            "SELECT cu.*, COALESCE(cu.display_name, u.display_name) as display_name, 
                    u.primary_email as user_email
             FROM charity_users cu
             JOIN users u ON cu.user_id = u.id
             WHERE cu.charity_id = ? AND cu.role = 'pending'
             ORDER BY cu.created_at",
            [$charityId]
        );
        
        // Get allowed emails - show all domain patterns and unused exact patterns
        $pendingEmails = $db->query(
            "SELECT cae.*
             FROM charity_allowed_emails cae
             WHERE cae.charity_id = ?
             AND (
                 cae.pattern_type = 'domain'
                 OR NOT EXISTS (
                     SELECT 1 FROM user_identities ui
                     JOIN charity_users cu ON cu.user_id = ui.user_id
                     WHERE ui.oauth_email = cae.email_pattern AND cu.charity_id = cae.charity_id
                 )
             )
             ORDER BY cae.pattern_type DESC, cae.email_pattern",
            [$charityId]
        );
        
        // Get roles for colour lookup
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        $roles = $permissionService->getRoles($charityId);
        $roleColours = [];
        foreach ($roles as $role) {
            $roleColours[$role['name']] = $role['colour'] ?? null;
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/users.html.twig', array_merge($this->getCommonTemplateData($request), [
            'users' => $users,
            'pending_users' => $pendingUsers,
            'pending_emails' => $pendingEmails,
            'role_colours' => $roleColours,
        ]));
    }

    public function invite(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        $roles = $permissionService->getAvailableRoles($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/invite.html.twig', array_merge($this->getCommonTemplateData($request), [
            'roles' => $roles,
        ]));
    }

    public function sendInvite(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'email' => 'required|email|max:255',
                'role' => 'required|in:admin,standard,readonly,pending',
            ]);
            
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            $email = strtolower(trim($data['email']));
            
            // Check if user already exists with this email
            $existingIdentity = $db->queryOne(
                "SELECT ui.user_id FROM user_identities ui WHERE ui.oauth_email = ?",
                [$email]
            );
            
            if ($existingIdentity) {
                // Check if already in charity
                $alreadyInCharity = $db->queryOne(
                    "SELECT 1 FROM charity_users WHERE charity_id = ? AND user_id = ?",
                    [$charityId, $existingIdentity['user_id']]
                );
                
                if ($alreadyInCharity) {
                    throw new ValidationException(['This user is already a member of this charity']);
                }
                
                // Add to charity with specified role
                $insertData = [
                    'charity_id' => $charityId,
                    'user_id' => $existingIdentity['user_id'],
                    'role' => $data['role'],
                    'is_active' => true,
                ];
                
                // If not pending, set approval info
                if ($data['role'] !== 'pending') {
                    $insertData['approved_by_user_id'] = $userId;
                    $insertData['approved_at'] = date('Y-m-d H:i:s');
                }
                
                $db->insert('charity_users', $insertData);
                
                // Also ensure email is in allowed list (for future reference)
                $existingAllowed = $db->queryOne(
                    "SELECT * FROM charity_allowed_emails WHERE charity_id = ? AND email_pattern = ?",
                    [$charityId, $email]
                );
                if (!$existingAllowed) {
                    $db->insert('charity_allowed_emails', [
                        'charity_id' => $charityId,
                        'email_pattern' => $email,
                        'pattern_type' => 'email',
                    ]);
                }
                
                $auditService->log($charityId, $userId, 'user_added', 'charity_user', $existingIdentity['user_id'], [
                    'email' => $email,
                    'role' => $data['role'],
                ]);
                
                $roleLabel = $data['role'] === 'pending' ? 'pending approval' : $data['role'];
                $this->flash('success', "User added to charity with {$roleLabel} role.");
            } else {
                // User doesn't exist yet - add to allowed emails so they can sign up
                $existingAllowed = $db->queryOne(
                    "SELECT * FROM charity_allowed_emails WHERE charity_id = ? AND email_pattern = ?",
                    [$charityId, $email]
                );
                
                if (!$existingAllowed) {
                    $db->insert('charity_allowed_emails', [
                        'charity_id' => $charityId,
                        'email_pattern' => $email,
                        'pattern_type' => 'email',
                    ]);
                }
                
                $auditService->log($charityId, $userId, 'user_invited', 'charity_user', null, [
                    'email' => $email,
                    'role' => $data['role'],
                ]);
                
                $this->flash('success', "Email {$email} authorized. They will be added when they log in (note: role will be assigned automatically - admin for first user, standard for others).");
            }
            
            return $this->redirect($response, "/charity/$charityId/settings/users");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/users/invite");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $targetUserId = (int) $args['id'];
        
        $db = $this->get(Database::class);
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        $user = $db->queryOne(
            "SELECT cu.*, COALESCE(cu.display_name, u.display_name) as display_name, 
                    u.primary_email as user_email, u.is_super_admin
             FROM charity_users cu
             JOIN users u ON cu.user_id = u.id
             WHERE cu.charity_id = ? AND cu.user_id = ?",
            [$charityId, $targetUserId]
        );
        
        if (!$user) {
            $this->flash('error', 'User not found.');
            return $this->redirect($response, "/charity/$charityId/settings/users");
        }
        
        // Get effective permissions for display
        $effectivePermissions = $permissionService->getEffectivePermissions($user, $charityId);
        
        // Get available roles for this charity
        $roles = $permissionService->getAvailableRoles($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/edit_user.html.twig', array_merge($this->getCommonTemplateData($request), [
            'edit_user' => $user,
            'roles' => $roles,
            'permission_definitions' => $permissionService::PERMISSIONS,
            'permission_groups' => $permissionService::PERMISSION_GROUPS,
            'effective_permissions' => $effectivePermissions,
        ]));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $targetUserId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            // Validate role exists for this charity
            $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
            $availableRoles = $permissionService->getAvailableRoles($charityId);
            
            $role = $data['role'] ?? '';
            if (!isset($availableRoles[$role])) {
                throw new ValidationException(['Invalid role selected']);
            }
            
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            // Can't demote yourself
            if ($targetUserId === $userId && $data['role'] !== 'admin') {
                throw new ValidationException(['You cannot change your own role']);
            }
            
            // Check target user exists in this charity
            $existing = $db->queryOne(
                "SELECT * FROM charity_users WHERE charity_id = ? AND user_id = ?",
                [$charityId, $targetUserId]
            );
            
            if (!$existing) {
                throw new ValidationException(['User not found in this charity']);
            }
            
            // Build update data
            $updateData = [
                'role' => $data['role'],
            ];
            
            // If approving from pending, set approval info
            if ($existing['role'] === 'pending' && $data['role'] !== 'pending') {
                $updateData['approved_by_user_id'] = $userId;
                $updateData['approved_at'] = date('Y-m-d H:i:s');
            }
            
            // Handle custom permissions if provided
            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $customPermissions = [];
                foreach ($data['permissions'] as $perm => $value) {
                    $customPermissions[$perm] = (bool) $value;
                }
                $updateData['permissions'] = json_encode($customPermissions);
            } elseif (isset($data['clear_permissions'])) {
                // Clear custom permissions to use role defaults
                $updateData['permissions'] = null;
            }
            
            // Build SET clause dynamically
            $sets = [];
            $params = [];
            foreach ($updateData as $col => $val) {
                $sets[] = "$col = ?";
                $params[] = $val;
            }
            $params[] = $charityId;
            $params[] = $targetUserId;
            
            $db->execute(
                "UPDATE charity_users SET " . implode(', ', $sets) . " WHERE charity_id = ? AND user_id = ?",
                $params
            );
            
            $auditService->log($charityId, $userId, 'user_role_changed', 'charity_user', $targetUserId, [
                'old_role' => $existing['role'],
                'new_role' => $data['role'],
            ]);
            
            $this->flash('success', 'User updated.');
            return $this->redirect($response, "/charity/$charityId/settings/users");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/users/$targetUserId/edit");
        }
    }

    /**
     * Approve a pending user with a specific role
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $targetUserId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $role = $data['role'] ?? 'standard';
            if (!in_array($role, ['admin', 'standard', 'readonly'])) {
                $role = 'standard';
            }
            
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            // Check target user exists and is pending
            $existing = $db->queryOne(
                "SELECT * FROM charity_users WHERE charity_id = ? AND user_id = ? AND role = 'pending'",
                [$charityId, $targetUserId]
            );
            
            if (!$existing) {
                throw new ValidationException(['User not found or not pending approval']);
            }
            
            $db->execute(
                "UPDATE charity_users SET role = ?, approved_by_user_id = ?, approved_at = NOW() 
                 WHERE charity_id = ? AND user_id = ?",
                [$role, $userId, $charityId, $targetUserId]
            );
            
            $auditService->log($charityId, $userId, 'user_approved', 'charity_user', $targetUserId, [
                'role' => $role,
            ]);
            
            $this->flash('success', "User approved with {$role} role.");
            return $this->redirect($response, "/charity/$charityId/settings/users");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/users");
        }
    }

    /**
     * Reject a pending user (removes from charity)
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $targetUserId = (int) $args['id'];
        
        try {
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            // Check target user exists and is pending
            $existing = $db->queryOne(
                "SELECT * FROM charity_users WHERE charity_id = ? AND user_id = ? AND role = 'pending'",
                [$charityId, $targetUserId]
            );
            
            if (!$existing) {
                throw new ValidationException(['User not found or not pending approval']);
            }
            
            $db->execute(
                "DELETE FROM charity_users WHERE charity_id = ? AND user_id = ?",
                [$charityId, $targetUserId]
            );
            
            $auditService->log($charityId, $userId, 'user_rejected', 'charity_user', $targetUserId);
            
            $this->flash('success', 'User access request rejected.');
            return $this->redirect($response, "/charity/$charityId/settings/users");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/users");
        }
    }

    public function deactivate(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $targetUserId = (int) $args['id'];
        
        try {
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            // Can't remove yourself
            if ($targetUserId === $userId) {
                throw new ValidationException(['You cannot remove yourself']);
            }
            
            // Check at least one admin remains
            $adminCount = $db->queryOne(
                "SELECT COUNT(*) as count FROM charity_users 
                 WHERE charity_id = ? AND role = 'admin' AND user_id != ?",
                [$charityId, $targetUserId]
            );
            
            if ((int) $adminCount['count'] === 0) {
                throw new ValidationException(['Cannot remove the last admin']);
            }
            
            $db->execute(
                "DELETE FROM charity_users WHERE charity_id = ? AND user_id = ?",
                [$charityId, $targetUserId]
            );
            
            // Also remove from allowed emails
            $db->execute(
                "DELETE cae FROM charity_allowed_emails cae
                 JOIN user_identities ui ON ui.oauth_email = cae.email_pattern
                 WHERE cae.charity_id = ? AND ui.user_id = ?",
                [$charityId, $targetUserId]
            );
            
            $auditService->log($charityId, $userId, 'user_removed', 'charity_user', $targetUserId);
            
            $this->flash('success', 'User removed from charity.');
            return $this->redirect($response, "/charity/$charityId/settings/users");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/users");
        }
    }

    public function forceReauth(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $targetUserId = (int) $args['id'];
        
        $sessionManager = $this->get(SessionManager::class);
        $auditService = $this->get(AuditService::class);
        
        // Invalidate charity session for this user
        $sessionManager->invalidateCharitySession($targetUserId, $charityId);
        
        $auditService->log($charityId, $userId, 'user_session_revoked', 'charity_user', $targetUserId);
        
        $this->flash('success', 'User will need to re-authenticate for this charity.');
        return $this->redirect($response, "/charity/$charityId/settings/users");
    }

    public function forceReauthAll(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        
        $sessionManager = $this->get(SessionManager::class);
        $auditService = $this->get(AuditService::class);
        
        $sessionManager->invalidateAllCharitySessions($charityId);
        
        $auditService->log($charityId, $userId, 'all_sessions_revoked', 'charity', $charityId);
        
        $this->flash('success', 'All users will need to re-authenticate for this charity.');
        return $this->redirect($response, "/charity/$charityId/settings/users");
    }

    public function allowedEmails(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $db = $this->get(Database::class);
        
        $allowedEmails = $db->query(
            "SELECT * FROM charity_allowed_emails WHERE charity_id = ? ORDER BY pattern_type, email_pattern",
            [$charityId]
        );
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/allowed_emails.html.twig', array_merge($this->getCommonTemplateData($request), [
            'allowed_emails' => $allowedEmails,
        ]));
    }

    public function addAllowedEmail(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'pattern' => 'required|max:255',
                'pattern_type' => 'required|in:email,domain',
            ]);
            
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            $pattern = strtolower(trim($data['pattern']));
            $patternType = $data['pattern_type'];
            
            // Validate format
            if ($patternType === 'email' && !filter_var($pattern, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException(['Invalid email address']);
            }
            
            if ($patternType === 'domain') {
                // Domain should start with @ and contain a dot
                if (!str_starts_with($pattern, '@')) {
                    $pattern = '@' . $pattern;
                }
                if (!preg_match('/^@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $pattern)) {
                    throw new ValidationException(['Invalid domain format (e.g., @example.com)']);
                }
            }
            
            // Check if already exists
            $existing = $db->queryOne(
                "SELECT * FROM charity_allowed_emails WHERE charity_id = ? AND email_pattern = ?",
                [$charityId, $pattern]
            );
            
            if ($existing) {
                throw new ValidationException(['This pattern is already authorized']);
            }
            
            $db->insert('charity_allowed_emails', [
                'charity_id' => $charityId,
                'email_pattern' => $pattern,
                'pattern_type' => $patternType,
            ]);
            
            $auditService->log($charityId, $userId, 'allowed_email_added', 'charity_allowed_emails', null, [
                'pattern' => $pattern,
                'type' => $patternType,
            ]);
            
            $this->flash('success', "Authorization added: {$pattern}");
            return $this->redirect($response, "/charity/$charityId/settings/allowed-emails");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/allowed-emails");
        }
    }

    public function deleteAllowedEmail(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $patternId = (int) $args['id'];
        
        try {
            $db = $this->get(Database::class);
            $auditService = $this->get(AuditService::class);
            
            // Get the pattern first for logging
            $pattern = $db->queryOne(
                "SELECT * FROM charity_allowed_emails WHERE id = ? AND charity_id = ?",
                [$patternId, $charityId]
            );
            
            if (!$pattern) {
                throw new ValidationException(['Pattern not found']);
            }
            
            // Check if this would leave no authorizations
            $count = $db->queryValue(
                "SELECT COUNT(*) FROM charity_allowed_emails WHERE charity_id = ? AND id != ?",
                [$charityId, $patternId]
            );
            
            if ((int) $count === 0) {
                throw new ValidationException(['Cannot remove the last authorization - at least one email or domain must be allowed']);
            }
            
            $db->execute(
                "DELETE FROM charity_allowed_emails WHERE id = ? AND charity_id = ?",
                [$patternId, $charityId]
            );
            
            $auditService->log($charityId, $userId, 'allowed_email_removed', 'charity_allowed_emails', $patternId, [
                'pattern' => $pattern['email_pattern'],
                'type' => $pattern['pattern_type'],
            ]);
            
            $this->flash('success', "Authorization removed: {$pattern['email_pattern']}");
            return $this->redirect($response, "/charity/$charityId/settings/allowed-emails");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/allowed-emails");
        }
    }

    /**
     * Simple validation helper
     */
    private function validate(array $data, array $rules): void
    {
        $errors = [];
        
        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                if ($rule === 'required' && (is_null($value) || $value === '')) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
                }
                
                if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid email';
                }
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters";
                    }
                }
                
                if (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($value, $allowed, true)) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be one of: ' . implode(', ', $allowed);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
