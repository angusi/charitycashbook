<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\GiftAidService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Charity dashboard controller
 */
class DashboardController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $fundService = $this->get(FundService::class);
        $transactionService = $this->get(TransactionService::class);
        $giftAidService = $this->get(GiftAidService::class);
        
        // Get fund balances
        $funds = $fundService->getAllBalances($charityId);
        
        // Get recent transactions
        $recentTransactions = $transactionService->getTransactions($charityId, ['limit' => 10]);
        
        // Calculate totals
        $totalBalance = 0;
        $totalUnrestricted = 0;
        $totalRestricted = 0;
        
        foreach ($funds as $fund) {
            $totalBalance += $fund['balance'];
            if ($fund['fund_type'] === 'unrestricted') {
                $totalUnrestricted += $fund['balance'];
            } else {
                $totalRestricted += $fund['balance'];
            }
        }
        
        // Get current financial year dates
        $yearEnd = $this->getFinancialYearEnd($charity);
        $yearStart = $this->getFinancialYearStart($charity);
        
        // Get YTD totals
        $ytdTotals = $transactionService->getTotalsByCategory(
            $charityId,
            $yearStart,
            date('Y-m-d')
        );
        
        $ytdReceipts = 0;
        $ytdPayments = 0;
        foreach ($ytdTotals as $total) {
            if ($total['category_type'] === 'receipt') {
                $ytdReceipts += (float) $total['total'];
            } else {
                $ytdPayments += (float) $total['total'];
            }
        }
        
        // Get Gift Aid summary if admin
        $giftAidSummary = null;
        if ($userRole === 'admin') {
            $giftAidSummary = $giftAidService->calculateEligibleTotals(
                $charityId,
                $yearStart,
                date('Y-m-d')
            );
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/dashboard.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'funds' => $funds,
                'total_balance' => $totalBalance,
                'total_unrestricted' => $totalUnrestricted,
                'total_restricted' => $totalRestricted,
                'recent_transactions' => $recentTransactions,
                'ytd_receipts' => $ytdReceipts,
                'ytd_payments' => $ytdPayments,
                'financial_year_start' => $yearStart,
                'financial_year_end' => $yearEnd,
                'giftaid_summary' => $giftAidSummary,
            ]
        ));
    }
    
    private function getFinancialYearEnd(array $charity): string
    {
        $month = (int) $charity['year_end_month'];
        $day = (int) $charity['year_end_day'];
        $year = (int) date('Y');
        
        $yearEndThisYear = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
        // If year end hasn't happened yet this year, use this year's date
        // Otherwise use next year's date
        if (date('Y-m-d') <= $yearEndThisYear) {
            return $yearEndThisYear;
        }
        
        return sprintf('%04d-%02d-%02d', $year + 1, $month, $day);
    }
    
    private function getFinancialYearStart(array $charity): string
    {
        $yearEnd = $this->getFinancialYearEnd($charity);
        $yearEndDate = new \DateTime($yearEnd);
        $yearStartDate = $yearEndDate->modify('-1 year +1 day');
        return $yearStartDate->format('Y-m-d');
    }

    /**
     * Show pending approval page for users awaiting approval
     */
    public function pending(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['charity_id'];
        $userId = $request->getAttribute('user_id');
        
        $charityRepo = $this->get(\CharityCashbook\Charity\CharityRepository::class);
        
        // Get charity
        $charity = $charityRepo->findById($charityId);
        if (!$charity) {
            return $this->redirect($response, '/charities');
        }
        
        // Verify user has a pending membership
        $membership = $charityRepo->getUserMembership($charityId, $userId);
        if (!$membership || $membership['role'] !== 'pending') {
            // Not pending - redirect to dashboard
            return $this->redirect($response, "/charity/$charityId");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/pending.html.twig', [
            'charity' => $charity,
            'user' => $this->getUserFromSession($request),
        ]);
    }
}
