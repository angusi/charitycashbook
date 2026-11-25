<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\FinancialYear;
use CharityCashbook\Core\Money;
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
        $membership = $request->getAttribute('membership');
        $permissionService = $request->getAttribute('permission_service');
        $can = fn(string $permission): bool => $permissionService->hasPermission($membership, $permission, $charityId);

        $fundService = $this->get(FundService::class);
        $transactionService = $this->get(TransactionService::class);
        $giftAidService = $this->get(GiftAidService::class);

        $yearStart = FinancialYear::currentStart($charity);
        $yearEnd = FinancialYear::currentEnd($charity);
        $today = date('Y-m-d');

        // Fund balances (active funds, plus inactive funds that still hold money)
        $funds = [];
        $totalsByType = array_fill_keys(FundService::FUND_TYPES, 0);
        $totalBalancePence = 0;
        if ($can('funds.view')) {
            $funds = $fundService->getAllBalances($charityId);
            foreach ($funds as $fund) {
                $pence = Money::toPence($fund['balance']);
                $totalBalancePence += $pence;
                $totalsByType[$fund['fund_type']] = ($totalsByType[$fund['fund_type']] ?? 0) + $pence;
            }
        }

        $recentTransactions = $can('transactions.view')
            ? $transactionService->getTransactions($charityId, ['limit' => 10])
            : [];

        // Year-to-date totals (only transactions in the financial year to date)
        $ytdReceipts = 0;
        $ytdPayments = 0;
        if ($can('reports.view')) {
            foreach ($transactionService->getTotalsByCategory($charityId, $yearStart, $today) as $total) {
                if ($total['category_type'] === 'receipt') {
                    $ytdReceipts += Money::toPence($total['total']);
                } else {
                    $ytdPayments += Money::toPence($total['total']);
                }
            }
        }

        $giftAidSummary = $can('giftaid.view')
            ? $giftAidService->calculateEligibleTotals($charityId, $yearStart, $today)
            : null;

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/dashboard.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'funds' => $funds,
                'total_balance' => $totalBalancePence / 100,
                'total_unrestricted' => $totalsByType['unrestricted'] / 100,
                'total_restricted' => $totalsByType['restricted'] / 100,
                'total_endowment' => $totalsByType['endowment'] / 100,
                'recent_transactions' => $recentTransactions,
                'ytd_receipts' => $ytdReceipts / 100,
                'ytd_payments' => $ytdPayments / 100,
                'financial_year_start' => $yearStart,
                'financial_year_end' => $yearEnd,
                'giftaid_summary' => $giftAidSummary,
            ]
        ));
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
