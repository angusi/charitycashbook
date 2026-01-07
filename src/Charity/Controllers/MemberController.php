<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\MemberService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Member management controller
 */
class MemberController extends BaseController
{
    /**
     * List all members
     */
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $params = $this->getQueryParams($request);
        
        $memberService = $this->get(MemberService::class);
        
        $filters = [
            'active_only' => !isset($params['show_inactive']),
            'trustees_only' => isset($params['trustees_only']),
            'membership_type' => $params['membership_type'] ?? null,
            'search' => $params['search'] ?? null,
        ];
        
        $members = $memberService->getAll($charityId, $filters);
        $membershipTypes = $memberService->getMembershipTypes($charityId);
        $statistics = $memberService->getStatistics($charityId, $charity);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'members' => $members,
                'membership_types' => $membershipTypes,
                'statistics' => $statistics,
                'filters' => $filters,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Show member details
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $memberId = (int) $args['id'];
        
        $memberService = $this->get(MemberService::class);
        $donorService = $this->get(DonorService::class);
        
        $member = $memberService->getById($memberId);
        if (!$member || $member['charity_id'] !== $charityId) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }
        
        $dues = $memberService->getMemberDues($memberId);
        $donors = $donorService->getAll($charityId);
        $trusteeHistory = $memberService->getTrusteeHistory($memberId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/show.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'member' => $member,
                'dues' => $dues,
                'donors' => $donors,
                'trustee_history' => $trusteeHistory,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Show create member form
     */
    public function create(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        
        $memberService = $this->get(MemberService::class);
        $donorService = $this->get(DonorService::class);
        
        $membershipTypes = $memberService->getMembershipTypes($charityId);
        $donors = $donorService->getAll($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'member' => null,
                'membership_types' => $membershipTypes,
                'donors' => $donors,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Store new member
     */
    public function store(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $memberId = $memberService->create($charityId, $data, $userId);
            
            $auditService->log($charityId, $userId, 'member_created', 'members', $memberId, [
                'name' => trim($data['name']),
            ]);
            
            $this->flash('success', 'Member created successfully.');
            return $this->redirect($response, "/charity/$charityId/members/$memberId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/members/create");
        }
    }

    /**
     * Show edit member form
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $memberId = (int) $args['id'];
        
        $memberService = $this->get(MemberService::class);
        $donorService = $this->get(DonorService::class);
        
        $member = $memberService->getById($memberId);
        if (!$member || $member['charity_id'] !== $charityId) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }
        
        $membershipTypes = $memberService->getMembershipTypes($charityId);
        $donors = $donorService->getAll($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'member' => $member,
                'membership_types' => $membershipTypes,
                'donors' => $donors,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Update member
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $donorService = $this->get(DonorService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            $memberService->update($memberId, $data, $userId);
            
            // Sync address to linked donor if requested
            if (!empty($data['sync_address_to_donor']) && $member['donor_id']) {
                $donorService->updateAddress($member['donor_id'], [
                    'address_line1' => $data['address_line1'] ?? null,
                    'address_line2' => $data['address_line2'] ?? null,
                    'address_city' => $data['address_city'] ?? null,
                    'address_postcode' => $data['address_postcode'] ?? null,
                ]);
                $auditService->log($charityId, $userId, 'donor_address_synced', 'donors', $member['donor_id'], [
                    'from' => 'member',
                    'member_id' => $memberId,
                ]);
            }
            
            $auditService->log($charityId, $userId, 'member_updated', 'members', $memberId, [
                'name' => trim($data['name']),
            ]);
            
            $this->flash('success', 'Member updated successfully.');
            return $this->redirect($response, "/charity/$charityId/members/$memberId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/members/$memberId/edit");
        }
    }

    /**
     * List trustees
     */
    public function trustees(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        
        $memberService = $this->get(MemberService::class);
        $trustees = $memberService->getTrustees($charityId);
        $trusteeHistory = $memberService->getAllTrusteeHistory($charityId);
        
        // Filter past trustees (those with end_date set)
        $pastTrustees = array_filter($trusteeHistory, fn($role) => $role['end_date'] !== null);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/trustees.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'trustees' => $trustees,
                'past_trustees' => $pastTrustees,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Export members list as CSV
     */
    public function exportMembers(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userId = $request->getAttribute('user_id');
        $membership = $request->getAttribute('membership');
        $params = $this->getQueryParams($request);
        
        $memberService = $this->get(MemberService::class);
        $auditService = $this->get(AuditService::class);
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        // Only include addresses if user has view_detail permission
        $canViewDetail = $permissionService->hasPermission($membership, 'members.view_detail', $charityId);
        $includeAddresses = isset($params['include_addresses']) && $canViewDetail;
        $activeOnly = !isset($params['include_inactive']);
        
        $members = $memberService->getAll($charityId, ['active_only' => $activeOnly]);
        
        $auditService->log($charityId, $userId, 'members_exported', 'members', null, [
            'include_addresses' => $includeAddresses,
            'count' => count($members),
        ]);
        
        // Build CSV
        $output = fopen('php://temp', 'r+');
        
        // Headers
        $headers = ['Member Number', 'Name', 'Email', 'Phone', 'Membership Type', 'Joined Date', 'Status'];
        if ($includeAddresses) {
            $headers = array_merge($headers, ['Address Line 1', 'Address Line 2', 'City', 'County', 'Postcode']);
        }
        fputcsv($output, $headers);
        
        // Data rows
        foreach ($members as $member) {
            $row = [
                $member['member_number'] ?? '',
                $member['name'],
                $member['email'] ?? '',
                $member['phone'] ?? '',
                $member['membership_type'] ?? '',
                $member['joined_date'] ?? '',
                $member['is_active'] ? 'Active' : 'Inactive',
            ];
            if ($includeAddresses) {
                $row = array_merge($row, [
                    $member['address_line1'] ?? '',
                    $member['address_line2'] ?? '',
                    $member['address_city'] ?? '',
                    $member['address_county'] ?? '',
                    $member['address_postcode'] ?? '',
                ]);
            }
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        $filename = sprintf('%s-members-%s.csv', 
            preg_replace('/[^a-z0-9]+/i', '-', $charity['name']),
            date('Y-m-d')
        );
        
        $response = $response->withHeader('Content-Type', 'text/csv');
        $response = $response->withHeader('Content-Disposition', "attachment; filename=\"$filename\"");
        $response->getBody()->write($csv);
        
        return $response;
    }

    /**
     * Export trustees list as CSV
     */
    public function exportTrustees(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userId = $request->getAttribute('user_id');
        $membership = $request->getAttribute('membership');
        $params = $this->getQueryParams($request);
        
        $memberService = $this->get(MemberService::class);
        $auditService = $this->get(AuditService::class);
        $permissionService = $this->get(\CharityCashbook\Charity\PermissionService::class);
        
        // Only include addresses if user has view_detail permission
        $canViewDetail = $permissionService->hasPermission($membership, 'members.view_detail', $charityId);
        $includeAddresses = isset($params['include_addresses']) && $canViewDetail;
        $includePast = isset($params['include_past']);
        
        // Get current trustees
        $trustees = $memberService->getTrustees($charityId);
        
        // If including past trustees, get the full history
        $pastTrustees = [];
        if ($includePast) {
            $pastTrustees = $memberService->getAllTrusteeHistory($charityId);
            // Filter to only ended roles (past trustees)
            $pastTrustees = array_filter($pastTrustees, fn($t) => !empty($t['end_date']));
        }
        
        $auditService->log($charityId, $userId, 'trustees_exported', 'members', null, [
            'include_addresses' => $includeAddresses,
            'include_past' => $includePast,
            'count' => count($trustees) + count($pastTrustees),
        ]);
        
        // Build CSV
        $output = fopen('php://temp', 'r+');
        
        // Headers
        $headers = ['Name', 'Member Number', 'Role', 'Start Date', 'End Date', 'Status', 'Email', 'Phone'];
        if ($includeAddresses) {
            $headers = array_merge($headers, ['Address Line 1', 'Address Line 2', 'City', 'County', 'Postcode']);
        }
        fputcsv($output, $headers);
        
        // Current trustees
        foreach ($trustees as $trustee) {
            $row = [
                $trustee['name'],
                $trustee['member_number'] ?? '',
                $trustee['current_trustee_role'] ?? 'Trustee',
                $trustee['current_trustee_start'] ?? '',
                '', // No end date for current trustees
                'Current',
                $trustee['email'] ?? '',
                $trustee['phone'] ?? '',
            ];
            if ($includeAddresses) {
                $row = array_merge($row, [
                    $trustee['address_line1'] ?? '',
                    $trustee['address_line2'] ?? '',
                    $trustee['address_city'] ?? '',
                    $trustee['address_county'] ?? '',
                    $trustee['address_postcode'] ?? '',
                ]);
            }
            fputcsv($output, $row);
        }
        
        // Past trustees (if included)
        foreach ($pastTrustees as $trustee) {
            $row = [
                $trustee['name'],
                $trustee['member_number'] ?? '',
                $trustee['role'] ?? 'Trustee',
                $trustee['start_date'] ?? '',
                $trustee['end_date'] ?? '',
                'Past',
                '', // No contact info in history table
                '',
            ];
            if ($includeAddresses) {
                $row = array_merge($row, ['', '', '', '', '']); // No addresses in history
            }
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        $filename = sprintf('%s-trustees-%s.csv', 
            preg_replace('/[^a-z0-9]+/i', '-', $charity['name']),
            date('Y-m-d')
        );
        
        $response = $response->withHeader('Content-Type', 'text/csv');
        $response = $response->withHeader('Content-Disposition', "attachment; filename=\"$filename\"");
        $response->getBody()->write($csv);
        
        return $response;
    }

    /**
     * Add trustee role to member
     */
    public function addTrusteeRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            if (empty($data['start_date'])) {
                throw new ValidationException(['Start date is required']);
            }
            
            $roleId = $memberService->addTrusteeRole($charityId, $memberId, $data, $userId);
            
            $auditService->log($charityId, $userId, 'trustee_role_added', 'member_trustee_roles', $roleId, [
                'member_id' => $memberId,
                'role' => $data['role'] ?? null,
            ]);
            
            $this->flash('success', 'Trustee role added.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/$memberId");
    }

    /**
     * End trustee role
     */
    public function endTrusteeRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $roleId = (int) $args['role_id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $role = $memberService->getTrusteeRole($roleId);
            if (!$role || $role['charity_id'] !== $charityId) {
                throw new ValidationException(['Trustee role not found']);
            }
            
            $endDate = $data['end_date'] ?? date('Y-m-d');
            $memberService->endTrusteeRole($roleId, $endDate);
            
            $auditService->log($charityId, $userId, 'trustee_role_ended', 'member_trustee_roles', $roleId, [
                'end_date' => $endDate,
            ]);
            
            $this->flash('success', 'Trustee role ended.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        $returnTo = $data['return_to'] ?? "/charity/$charityId/members/trustees";
        return $this->redirect($response, $returnTo);
    }

    /**
     * Update trustee role
     */
    public function updateTrusteeRole(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $roleId = (int) $args['role_id'];
        $data = $this->getPostData($request);
        
        $memberService = $this->get(MemberService::class);
        $role = $memberService->getTrusteeRole($roleId);
        
        try {
            $auditService = $this->get(AuditService::class);
            
            if (!$role || $role['charity_id'] !== $charityId) {
                throw new ValidationException(['Trustee role not found']);
            }
            
            if (empty($data['start_date'])) {
                throw new ValidationException(['Start date is required']);
            }
            
            $memberService->updateTrusteeRole($roleId, $data);
            
            $auditService->log($charityId, $userId, 'trustee_role_updated', 'member_trustee_roles', $roleId, [
                'role' => $data['role'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
            ]);
            
            $this->flash('success', 'Trustee role updated.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        if ($role) {
            return $this->redirect($response, "/charity/$charityId/members/{$role['member_id']}");
        }
        return $this->redirect($response, "/charity/$charityId/members");
    }

    // ========== DUES MANAGEMENT ==========

    /**
     * List all dues
     */
    public function dues(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $params = $this->getQueryParams($request);
        
        $memberService = $this->get(MemberService::class);
        
        // Get current membership period for default filter
        $currentPeriod = $memberService->calculateMembershipPeriod($charity);
        
        // Get all distinct period labels for the filter dropdown
        $periodLabels = $memberService->getDuesPeriodLabels($charityId);
        
        $filters = [
            'status' => $params['status'] ?? null,
            'period_label' => $params['period'] ?? $currentPeriod['period_label'],
        ];
        
        $dues = $memberService->getAllDues($charityId, $filters);
        $statistics = $memberService->getDuesStatistics($charityId, $filters['period_label']);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/dues.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'dues' => $dues,
                'statistics' => $statistics,
                'filters' => $filters,
                'period_labels' => $periodLabels,
                'current_period' => $currentPeriod,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Show create dues form for a member
     */
    public function createDues(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $memberId = (int) $args['id'];
        
        $memberService = $this->get(MemberService::class);
        
        $member = $memberService->getById($memberId);
        if (!$member || $member['charity_id'] !== $charityId) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }
        
        $membershipTypes = $memberService->getMembershipTypes($charityId);
        
        // Find the fee for this member's type
        $defaultFee = 0;
        foreach ($membershipTypes as $type) {
            if ($type['name'] === $member['membership_type']) {
                $defaultFee = $type['annual_fee'] ?? 0;
                break;
            }
        }
        
        // Get default period based on charity settings (and member join date for anniversary type)
        $defaultPeriod = $memberService->calculateMembershipPeriod($charity, $member['joined_date']);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/create_dues.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'member' => $member,
                'default_fee' => $defaultFee,
                'default_period' => $defaultPeriod,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Store dues for a member
     */
    public function storeDues(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            $duesId = $memberService->createDues($charityId, $memberId, $data, $userId);
            
            $auditService->log($charityId, $userId, 'dues_created', 'membership_dues', $duesId, [
                'member_id' => $memberId,
                'period' => $data['period_label'] ?? $data['period_start'],
                'amount' => $data['amount_due'],
            ]);
            
            $this->flash('success', 'Dues record created.');
            return $this->redirect($response, "/charity/$charityId/members/$memberId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/members/$memberId/dues/create");
        }
    }

    /**
     * Record payment against dues
     */
    public function recordPayment(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $duesId = (int) $args['dues_id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $amount = (float) ($data['amount'] ?? 0);
            $transactionId = !empty($data['transaction_id']) ? (int) $data['transaction_id'] : null;
            
            $memberService->recordDuesPayment($duesId, $charityId, $amount, $transactionId);
            
            $auditService->log($charityId, $userId, 'dues_payment', 'membership_dues', $duesId, [
                'amount' => $amount,
                'transaction_id' => $transactionId,
            ]);
            
            $this->flash('success', 'Payment recorded.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        // Redirect back to dues list or member page
        $returnTo = $data['return_to'] ?? "/charity/$charityId/members/dues";
        return $this->redirect($response, $returnTo);
    }

    /**
     * Waive dues
     */
    public function waiveDues(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $duesId = (int) $args['dues_id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $memberService->waiveDues($duesId, $charityId, $data['reason'] ?? null);
            
            $auditService->log($charityId, $userId, 'dues_waived', 'membership_dues', $duesId, [
                'reason' => $data['reason'] ?? null,
            ]);
            
            $this->flash('success', 'Dues waived.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        $returnTo = $data['return_to'] ?? "/charity/$charityId/members/dues";
        return $this->redirect($response, $returnTo);
    }

    /**
     * Undo dues payment/waiver - revert to pending
     */
    public function undoDues(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $duesId = (int) $args['dues_id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $memberService->undoDuesStatus($duesId, $charityId);
            
            $auditService->log($charityId, $userId, 'dues_status_reset', 'membership_dues', $duesId);
            
            $this->flash('success', 'Dues status reset to pending.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        $returnTo = $data['return_to'] ?? "/charity/$charityId/members/dues";
        return $this->redirect($response, $returnTo);
    }

    /**
     * Show bulk dues generation form
     */
    public function bulkDuesForm(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        
        $memberService = $this->get(MemberService::class);
        $membershipTypes = $memberService->getMembershipTypes($charityId);
        $statistics = $memberService->getStatistics($charityId);
        $defaultPeriod = $memberService->calculateMembershipPeriod($charity);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/bulk_dues.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'membership_types' => $membershipTypes,
                'statistics' => $statistics,
                'default_period' => $defaultPeriod,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Generate bulk dues
     */
    public function generateBulkDues(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $count = $memberService->generateBulkDues($charityId, $data, $userId);
            
            $auditService->log($charityId, $userId, 'bulk_dues_generated', 'membership_dues', null, [
                'period' => $data['period_label'] ?? $data['period_start'],
                'count' => $count,
            ]);
            
            $this->flash('success', "Generated dues for $count members.");
            return $this->redirect($response, "/charity/$charityId/members/dues");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/members/dues/generate");
        }
    }

    // ========== DONOR LINKING ==========

    /**
     * Link member to donor
     */
    public function linkDonor(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            $donorId = (int) $data['donor_id'];
            $memberService->linkToDonor($memberId, $donorId);
            
            $auditService->log($charityId, $userId, 'member_linked_donor', 'members', $memberId, [
                'donor_id' => $donorId,
            ]);
            
            $this->flash('success', 'Member linked to donor record.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/$memberId");
    }

    /**
     * Unlink member from donor
     */
    public function unlinkDonor(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            $memberService->unlinkFromDonor($memberId);
            
            $auditService->log($charityId, $userId, 'member_unlinked_donor', 'members', $memberId);
            
            $this->flash('success', 'Member unlinked from donor record.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/$memberId");
    }

    /**
     * Create a donor record from a member
     */
    public function createDonorFromMember(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        
        try {
            $memberService = $this->get(MemberService::class);
            $donorService = $this->get(DonorService::class);
            $auditService = $this->get(AuditService::class);
            
            $member = $memberService->getById($memberId);
            if (!$member || $member['charity_id'] !== $charityId) {
                throw new ValidationException(['Member not found']);
            }
            
            if ($member['donor_id']) {
                throw new ValidationException(['Member is already linked to a donor']);
            }
            
            // Create donor with member's details
            $donorId = $donorService->create($charityId, $userId, [
                'name' => $member['name'],
                'email' => $member['email'],
                'address_line1' => $member['address_line1'],
                'address_line2' => $member['address_line2'],
                'address_city' => $member['address_city'],
                'address_postcode' => $member['address_postcode'],
                'notes' => 'Created from member record',
            ]);
            
            // Link the member to the new donor
            $memberService->linkToDonor($memberId, $donorId);
            
            $auditService->log($charityId, $userId, 'donor_created_from_member', 'members', $memberId, [
                'donor_id' => $donorId,
            ]);
            
            $this->flash('success', 'Donor record created and linked to member.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/$memberId");
    }

    // ========== MEMBERSHIP TYPES ==========

    /**
     * List membership types
     */
    public function types(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        
        $memberService = $this->get(MemberService::class);
        $types = $memberService->getMembershipTypes($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/members/types.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'types' => $types,
                'active_nav' => 'members',
            ]
        ));
    }

    /**
     * Store new membership type
     */
    public function storeType(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            if (empty($data['name'])) {
                throw new ValidationException(['Name is required']);
            }
            
            $typeId = $memberService->createMembershipType($charityId, $data);
            
            $auditService->log($charityId, $userId, 'membership_type_created', 'membership_types', $typeId, [
                'name' => $data['name'],
            ]);
            
            $this->flash('success', 'Membership type created.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/types");
    }

    /**
     * Update membership type
     */
    public function updateType(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $typeId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            if (empty($data['name'])) {
                throw new ValidationException(['Name is required']);
            }
            
            $memberService->updateMembershipType($typeId, $data);
            
            $auditService->log($charityId, $userId, 'membership_type_updated', 'membership_types', $typeId, [
                'name' => $data['name'],
            ]);
            
            $this->flash('success', 'Membership type updated.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/members/types");
    }
}
