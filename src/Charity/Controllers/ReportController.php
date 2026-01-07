<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\ReportService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\GiftAidService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Reports controller
 */
class ReportController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            []
        ));
    }

    public function receiptsPayments(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $reportService = $this->get(ReportService::class);
        
        // Default to current financial year
        $dateFrom = $params['date_from'] ?? $this->getFinancialYearStart($charity);
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        
        $report = $reportService->receiptsAndPayments($charityId, $dateFrom, $dateTo);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/receipts_payments.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'report' => $report,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        ));
    }

    public function receiptsPaymentsDetailed(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $reportService = $this->get(ReportService::class);
        
        // Default to current financial year
        $dateFrom = $params['date_from'] ?? $this->getFinancialYearStart($charity);
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        
        $report = $reportService->detailedReceiptsAndPayments($charityId, $dateFrom, $dateTo);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/receipts_payments_detailed.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'report' => $report,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        ));
    }

    public function fundBreakdown(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $reportService = $this->get(ReportService::class);
        
        // Default to current financial year
        $dateFrom = $params['date_from'] ?? $this->getFinancialYearStart($charity);
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        
        $report = $reportService->fundByFundBreakdown($charityId, $dateFrom, $dateTo);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/fund_breakdown.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'report' => $report,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        ));
    }

    public function transactions(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $transactionService = $this->get(TransactionService::class);
        $fundService = $this->get(FundService::class);
        
        $dateFrom = $params['date_from'] ?? date('Y-m-01');
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        $fundId = !empty($params['fund_id']) ? (int) $params['fund_id'] : null;
        $type = $params['type'] ?? null;
        
        $transactions = $transactionService->getTransactions($charityId, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'fund_id' => $fundId,
            'type' => $type,
        ]);
        $funds = $fundService->getAll($charityId);
        
        // Calculate totals
        $totalReceipts = 0;
        $totalPayments = 0;
        foreach ($transactions as $t) {
            if ($t['transaction_type'] === 'receipt') {
                $totalReceipts += (float) $t['amount'];
            } else {
                $totalPayments += (float) $t['amount'];
            }
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/transactions.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transactions' => $transactions,
                'funds' => $funds,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'fund_id' => $fundId,
                'type' => $type,
                'total_receipts' => $totalReceipts,
                'total_payments' => $totalPayments,
            ]
        ));
    }

    public function fundSummary(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $reportService = $this->get(ReportService::class);
        
        $asOfDate = $params['as_of_date'] ?? date('Y-m-d');
        
        $report = $reportService->fundSummary($charityId, $asOfDate);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/fund_summary.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'report' => $report,
                'as_of_date' => $asOfDate,
            ]
        ));
    }

    public function categoryAnalysis(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $reportService = $this->get(ReportService::class);
        
        $dateFrom = $params['date_from'] ?? $this->getFinancialYearStart($charity);
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        $type = $params['type'] ?? 'receipt';
        
        $report = $reportService->categoryAnalysis($charityId, $dateFrom, $dateTo, $type);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/category_analysis.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'report' => $report,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'type' => $type,
            ]
        ));
    }

    public function giftAid(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $giftAidService = $this->get(GiftAidService::class);
        
        // Default to tax year (April 6 - April 5)
        $year = $params['year'] ?? date('Y');
        $taxYearStart = ($year - 1) . '-04-06';
        if (date('m-d') < '04-06') {
            $taxYearStart = ($year - 2) . '-04-06';
        }
        
        $dateFrom = $params['date_from'] ?? $taxYearStart;
        $dateTo = $params['date_to'] ?? date('Y-m-d');
        
        $summary = $giftAidService->getSummary($charityId, $dateFrom, $dateTo);
        $claims = $giftAidService->getClaims($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/giftaid.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'summary' => $summary,
                'claims' => $claims,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        ));
    }

    /**
     * Get financial year start date
     */
    private function getFinancialYearStart(array $charity): string
    {
        $endMonth = (int) ($charity['year_end_month'] ?? 3);
        $endDay = (int) ($charity['year_end_day'] ?? 31);
        $yearEnd = sprintf('%02d-%02d', $endMonth, $endDay);
        
        // Start is day after year end
        $startDate = new \DateTime(date('Y') . '-' . $yearEnd);
        $startDate->modify('+1 day');
        
        // If we're before the year end, use previous year's start
        $now = new \DateTime();
        $thisYearEnd = new \DateTime(date('Y') . '-' . $yearEnd);
        
        if ($now <= $thisYearEnd) {
            $startDate->modify('-1 year');
        }
        
        return $startDate->format('Y-m-d');
    }
}
