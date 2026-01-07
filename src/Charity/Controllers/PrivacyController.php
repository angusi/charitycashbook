<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Core\PrivacyService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\MemberService;
use CharityCashbook\Charity\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Controller for GDPR privacy operations at the charity level
 */
class PrivacyController extends BaseController
{
    /**
     * Export donor personal data as ZIP
     */
    public function exportDonorData(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $donorId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $donorService = $this->get(DonorService::class);
        $auditService = $this->get(AuditService::class);

        // Verify donor exists and belongs to this charity
        $donor = $donorService->get($donorId, $charityId);
        if (!$donor) {
            $this->flash('error', 'Donor not found.');
            return $this->redirect($response, "/charity/$charityId/donors");
        }

        try {
            $zipPath = $privacyService->exportDonorData($donorId, $charityId);

            // Log the export
            $auditService->log($charityId, $userId, 'donor_data_exported', 'donor', $donorId, [
                'reason' => 'SAR/data export request',
            ]);

            // Send the file
            $filename = 'donor_data_' . $donorId . '_' . date('Y-m-d') . '.zip';
            
            $response = $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) filesize($zipPath));

            $response->getBody()->write(file_get_contents($zipPath));

            // Clean up temp file
            unlink($zipPath);

            return $response;

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to export data: ' . $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/donors/$donorId");
        }
    }

    /**
     * Show deletion/anonymisation options for a donor
     */
    public function showDonorDeletion(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $donorId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $donorService = $this->get(DonorService::class);

        $donor = $donorService->get($donorId, $charityId);
        if (!$donor) {
            $this->flash('error', 'Donor not found.');
            return $this->redirect($response, "/charity/$charityId/donors");
        }

        $deletionCheck = $privacyService->canDeleteDonorData($donorId, $charityId);
        $donorData = $privacyService->findDonorData($donorId, $charityId);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/privacy/donor_deletion.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'donor' => $donor,
                'deletion_check' => $deletionCheck,
                'data_summary' => $this->summariseData($donorData),
                'csrf_token' => $this->getCsrfToken($request),
            ]
        ));
    }

    /**
     * Process donor deletion/anonymisation request
     */
    public function processDonorDeletion(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $donorId = (int) $args['id'];
        $data = $this->getPostData($request);

        $privacyService = $this->get(PrivacyService::class);
        $donorService = $this->get(DonorService::class);
        $auditService = $this->get(AuditService::class);

        $donor = $donorService->get($donorId, $charityId);
        if (!$donor) {
            $this->flash('error', 'Donor not found.');
            return $this->redirect($response, "/charity/$charityId/donors");
        }

        // Confirm checkbox must be checked
        if (empty($data['confirm_anonymisation'])) {
            $this->flash('error', 'You must confirm you understand the implications.');
            return $this->redirect($response, "/charity/$charityId/donors/$donorId/privacy/delete");
        }

        try {
            $changes = $privacyService->anonymiseDonorData($donorId, $charityId, $userId);

            $this->flash('success', 'Donor data has been anonymised. ' . count($changes) . ' change(s) made.');
            return $this->redirect($response, "/charity/$charityId/donors");

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to anonymise data: ' . $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/donors/$donorId/privacy/delete");
        }
    }

    /**
     * Export member personal data as ZIP
     */
    public function exportMemberData(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $memberId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $memberService = $this->get(MemberService::class);
        $auditService = $this->get(AuditService::class);

        $member = $memberService->get($memberId, $charityId);
        if (!$member) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }

        try {
            $zipPath = $privacyService->exportMemberData($memberId, $charityId);

            $auditService->log($charityId, $userId, 'member_data_exported', 'member', $memberId, [
                'reason' => 'SAR/data export request',
            ]);

            $filename = 'member_data_' . $memberId . '_' . date('Y-m-d') . '.zip';
            
            $response = $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) filesize($zipPath));

            $response->getBody()->write(file_get_contents($zipPath));
            unlink($zipPath);

            return $response;

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to export data: ' . $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/members/$memberId");
        }
    }

    /**
     * Show deletion/anonymisation options for a member
     */
    public function showMemberDeletion(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $memberId = (int) $args['id'];

        $privacyService = $this->get(PrivacyService::class);
        $memberService = $this->get(MemberService::class);

        $member = $memberService->get($memberId, $charityId);
        if (!$member) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }

        $deletionCheck = $privacyService->canDeleteMemberData($memberId, $charityId);
        $memberData = $privacyService->findMemberData($memberId, $charityId);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/privacy/member_deletion.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'member' => $member,
                'deletion_check' => $deletionCheck,
                'data_summary' => $this->summariseData($memberData),
                'csrf_token' => $this->getCsrfToken($request),
            ]
        ));
    }

    /**
     * Process member deletion/anonymisation request
     */
    public function processMemberDeletion(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = (int) $request->getAttribute('user_id');
        $memberId = (int) $args['id'];
        $data = $this->getPostData($request);

        $privacyService = $this->get(PrivacyService::class);
        $memberService = $this->get(MemberService::class);

        $member = $memberService->get($memberId, $charityId);
        if (!$member) {
            $this->flash('error', 'Member not found.');
            return $this->redirect($response, "/charity/$charityId/members");
        }

        if (empty($data['confirm_anonymisation'])) {
            $this->flash('error', 'You must confirm you understand the implications.');
            return $this->redirect($response, "/charity/$charityId/members/$memberId/privacy/delete");
        }

        try {
            $changes = $privacyService->anonymiseMemberData($memberId, $charityId, $userId);

            // Also anonymise linked donor if requested
            if (!empty($data['include_linked_donor']) && $member['donor_id']) {
                $privacyService->anonymiseDonorData($member['donor_id'], $charityId, $userId);
            }

            $this->flash('success', 'Member data has been anonymised. ' . count($changes) . ' change(s) made.');
            return $this->redirect($response, "/charity/$charityId/members");

        } catch (\Exception $e) {
            $this->flash('error', 'Failed to anonymise data: ' . $e->getMessage());
            return $this->redirect($response, "/charity/$charityId/members/$memberId/privacy/delete");
        }
    }

    /**
     * Summarise data for display
     */
    private function summariseData(array $data): array
    {
        $summary = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $summary[$key] = 0;
            } elseif (is_array($value) && isset($value['id'])) {
                $summary[$key] = 1;
            } elseif (is_array($value)) {
                $summary[$key] = count($value);
            } else {
                $summary[$key] = 1;
            }
        }
        return $summary;
    }
}
