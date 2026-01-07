<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Gift Aid management controller
 */
class GiftAidController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $giftAidService = $this->get(GiftAidService::class);
        
        // Get eligible transactions grouped by status
        $eligibleTransactions = $giftAidService->getEligibleTransactions($charityId);
        $claimableAmount = $giftAidService->calculateClaimableAmount($charityId);
        
        // Get current year GASDS status
        // GASDS limit is £2,000 claim per year (= £8,000 in donations at 25%)
        // Plus cannot exceed 10x your regular Gift Aid claims
        $currentYear = $giftAidService->getFinancialYear(date('Y-m-d'));
        $gasdsDonations = $giftAidService->getGasdsUsed($charityId, $currentYear);
        $gasdsClaimed = $gasdsDonations * 0.25; // Convert to claim amount
        $gasdsAbsoluteCap = 2000.00; // £2,000 max per year
        $gasdsClaimRemaining = max(0, $gasdsAbsoluteCap - $gasdsClaimed);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'eligible_transactions' => $eligibleTransactions,
                'claimable_amount' => $claimableAmount,
                'gasds_donations' => $gasdsDonations,
                'gasds_claimed' => $gasdsClaimed,
                'gasds_claim_remaining' => $gasdsClaimRemaining,
                'gasds_cap' => $gasdsAbsoluteCap,
                'financial_year' => $currentYear,
            ]
        ));
    }

    public function claims(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $claims = $giftAidService->getClaims($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/claims.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'claims' => $claims,
            ]
        ));
    }

    public function createClaim(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $giftAidService = $this->get(GiftAidService::class);
        
        // Get eligible unclaimed transactions
        $eligibleTransactions = $giftAidService->getEligibleTransactions($charityId, true);
        $claimableAmount = $giftAidService->calculateClaimableAmount($charityId);
        
        // Group by type for display
        $standardDonations = [];
        $gasdsDonations = [];
        
        foreach ($eligibleTransactions as $t) {
            if ($t['giftaid_status'] === 'gasds') {
                $gasdsDonations[] = $t;
            } else {
                $standardDonations[] = $t;
            }
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/create_claim.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'standard_donations' => $standardDonations,
                'gasds_donations' => $gasdsDonations,
                'claimable_amount' => $claimableAmount,
            ]
        ));
    }

    public function storeClaim(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $giftAidService = $this->get(GiftAidService::class);
            $auditService = $this->get(AuditService::class);
            
            // Get selected transaction IDs
            $transactionIds = $data['transactions'] ?? $data['transaction_ids'] ?? [];
            if (empty($transactionIds)) {
                throw new ValidationException(['No transactions selected for claim']);
            }
            
            // Ensure they're integers
            $transactionIds = array_map('intval', $transactionIds);
            
            // Create the claim
            $periodStart = $data['period_start'] ?? date('Y-m-01');
            $periodEnd = $data['period_end'] ?? date('Y-m-d');
            
            $claimId = $giftAidService->createClaim($charityId, $userId, $periodStart, $periodEnd, $transactionIds);
            
            $this->flash('success', 'Gift Aid claim created successfully.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/create");
        }
    }

    public function showClaim(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $claimId = (int) $args['id'];
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $claim = $giftAidService->getClaim($claimId, $charityId);
        
        if (!$claim) {
            $this->flash('error', 'Claim not found.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims");
        }
        
        $transactions = $giftAidService->getClaimTransactions($claimId, $charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/show_claim.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'claim' => $claim,
                'transactions' => $transactions,
            ]
        ));
    }

    public function editClaim(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $claimId = (int) $args['id'];
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $claim = $giftAidService->getClaim($claimId, $charityId);
        
        if (!$claim) {
            $this->flash('error', 'Claim not found.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims");
        }
        
        if ($claim['status'] !== 'draft') {
            $this->flash('error', 'Only draft claims can be edited.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
        }
        
        // Get transactions currently in the claim
        $claimTransactions = $giftAidService->getClaimTransactions($claimId, $charityId);
        $claimTransactionIds = array_column($claimTransactions, 'id');
        
        // Get other eligible unclaimed transactions
        $eligibleTransactions = $giftAidService->getEligibleTransactions($charityId, true);
        
        // Merge and mark which are in the claim
        $allTransactions = [];
        
        // Add claim transactions first (marked as in_claim)
        foreach ($claimTransactions as $t) {
            $t['in_claim'] = true;
            $allTransactions[] = $t;
        }
        
        // Add other eligible transactions
        foreach ($eligibleTransactions as $t) {
            if (!in_array($t['id'], $claimTransactionIds)) {
                $t['in_claim'] = false;
                $allTransactions[] = $t;
            }
        }
        
        // Sort by date
        usort($allTransactions, fn($a, $b) => $a['transaction_date'] <=> $b['transaction_date']);
        
        // Separate by type for display
        $standardDonations = array_filter($allTransactions, fn($t) => $t['giftaid_status'] !== 'gasds');
        $gasdsDonations = array_filter($allTransactions, fn($t) => $t['giftaid_status'] === 'gasds');
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/edit_claim.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'claim' => $claim,
                'standard_donations' => array_values($standardDonations),
                'gasds_donations' => array_values($gasdsDonations),
            ]
        ));
    }

    public function updateClaim(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $claimId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $giftAidService = $this->get(GiftAidService::class);
            
            $transactionIds = $data['transactions'] ?? $data['transaction_ids'] ?? [];
            if (empty($transactionIds)) {
                throw new ValidationException(['No transactions selected for claim']);
            }
            
            $transactionIds = array_map('intval', $transactionIds);
            
            $periodStart = $data['period_start'] ?? date('Y-m-01');
            $periodEnd = $data['period_end'] ?? date('Y-m-d');
            
            $giftAidService->updateClaim($claimId, $charityId, $userId, $periodStart, $periodEnd, $transactionIds);
            
            $this->flash('success', 'Claim updated successfully.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId/edit");
        }
    }

    public function submitClaim(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $claimId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $giftAidService = $this->get(GiftAidService::class);
            
            $hmrcReference = $data['reference'] ?? null;
            $giftAidService->submitClaim($claimId, $charityId, $userId, $hmrcReference);
            
            $this->flash('success', 'Claim marked as submitted to HMRC.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
        }
    }

    public function unsubmitClaim(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $claimId = (int) $args['id'];
        
        try {
            $giftAidService = $this->get(GiftAidService::class);
            
            $giftAidService->unsubmitClaim($claimId, $charityId, $userId);
            
            $this->flash('success', 'Claim status reverted to draft.');
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/giftaid/claims/$claimId");
        }
    }
}
