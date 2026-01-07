<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\MemberService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Donor management controller
 */
class DonorController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $userId = $request->getAttribute('user_id');
        $params = $this->getQueryParams($request);
        
        $donorService = $this->get(DonorService::class);
        $auditService = $this->get(AuditService::class);
        
        $search = $params['search'] ?? '';
        $giftaidOnly = !empty($params['giftaid_only']);
        
        $donors = $donorService->search($charityId, $search, $giftaidOnly ? 100 : 50, 0);
        
        if ($giftaidOnly) {
            $donors = array_filter($donors, fn($d) => $d['has_active_declaration']);
        }
        
        // Log donor list access
        $auditService->logDonorAccess($charityId, $userId, null, 'list_view');
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/donors/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'donors' => $donors,
                'search' => $search,
                'giftaid_only' => $giftaidOnly,
            ]
        ));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $userId = $request->getAttribute('user_id');
        $donorId = (int) $args['id'];
        $membership = $request->getAttribute('membership');
        $permissionService = $request->getAttribute('permission_service');
        
        $donorService = $this->get(DonorService::class);
        $memberService = $this->get(MemberService::class);
        $auditService = $this->get(AuditService::class);
        
        $donor = $donorService->get($donorId, $charityId);
        
        if (!$donor) {
            $this->flash('error', 'Donor not found.');
            return $this->redirect($response, "/charity/$charityId/donors");
        }
        
        $declarations = $donorService->getDeclarations($donorId, $charityId);
        $donations = $donorService->getDonations($donorId, $charityId);
        $linkedMember = $memberService->getByDonorId($donorId);
        
        // Log donor detail access
        $auditService->logDonorAccess($charityId, $userId, $donorId, 'view');
        
        // Check privacy permissions
        $canExportPrivacy = $permissionService->hasPermission($membership, 'privacy.export', $charityId);
        $canDeletePrivacy = $permissionService->hasPermission($membership, 'privacy.delete', $charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/donors/show.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'donor' => $donor,
                'declarations' => $declarations,
                'donations' => $donations,
                'linked_member' => $linkedMember,
                'can_export_privacy' => $canExportPrivacy,
                'can_delete_privacy' => $canDeletePrivacy,
            ]
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/donors/create.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            []
        ));
    }

    public function store(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:200',
            ]);
            
            $donorService = $this->get(DonorService::class);
            
            $id = $donorService->create($charityId, $userId, [
                'name' => trim($data['name']),
                'email' => $data['email'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['city'] ?? null,
                'address_postcode' => $data['postcode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            
            // Add Gift Aid declaration if provided
            if (!empty($data['has_declaration']) && !empty($data['declaration_date'])) {
                $donorService->addDeclaration($id, $charityId, $userId, [
                    'start_date' => $data['declaration_date'],
                    'covers_past_donations' => !empty($data['covers_past']),
                    'notes' => $data['declaration_notes'] ?? null,
                ]);
            }
            
            $this->flash('success', 'Donor created successfully.');
            return $this->redirect($response, "/charity/$charityId/donors/$id");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/donors/create");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $donorId = (int) $args['id'];
        
        $donorService = $this->get(DonorService::class);
        $memberService = $this->get(MemberService::class);
        
        $donor = $donorService->get($donorId, $charityId);
        
        if (!$donor) {
            $this->flash('error', 'Donor not found.');
            return $this->redirect($response, "/charity/$charityId/donors");
        }
        
        $declarations = $donorService->getDeclarations($donorId, $charityId);
        $linkedMember = $memberService->getByDonorId($donorId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/donors/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'donor' => $donor,
                'declarations' => $declarations,
                'linked_member' => $linkedMember,
            ]
        ));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $donorId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:200',
            ]);
            
            $donorService = $this->get(DonorService::class);
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $donor = $donorService->get($donorId, $charityId);
            if (!$donor) {
                $this->flash('error', 'Donor not found.');
                return $this->redirect($response, "/charity/$charityId/donors");
            }
            
            $donorService->update($donorId, $charityId, $userId, [
                'name' => trim($data['name']),
                'email' => $data['email'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'address_city' => $data['city'] ?? null,
                'address_postcode' => $data['postcode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            
            // Sync address to linked member if requested
            if (!empty($data['sync_address_to_member'])) {
                $linkedMember = $memberService->getByDonorId($donorId);
                if ($linkedMember) {
                    $memberService->updateAddress($linkedMember['id'], [
                        'address_line1' => $data['address_line1'] ?? null,
                        'address_line2' => $data['address_line2'] ?? null,
                        'address_city' => $data['city'] ?? null,
                        'address_postcode' => $data['postcode'] ?? null,
                    ]);
                }
            }
            
            // Handle new declaration
            if (!empty($data['add_declaration']) && !empty($data['new_declaration_date'])) {
                $donorService->addDeclaration($donorId, $charityId, $userId, [
                    'start_date' => $data['new_declaration_date'],
                    'covers_past_donations' => !empty($data['covers_past']),
                    'notes' => $data['new_declaration_notes'] ?? null,
                ]);
            }
            
            // Handle declaration withdrawal
            if (!empty($data['withdraw_declaration_id'])) {
                $donorService->withdrawDeclaration(
                    (int) $data['withdraw_declaration_id'], 
                    $charityId,
                    $userId,
                    $data['withdraw_date'] ?? date('Y-m-d')
                );
            }
            
            $this->flash('success', 'Donor updated successfully.');
            return $this->redirect($response, "/charity/$charityId/donors/$donorId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/donors/$donorId/edit");
        }
    }

    /**
     * Create a member record from a donor
     */
    public function createMemberFromDonor(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $donorId = (int) $args['id'];
        
        try {
            $donorService = $this->get(DonorService::class);
            $memberService = $this->get(MemberService::class);
            $auditService = $this->get(AuditService::class);
            
            $donor = $donorService->get($donorId, $charityId);
            if (!$donor) {
                throw new ValidationException(['Donor not found']);
            }
            
            // Check if donor already has a linked member
            $existingMember = $memberService->getByDonorId($donorId);
            if ($existingMember) {
                throw new ValidationException(['Donor is already linked to a member record']);
            }
            
            // Create member with donor's details
            $memberId = $memberService->create($charityId, [
                'name' => $donor['name'],
                'email' => $donor['email'],
                'address_line1' => $donor['address_line1'],
                'address_line2' => $donor['address_line2'],
                'address_city' => $donor['address_city'],
                'address_postcode' => $donor['address_postcode'],
                'donor_id' => $donorId, // Link directly on creation
                'notes' => 'Created from donor record',
            ], $userId);
            
            $auditService->log($charityId, $userId, 'member_created_from_donor', 'donors', $donorId, [
                'member_id' => $memberId,
            ]);
            
            $this->flash('success', 'Member record created and linked to donor.');
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }
        
        return $this->redirect($response, "/charity/$charityId/donors/$donorId");
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
