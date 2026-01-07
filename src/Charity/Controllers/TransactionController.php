<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\CashBookService;
use CharityCashbook\Charity\ModuleService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Transaction management controller
 */
class TransactionController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $transactionService = $this->get(TransactionService::class);
        $fundService = $this->get(FundService::class);
        $categoryService = $this->get(CategoryService::class);
        
        $filters = [
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'fund_id' => !empty($params['fund_id']) ? (int) $params['fund_id'] : null,
            'type' => $params['type'] ?? null,
            'search' => $params['search'] ?? null,
        ];
        
        $transactions = $transactionService->getTransactions($charityId, $filters);
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
        return $view->render($response, 'charity/transactions/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transactions' => $transactions,
                'funds' => $funds,
                'filters' => $filters,
                'total_receipts' => $totalReceipts,
                'total_payments' => $totalPayments,
            ]
        ));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $transactionId = (int) $args['id'];
        
        $transactionService = $this->get(TransactionService::class);
        $transaction = $transactionService->get($transactionId, $charityId);
        
        if (!$transaction) {
            $this->flash('error', 'Transaction not found.');
            return $this->redirect($response, "/charity/$charityId/transactions");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/transactions/show.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transaction' => $transaction,
                'csrf_token' => $_SESSION['csrf_token'] ?? '',
            ]
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $fundService = $this->get(FundService::class);
        $categoryService = $this->get(CategoryService::class);
        $donorService = $this->get(DonorService::class);
        $moduleService = $this->get(ModuleService::class);
        
        $type = $params['type'] ?? 'receipt';
        
        $funds = $fundService->getAll($charityId);
        $categories = $categoryService->getOperationalCategories($charityId, $type);
        $donors = ($type === 'receipt') ? $donorService->getAll($charityId) : [];
        
        // Get cash books if module is enabled
        $cashBooks = [];
        if ($moduleService->isModuleEnabled($charityId, 'cashbooks')) {
            $cashBookService = $this->get(CashBookService::class);
            $cashBooks = $cashBookService->getCashBooks($charityId);
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/transactions/create.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'type' => $type,
                'funds' => $funds,
                'categories' => $categories,
                'donors' => $donors,
                'cash_books' => $cashBooks,
            ]
        ));
    }

    public function store(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $transactionService = $this->get(TransactionService::class);
            $id = $transactionService->create($charityId, $userId, [
                'transaction_date' => $data['transaction_date'],
                'amount' => (float) $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => (int) ($data['fund_id'] ?? 0),
                'fund_entries' => $data['fund_entries'] ?? null,
                'operational_category_id' => (int) ($data['operational_category_id'] ?? $data['category_id'] ?? 0),
                'donor_id' => !empty($data['donor_id']) ? (int) $data['donor_id'] : null,
                'has_oneoff_declaration' => !empty($data['has_oneoff_declaration']),
                'is_gasds' => !empty($data['is_gasds']),
                'cash_book_id' => !empty($data['cash_book_id']) ? (int) $data['cash_book_id'] : null,
                'cashbook_entries' => $data['cashbook_entries'] ?? null,
            ]);
            
            $this->flash('success', 'Transaction created successfully.');
            return $this->redirect($response, "/charity/$charityId/transactions/$id");
            
        } catch (ValidationException $e) {
            $this->flash('error', 'Please correct the errors below: ' . implode(', ', $e->getErrors()));
            // In a real app, we'd pass errors back to the form
            return $this->redirect($response, "/charity/$charityId/transactions/create");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $transactionId = (int) $args['id'];
        
        $transactionService = $this->get(TransactionService::class);
        $fundService = $this->get(FundService::class);
        $categoryService = $this->get(CategoryService::class);
        $donorService = $this->get(DonorService::class);
        
        $transaction = $transactionService->get($transactionId, $charityId);
        
        if (!$transaction) {
            $this->flash('error', 'Transaction not found.');
            return $this->redirect($response, "/charity/$charityId/transactions");
        }
        
        if ($transaction['giftaid_status'] === 'claimed') {
            $this->flash('error', 'Cannot edit a claimed transaction.');
            return $this->redirect($response, "/charity/$charityId/transactions/$transactionId");
        }
        
        $funds = $fundService->getAll($charityId);
        $categories = $categoryService->getOperationalCategories($charityId, $transaction['transaction_type']);
        $donors = ($transaction['transaction_type'] === 'receipt') ? $donorService->getAll($charityId) : [];
        
        // Get cash books if module is enabled
        $cash_books = [];
        $moduleService = $this->get(ModuleService::class);
        if ($moduleService->isModuleEnabled($charityId, 'cashbooks')) {
            $cashBookService = $this->get(CashBookService::class);
            $cash_books = $cashBookService->getCashBooks($charityId);
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/transactions/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transaction' => $transaction,
                'funds' => $funds,
                'categories' => $categories,
                'donors' => $donors,
                'cash_books' => $cash_books,
            ]
        ));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $transactionId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $transactionService = $this->get(TransactionService::class);
            $transactionService->update($transactionId, $charityId, $userId, [
                'transaction_date' => $data['transaction_date'],
                'amount' => (float) $data['amount'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'fund_id' => (int) ($data['fund_id'] ?? 0),
                'fund_entries' => $data['fund_entries'] ?? null,
                'operational_category_id' => (int) ($data['operational_category_id'] ?? $data['category_id'] ?? 0),
                'donor_id' => !empty($data['donor_id']) ? (int) $data['donor_id'] : null,
                'has_oneoff_declaration' => !empty($data['has_oneoff_declaration']),
                'cash_book_id' => !empty($data['cash_book_id']) ? (int) $data['cash_book_id'] : null,
                'cashbook_entries' => $data['cashbook_entries'] ?? null,
            ]);
            
            $this->flash('success', 'Transaction updated successfully.');
            return $this->redirect($response, "/charity/$charityId/transactions/$transactionId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/transactions/$transactionId/edit");
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $transactionId = (int) $args['id'];
        
        try {
            $transactionService = $this->get(TransactionService::class);
            $transactionService->delete($transactionId, $charityId, $userId);
            
            $this->flash('success', 'Transaction deleted.');
            return $this->redirect($response, "/charity/$charityId/transactions");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/transactions/$transactionId");
        }
    }
}
