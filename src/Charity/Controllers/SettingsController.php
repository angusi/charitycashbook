<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\FinancialYear;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Charity settings controller
 */
class SettingsController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');
        $lockedUntil = $charity['books_locked_until'] ?? null;
        $closeCandidate = FinancialYear::lastCompletedEnd($charity);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'books' => [
                    'locked_until' => $lockedUntil,
                    'close_candidate' => ($lockedUntil === null || $closeCandidate > $lockedUntil) ? $closeCandidate : null,
                    'close_candidate_start' => FinancialYear::startFor($charity, $closeCandidate),
                    'reopen_to' => $lockedUntil ? FinancialYear::previousEnd($charity, $lockedUntil) : null,
                ],
            ]
        ));
    }

    /**
     * Close the books for the most recent completed financial year
     */
    public function closeYear(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userId = $request->getAttribute('user_id');
        $db = $this->get(DatabaseInterface::class);

        $lockedUntil = $charity['books_locked_until'] ?? null;
        $closeTo = FinancialYear::lastCompletedEnd($charity);

        if ($lockedUntil !== null && $closeTo <= $lockedUntil) {
            $this->flash('error', "The books are already closed up to $lockedUntil.");
            return $this->redirect($response, "/charity/$charityId/settings");
        }

        // Estimated fund allocations must be confirmed before a year is closed
        $needsReview = (int) $db->queryValue(
            "SELECT COUNT(*) FROM transactions
             WHERE charity_id = ? AND is_deleted = FALSE AND allocation_needs_review = TRUE AND transaction_date <= ?",
            [$charityId, $closeTo]
        );
        if ($needsReview > 0) {
            $this->flash('error', "$needsReview transaction(s) up to $closeTo need their fund allocation reviewed before the year can be closed.");
            return $this->redirect($response, "/charity/$charityId/transactions?needs_review=1&date_to=$closeTo");
        }

        $db->execute("UPDATE charities SET books_locked_until = ? WHERE id = ?", [$closeTo, $charityId]);

        $this->get(AuditService::class)->log($charityId, $userId, 'books_closed', 'charity', $charityId, [
            'locked_until' => $closeTo,
            'previously_locked_until' => $lockedUntil,
        ]);

        $this->flash('success', "Books closed up to $closeTo. Transactions on or before that date can no longer be changed.");
        return $this->redirect($response, "/charity/$charityId/settings");
    }

    /**
     * Reopen the most recently closed financial year
     */
    public function reopenYear(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userId = $request->getAttribute('user_id');

        $lockedUntil = $charity['books_locked_until'] ?? null;
        if ($lockedUntil === null) {
            $this->flash('error', 'No financial year is closed.');
            return $this->redirect($response, "/charity/$charityId/settings");
        }

        $reopenTo = FinancialYear::previousEnd($charity, $lockedUntil);
        // Stop locking altogether once there is nothing before the earlier year end
        $db = $this->get(DatabaseInterface::class);
        $earliest = $db->queryValue(
            "SELECT MIN(transaction_date) FROM transactions WHERE charity_id = ? AND is_deleted = FALSE",
            [$charityId]
        );
        $newLock = ($earliest && $earliest <= $reopenTo) ? $reopenTo : null;

        $db->execute("UPDATE charities SET books_locked_until = ? WHERE id = ?", [$newLock, $charityId]);

        $this->get(AuditService::class)->log($charityId, $userId, 'books_reopened', 'charity', $charityId, [
            'locked_until' => $newLock,
            'previously_locked_until' => $lockedUntil,
        ]);

        $this->flash('warning', $newLock
            ? "Reopened the year ending $lockedUntil. Books are now closed up to $newLock."
            : "Reopened the year ending $lockedUntil. No years are closed now.");
        return $this->redirect($response, "/charity/$charityId/settings");
    }

    public function updateGeneral(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:200',
                'year_end_day' => 'required',
                'year_end_month' => 'required',
            ]);
            
            // Validate year end date
            $yearEndDay = (int) $data['year_end_day'];
            $yearEndMonth = (int) $data['year_end_month'];
            if (!$this->isValidDayMonth($yearEndDay, $yearEndMonth)) {
                throw new ValidationException(['Invalid financial year end date']);
            }
            
            // Validate membership year type
            $membershipYearType = $data['membership_year_type'] ?? 'financial';
            if (!in_array($membershipYearType, ['financial', 'calendar', 'anniversary'])) {
                $membershipYearType = 'financial';
            }
            
            $charityRepo = $this->get(CharityRepository::class);
            $auditService = $this->get(AuditService::class);
            
            $charityRepo->update($charityId, [
                'name' => trim($data['name']),
                'registration_number' => $data['charity_number'] ?? null,
                'hmrc_reference' => $data['hmrc_reference'] ?? null,
                'giftaid_eligible_from_date' => !empty($data['giftaid_eligible_from_date']) ? $data['giftaid_eligible_from_date'] : null,
                'address' => $data['address'] ?? null,
                'year_end_day' => $yearEndDay,
                'year_end_month' => $yearEndMonth,
                'membership_year_type' => $membershipYearType,
            ]);
            
            $auditService->log($charityId, $userId, 'settings_updated', 'charity', $charityId, [
                'section' => 'general',
            ]);
            
            $this->flash('success', 'General settings updated.');
            return $this->redirect($response, "/charity/$charityId/settings");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings");
        }
    }
    
    private function isValidDayMonth(int $day, int $month): bool
    {
        if ($month < 1 || $month > 12) {
            return false;
        }
        $maxDays = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return $day >= 1 && $day <= $maxDays[$month - 1];
    }

    public function updateAuth(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $charityRepo = $this->get(CharityRepository::class);
            $auditService = $this->get(AuditService::class);
            
            // Session timeout in minutes - store directly
            $sessionTimeoutMinutes = !empty($data['session_timeout']) ? (int) $data['session_timeout'] : 480;
            $sessionTimeoutMinutes = max(15, min(1440, $sessionTimeoutMinutes)); // 15 min to 24 hours

            $charityRepo->update($charityId, [
                'session_timeout_minutes' => $sessionTimeoutMinutes,
            ]);
            
            $auditService->log($charityId, $userId, 'settings_updated', 'charity', $charityId, [
                'section' => 'auth',
                'session_timeout_minutes' => $sessionTimeoutMinutes,
            ]);
            
            $this->flash('success', 'Authentication settings updated.');
            return $this->redirect($response, "/charity/$charityId/settings");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings");
        }
    }

    /**
     * Show role permissions configuration
     */
    public function roles(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        // Get all roles for this charity
        $roles = $permissionService->getRoles($charityId);
        
        // Add user count to each role
        foreach ($roles as &$role) {
            $role['user_count'] = $permissionService->getUserCountByRole($charityId, $role['name']);
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/roles.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'roles' => $roles,
                'permission_definitions' => $permissionService::PERMISSIONS,
                'permission_groups' => $permissionService::PERMISSION_GROUPS,
            ]
        ));
    }

    /**
     * Show create role form
     */
    public function createRole(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/edit_role.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'role' => null,
                'permission_definitions' => $permissionService::PERMISSIONS,
                'permission_groups' => $permissionService::PERMISSION_GROUPS,
                'role_colours' => $permissionService::ROLE_COLOURS,
            ]
        ));
    }

    /**
     * Store new role
     */
    public function storeRole(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:50',
                'display_name' => 'required|max:100',
            ]);
            
            $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
            $auditService = $this->get(AuditService::class);
            
            // Sanitize name to be URL-safe
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($data['name'])));
            
            // Validate colour if provided
            $colour = $data['colour'] ?? null;
            if ($colour && !isset($permissionService::ROLE_COLOURS[$colour])) {
                $colour = null;
            }
            
            $roleId = $permissionService->createRole($charityId, [
                'name' => $name,
                'display_name' => trim($data['display_name']),
                'description' => $data['description'] ?? null,
                'colour' => $colour,
                'permissions' => $data['permissions'] ?? [],
            ]);
            
            $auditService->log($charityId, $userId, 'role_created', 'charity_roles', $roleId, [
                'name' => $name,
                'display_name' => $data['display_name'],
            ]);
            
            $this->flash('success', 'Role created.');
            return $this->redirect($response, "/charity/$charityId/settings/roles");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/roles/create");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/settings/roles/create");
        }
    }

    /**
     * Show edit role form
     */
    public function editRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $roleId = (int) $args['id'];
        
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        $role = $permissionService->getRoleById($roleId);
        if (!$role || $role['charity_id'] !== $charityId) {
            $this->flash('error', 'Role not found.');
            return $this->redirect($response, "/charity/$charityId/settings/roles");
        }
        
        $role['user_count'] = $permissionService->getUserCountByRole($charityId, $role['name']);
        
        // Get all roles for reassignment dropdown
        $allRoles = $permissionService->getRoles($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/edit_role.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'role' => $role,
                'roles' => $allRoles,
                'permission_definitions' => $permissionService::PERMISSIONS,
                'permission_groups' => $permissionService::PERMISSION_GROUPS,
                'role_colours' => $permissionService::ROLE_COLOURS,
            ]
        ));
    }

    /**
     * Update role permissions
     */
    public function updateRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $roleId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
            $auditService = $this->get(AuditService::class);
            
            $role = $permissionService->getRoleById($roleId);
            if (!$role || $role['charity_id'] !== $charityId) {
                throw new ValidationException(['Role not found']);
            }
            
            $this->validate($data, [
                'display_name' => 'required|max:100',
            ]);
            
            // Validate colour if provided
            $colour = $data['colour'] ?? null;
            if ($colour && !isset($permissionService::ROLE_COLOURS[$colour])) {
                $colour = null;
            }
            
            $permissionService->updateRole($roleId, [
                'display_name' => trim($data['display_name']),
                'description' => $data['description'] ?? null,
                'colour' => $colour,
                'permissions' => $data['permissions'] ?? [],
            ]);
            
            $auditService->log($charityId, $userId, 'role_updated', 'charity_roles', $roleId, [
                'name' => $role['name'],
            ]);
            
            $this->flash('success', 'Role updated.');
            return $this->redirect($response, "/charity/$charityId/settings/roles");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/roles/$roleId/edit");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/settings/roles/$roleId/edit");
        }
    }

    /**
     * Delete a role
     */
    public function deleteRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $roleId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
            $auditService = $this->get(AuditService::class);
            
            $role = $permissionService->getRoleById($roleId);
            if (!$role || $role['charity_id'] !== $charityId) {
                throw new ValidationException(['Role not found']);
            }
            
            $reassignTo = $data['reassign_to'] ?? null;
            if (!$reassignTo) {
                throw new ValidationException(['Please select a role to reassign users to']);
            }
            
            $permissionService->deleteRole($roleId, $reassignTo);
            
            $auditService->log($charityId, $userId, 'role_deleted', 'charity_roles', $roleId, [
                'name' => $role['name'],
                'reassigned_to' => $reassignTo,
            ]);
            
            $this->flash('success', 'Role deleted and users reassigned.');
            return $this->redirect($response, "/charity/$charityId/settings/roles");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/settings/roles");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/settings/roles");
        }
    }

    /**
     * Show modules management page
     */
    public function modules(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        
        $moduleService = $this->get(\CharityCashbook\Charity\ModuleService::class);
        $modules = $moduleService->getModulesForCharity($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/settings/modules.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            ['modules' => $modules]
        ));
    }

    /**
     * Enable a module
     */
    public function enableModule(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $moduleId = $args['module'];
        
        try {
            $moduleService = $this->get(\CharityCashbook\Charity\ModuleService::class);
            $auditService = $this->get(AuditService::class);
            
            $moduleService->enableModule($charityId, $moduleId, $userId);
            
            $auditService->log($charityId, $userId, 'module_enabled', 'charity_modules', null, [
                'module' => $moduleId,
            ]);
            
            // For cashbooks module, redirect to setup wizard
            if ($moduleId === 'cashbooks') {
                $this->flash('success', 'Cash Books module enabled. Let\'s set up your cash books.');
                return $this->redirect($response, "/charity/$charityId/cashbooks/setup");
            }
            
            $this->flash('success', 'Module enabled.');
            
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/charity/$charityId/settings/modules");
    }

    /**
     * Disable a module
     */
    public function disableModule(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $moduleId = $args['module'];
        
        try {
            $moduleService = $this->get(\CharityCashbook\Charity\ModuleService::class);
            $auditService = $this->get(AuditService::class);
            
            $moduleService->disableModule($charityId, $moduleId, $userId);
            
            $auditService->log($charityId, $userId, 'module_disabled', 'charity_modules', null, [
                'module' => $moduleId,
            ]);
            
            $this->flash('success', 'Module disabled.');
            
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/charity/$charityId/settings/modules");
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
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters";
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
