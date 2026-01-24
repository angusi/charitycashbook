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
        
        // Get transactions needing review
        $incompleteSetupCount = count($giftAidService->getTransactionsWithIncompleteSetup($charityId));
        $needsAmountReviewCount = count($giftAidService->getTransactionsNeedingAmountReview($charityId));
        
        // Get pending corrections count
        $pendingCorrectionsCount = count($giftAidService->getPendingCorrections($charityId));
        
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
                'incomplete_setup_count' => $incompleteSetupCount,
                'needs_amount_review_count' => $needsAmountReviewCount,
                'pending_corrections_count' => $pendingCorrectionsCount,
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
        
        // Get corrections applied to this claim
        $underclaims = [];
        $overclaim_total = 0;
        if ($claim['status'] === 'submitted') {
            // For submitted claims, show corrections that were applied
            $corrections = $giftAidService->getCorrectionsForClaim($claimId, $charityId);
            foreach ($corrections as $c) {
                if ($c['adjustment'] > 0) {
                    $underclaims[] = $c;
                } else {
                    $overclaim_total += abs($c['adjustment']);
                }
            }
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/show_claim.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'claim' => $claim,
                'transactions' => $transactions,
                'underclaims' => $underclaims,
                'overclaim_total' => $overclaim_total,
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

    public function review(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $incompleteSetup = $giftAidService->getTransactionsWithIncompleteSetup($charityId);
        $needsAmountConfirmation = $giftAidService->getTransactionsNeedingAmountReview($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/review.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'incomplete_setup' => $incompleteSetup,
                'needs_amount_confirmation' => $needsAmountConfirmation,
            ]
        ));
    }

    public function confirmAmounts(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $confirmed = 0;
        $errors = [];
        
        // Process each transaction's giftaid_amount
        if (!empty($data['amounts']) && is_array($data['amounts'])) {
            foreach ($data['amounts'] as $transactionId => $amount) {
                if ($amount === '' || $amount === null) {
                    continue; // Skip unconfirmed
                }
                
                try {
                    $giftAidService->confirmGiftAidAmount(
                        (int) $transactionId,
                        $charityId,
                        (float) $amount,
                        $userId
                    );
                    $confirmed++;
                } catch (ValidationException $e) {
                    $errors[] = "Transaction #$transactionId: " . implode(', ', $e->getErrors());
                }
            }
        }
        
        if ($confirmed > 0) {
            $this->flash('success', "$confirmed transaction(s) confirmed for Gift Aid.");
        }
        if (!empty($errors)) {
            $this->flash('error', implode('; ', $errors));
        }
        
        // Check if there are more to review
        $remaining = count($giftAidService->getTransactionsNeedingReview($charityId));
        if ($remaining > 0) {
            return $this->redirect($response, "/charity/$charityId/giftaid/review");
        }
        
        return $this->redirect($response, "/charity/$charityId/giftaid");
    }

    public function createCorrection(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $params = $request->getQueryParams();
        $transactionId = isset($params['transaction_id']) ? (int) $params['transaction_id'] : null;
        
        $giftAidService = $this->get(GiftAidService::class);
        
        $transaction = null;
        if ($transactionId) {
            // Get transaction details
            $transactionService = $this->get(\CharityCashbook\Charity\TransactionService::class);
            $transaction = $transactionService->get($transactionId, $charityId);
            
            // Must be claimed
            if ($transaction && $transaction['giftaid_status'] !== 'claimed') {
                $this->flash('error', 'Only claimed transactions can have corrections.');
                return $this->redirect($response, "/charity/$charityId/transactions/$transactionId");
            }
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/giftaid/create_correction.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transaction' => $transaction,
            ]
        ));
    }

    public function storeCorrection(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        
        try {
            $giftAidService = $this->get(GiftAidService::class);
            
            $transactionId = (int) ($data['transaction_id'] ?? 0);
            $correctedAmount = (float) ($data['corrected_amount'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            if (empty($reason)) {
                throw new ValidationException(['reason' => 'Please provide a reason for this correction']);
            }
            
            $giftAidService->createCorrection(
                $charityId,
                $transactionId,
                $correctedAmount,
                $reason,
                $userId
            );
            
            $this->flash('success', 'Correction created. It will be applied to your next Gift Aid claim.');
            return $this->redirect($response, "/charity/$charityId/giftaid");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            $transactionId = (int) ($data['transaction_id'] ?? 0);
            return $this->redirect($response, "/charity/$charityId/giftaid/corrections/create?transaction_id=$transactionId");
        }
    }
}
