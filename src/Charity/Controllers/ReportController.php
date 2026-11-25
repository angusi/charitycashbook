<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\FinancialYear;
use CharityCashbook\Charity\ModuleService;
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
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/reports/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            []
        ));
    }

    public function receiptsPayments(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);

        $report = $this->get(ReportService::class)->receiptsAndPayments($charityId, $dateFrom, $dateTo);
        if (!$this->cashBooksEnabled($request)) {
            $report['cash'] = null;
        }

        return $this->renderReport($request, $response, 'charity/reports/receipts_payments.html.twig', [
            'report' => $report,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    public function receiptsPaymentsDetailed(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);

        $report = $this->get(ReportService::class)->detailedReceiptsAndPayments($charityId, $dateFrom, $dateTo);
        if (!$this->cashBooksEnabled($request)) {
            $report['cash'] = null;
        }

        return $this->renderReport($request, $response, 'charity/reports/receipts_payments_detailed.html.twig', [
            'report' => $report,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    public function fundBreakdown(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);

        return $this->renderReport($request, $response, 'charity/reports/fund_breakdown.html.twig', [
            'report' => $this->get(ReportService::class)->fundByFundBreakdown($charityId, $dateFrom, $dateTo),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    public function fundOperationalBreakdown(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);

        return $this->renderReport($request, $response, 'charity/reports/fund_operational_breakdown.html.twig', [
            'report' => $this->get(ReportService::class)->fundByFundOperationalBreakdown($charityId, $dateFrom, $dateTo),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    public function transactions(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);
        $filters = $this->transactionFilters($request, $dateFrom, $dateTo);

        $transactionService = $this->get(TransactionService::class);
        $transactions = $transactionService->getTransactions($charityId, $filters);
        // Same filters and rules as the list: transfers are separate, and with a fund
        // filter only that fund's part of each transaction is counted
        $totals = $transactionService->getTotals($charityId, $filters);

        return $this->renderReport($request, $response, 'charity/reports/transactions.html.twig', [
            'transactions' => $transactions,
            'funds' => $this->get(FundService::class)->getAll($charityId, false),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'fund_id' => $filters['fund_id'],
            'type' => $filters['type'],
            'total_receipts' => $totals['receipts'],
            'total_payments' => $totals['payments'],
            'total_transfers' => $totals['transfers'],
        ]);
    }

    /**
     * Download the transactions report as CSV
     */
    public function exportTransactions(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);
        $filters = $this->transactionFilters($request, $dateFrom, $dateTo);

        $transactions = $this->get(TransactionService::class)->getTransactions($charityId, $filters);
        $csv = $this->get(ReportService::class)->exportTransactionsCsv($transactions);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="transactions-%s-to-%s.csv"', $dateFrom, $dateTo))
            ->withHeader('Cache-Control', 'no-store');
    }

    public function fundSummary(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $params = $this->getQueryParams($request);

        // Cumulative mode (no from date) or period mode (from + to)
        $dateFrom = $this->validDate($params['date_from'] ?? null);
        $dateTo = $this->validDate($params['date_to'] ?? $params['as_of_date'] ?? null) ?? date('Y-m-d');
        if ($dateFrom !== null && $dateFrom > $dateTo) {
            $this->flash('error', 'The start date must be on or before the end date.');
            $dateFrom = null;
        }

        return $this->renderReport($request, $response, 'charity/reports/fund_summary.html.twig', [
            'report' => $this->get(ReportService::class)->fundSummary($charityId, $dateTo, $dateFrom),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    public function categoryAnalysis(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        [$dateFrom, $dateTo] = $this->getPeriod($request);
        $type = $this->getQueryParams($request)['type'] ?? 'receipt';
        if (!in_array($type, ['receipt', 'payment'], true)) {
            $type = 'receipt';
        }

        return $this->renderReport($request, $response, 'charity/reports/category_analysis.html.twig', [
            'report' => $this->get(ReportService::class)->categoryAnalysis($charityId, $dateFrom, $dateTo, $type),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'type' => $type,
        ]);
    }

    public function giftAid(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $params = $this->getQueryParams($request);

        // Default to the current UK tax year (6 April - 5 April) up to today
        [$taxYearStart] = FinancialYear::taxYearBounds(date('Y-m-d'));
        [$dateFrom, $dateTo] = $this->resolvePeriod($params, $taxYearStart, date('Y-m-d'));

        $giftAidService = $this->get(GiftAidService::class);

        return $this->renderReport($request, $response, 'charity/reports/giftaid.html.twig', [
            'summary' => $giftAidService->getSummary($charityId, $dateFrom, $dateTo),
            'claims' => $giftAidService->getClaims($charityId),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    private function renderReport(Request $request, Response $response, string $template, array $data): Response
    {
        $view = Twig::fromRequest($request);
        return $view->render($response, $template, array_merge($this->getCommonTemplateData($request), $data));
    }

    /**
     * Report period from the query string, defaulting to the current financial year to date
     *
     * @return array{0: string, 1: string}
     */
    private function getPeriod(Request $request): array
    {
        $charity = $request->getAttribute('charity');

        return $this->resolvePeriod(
            $this->getQueryParams($request),
            FinancialYear::currentStart($charity),
            date('Y-m-d')
        );
    }

    /**
     * Validate date_from/date_to, falling back to defaults (with a message) when invalid
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(array $params, string $defaultFrom, string $defaultTo): array
    {
        $from = $params['date_from'] ?? '';
        $to = $params['date_to'] ?? '';

        $dateFrom = $from === '' ? $defaultFrom : $this->validDate($from);
        $dateTo = $to === '' ? $defaultTo : $this->validDate($to);

        if ($dateFrom === null || $dateTo === null) {
            $this->flash('error', 'Please enter valid dates. Showing the default period instead.');
            return [$defaultFrom, $defaultTo];
        }

        if ($dateFrom > $dateTo) {
            $this->flash('error', 'The start date must be on or before the end date. Showing the default period instead.');
            return [$defaultFrom, $defaultTo];
        }

        return [$dateFrom, $dateTo];
    }

    private function validDate(mixed $date): ?string
    {
        return FinancialYear::isValidDate($date) ? $date : null;
    }

    private function transactionFilters(Request $request, string $dateFrom, string $dateTo): array
    {
        $params = $this->getQueryParams($request);
        $type = $params['type'] ?? null;

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'fund_id' => !empty($params['fund_id']) ? (int) $params['fund_id'] : null,
            'type' => in_array($type, ['receipt', 'payment', 'transfer'], true) ? $type : null,
        ];
    }

    private function cashBooksEnabled(Request $request): bool
    {
        return $this->get(ModuleService::class)->isModuleEnabled((int) $request->getAttribute('charity_id'), 'cashbooks');
    }
}
