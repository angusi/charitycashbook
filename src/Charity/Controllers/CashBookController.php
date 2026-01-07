<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\CashBookService;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\ModuleService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Cash books controller
 */
class CashBookController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');

        $cashBookService = $this->get(CashBookService::class);
        $summary = $cashBookService->getSummary($charity['id']);

        // If no cashbooks exist, redirect to setup wizard
        if (empty($summary['cash_books'])) {
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/setup");
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'summary' => $summary,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    /**
     * Setup wizard - shown when cashbooks module is enabled but no cashbooks exist
     */
    public function setup(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');

        $cashBookService = $this->get(CashBookService::class);
        $fundService = $this->get(FundService::class);
        $transactionService = $this->get(TransactionService::class);
        $moduleService = $this->get(ModuleService::class);

        // Check if this is a re-enable (previous activity exists)
        $moduleData = $moduleService->getModulesForCharity($charity['id'])['cashbooks'] ?? null;
        $isReenable = $moduleData && $moduleData['disabled_at'] !== null;
        
        // Get existing cashbooks (may exist if re-enabling)
        $existingCashbooks = $cashBookService->getCashBooks($charity['id'], false);
        
        // Get funds for mapping
        $funds = $fundService->getAll($charity['id']);
        
        // Count transactions that would need assignment
        $transactionCount = $transactionService->countTransactions($charity['id']);
        $unassignedCount = $cashBookService->countUnassignedTransactions($charity['id']);

        // Detect gap if re-enabling
        $gapInfo = null;
        if ($isReenable && !empty($existingCashbooks)) {
            // Find last activity date (last cashbook entry or reconciliation)
            $lastActivity = $cashBookService->getLastActivityDate($charity['id']);
            if ($lastActivity) {
                $gapInfo = [
                    'last_activity_date' => $lastActivity,
                    'unassigned_since_then' => $unassignedCount,
                ];
            }
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/setup.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'is_reenable' => $isReenable,
                'existing_cashbooks' => $existingCashbooks,
                'funds' => $funds,
                'transaction_count' => $transactionCount,
                'unassigned_count' => $unassignedCount,
                'gap_info' => $gapInfo,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    /**
     * Complete setup wizard
     */
    public function completeSetup(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');
        $session = $request->getAttribute('session');
        $data = $this->getPostData($request);

        $cashBookService = $this->get(CashBookService::class);
        $auditService = $this->get(AuditService::class);

        $action = $data['action'] ?? 'start_fresh';

        try {
            // Handle cashbook creation if provided
            if (!empty($data['cashbooks'])) {
                foreach ($data['cashbooks'] as $cbData) {
                    if (empty($cbData['name'])) {
                        continue;
                    }
                    
                    $id = $cashBookService->create($charity['id'], [
                        'name' => $cbData['name'],
                        'book_type' => $cbData['book_type'] ?? 'bank',
                        'opening_balance' => $cbData['opening_balance'] ?? 0,
                        'opening_balance_date' => $cbData['opening_balance_date'] ?? date('Y-m-d'),
                    ]);

                    $auditService->log(
                        $charity['id'],
                        $session->getUserId(),
                        'cashbook_created',
                        'cash_book',
                        $id,
                        ['name' => $cbData['name'], 'via' => 'setup_wizard']
                    );
                }
            }

            // Handle fund→cashbook mapping for historical assignment
            if ($action === 'assign_historical' && !empty($data['fund_mapping'])) {
                $assignedCount = 0;
                foreach ($data['fund_mapping'] as $fundId => $cashBookId) {
                    if ($cashBookId) {
                        $count = $cashBookService->assignTransactionsByFund(
                            $charity['id'],
                            (int) $fundId,
                            (int) $cashBookId
                        );
                        $assignedCount += $count;
                    }
                }

                $auditService->log(
                    $charity['id'],
                    $session->getUserId(),
                    'cashbook_setup_completed',
                    'charity',
                    $charity['id'],
                    ['action' => 'assign_historical', 'assigned_count' => $assignedCount]
                );

                $this->flash('success', "Cash books set up successfully. {$assignedCount} transaction(s) assigned.");
            } else {
                // Start fresh - cashbooks created with opening balances, historical transactions ignored
                $auditService->log(
                    $charity['id'],
                    $session->getUserId(),
                    'cashbook_setup_completed',
                    'charity',
                    $charity['id'],
                    ['action' => 'start_fresh']
                );

                $this->flash('success', 'Cash books set up successfully. You can start tracking from today.');
            }

            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to complete setup: ' . $e->getMessage());
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/setup");
        }
    }

    public function create(Request $request, Response $response): Response
    {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/create.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    public function store(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');
        $session = $request->getAttribute('session');
        $data = $this->getPostData($request);

        $cashBookService = $this->get(CashBookService::class);
        $auditService = $this->get(AuditService::class);

        try {
            $id = $cashBookService->create($charity['id'], [
                'name' => $data['name'],
                'book_type' => $data['book_type'] ?? 'bank',
                'description' => $data['description'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'sort_code' => $data['sort_code'] ?? null,
                'opening_balance' => $data['opening_balance'] ?? 0,
                'opening_balance_date' => $data['opening_balance_date'],
            ]);

            $auditService->log(
                $charity['id'],
                $session->getUserId(),
                'cashbook_created',
                'cash_book',
                $id,
                ['name' => $data['name'], 'book_type' => $data['book_type'] ?? 'bank']
            );

            $this->flash('success', 'Cash book created successfully.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/{$id}");
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to create cash book: ' . $e->getMessage());
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/create");
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $charity = $request->getAttribute('charity');
        $cashBookId = (int) $args['id'];

        $cashBookService = $this->get(CashBookService::class);
        $cashBook = $cashBookService->getCashBook($cashBookId);

        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Cash book not found.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        }

        $balance = $cashBookService->calculateBalance($cashBookId);
        $transactions = $cashBookService->getTransactions($cashBookId, ['limit' => 50]);
        $reconciliations = $cashBookService->getReconciliations($cashBookId);
        $lastReconciliation = $reconciliations[0] ?? null;

        // Count unreconciled
        $unreconciledCount = 0;
        foreach ($transactions as $t) {
            if (!$t['is_reconciled']) {
                $unreconciledCount++;
            }
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/show.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'cash_book' => $cashBook,
                'balance' => $balance,
                'transactions' => $transactions,
                'reconciliations' => $reconciliations,
                'last_reconciliation' => $lastReconciliation,
                'unreconciled_count' => $unreconciledCount,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $charity = $request->getAttribute('charity');
        $cashBookId = (int) $args['id'];

        $cashBookService = $this->get(CashBookService::class);
        $cashBook = $cashBookService->getCashBook($cashBookId);

        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Cash book not found.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'cash_book' => $cashBook,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charity = $request->getAttribute('charity');
        $session = $request->getAttribute('session');
        $cashBookId = (int) $args['id'];
        $data = $this->getPostData($request);

        $cashBookService = $this->get(CashBookService::class);
        $auditService = $this->get(AuditService::class);

        $cashBook = $cashBookService->getCashBook($cashBookId);
        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Cash book not found.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        }

        try {
            $cashBookService->update($cashBookId, [
                'name' => $data['name'],
                'book_type' => $data['book_type'],
                'description' => $data['description'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'sort_code' => $data['sort_code'] ?? null,
                'opening_balance' => $data['opening_balance'],
                'opening_balance_date' => $data['opening_balance_date'],
                'is_active' => isset($data['is_active']),
            ]);

            $auditService->log(
                $charity['id'],
                $session->getUserId(),
                'cashbook_updated',
                'cash_book',
                $cashBookId,
                ['name' => $data['name']]
            );

            $this->flash('success', 'Cash book updated successfully.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/{$cashBookId}");
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to update cash book: ' . $e->getMessage());
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/{$cashBookId}/edit");
        }
    }

    public function reconcile(Request $request, Response $response, array $args): Response
    {
        $charity = $request->getAttribute('charity');
        $cashBookId = (int) $args['id'];

        $cashBookService = $this->get(CashBookService::class);
        $cashBook = $cashBookService->getCashBook($cashBookId);

        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Cash book not found.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        }

        $balance = $cashBookService->calculateBalance($cashBookId);
        $transactions = $cashBookService->getTransactions($cashBookId, ['is_reconciled' => false]);
        $lastReconciliation = $cashBookService->getLastReconciliation($cashBookId);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/reconcile.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'cash_book' => $cashBook,
                'balance' => $balance,
                'transactions' => $transactions,
                'last_reconciliation' => $lastReconciliation,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    public function storeReconciliation(Request $request, Response $response, array $args): Response
    {
        $charity = $request->getAttribute('charity');
        $session = $request->getAttribute('session');
        $cashBookId = (int) $args['id'];
        $data = $this->getPostData($request);

        $cashBookService = $this->get(CashBookService::class);
        $auditService = $this->get(AuditService::class);

        $cashBook = $cashBookService->getCashBook($cashBookId);
        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Cash book not found.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks");
        }

        try {
            $reconciliationId = $cashBookService->reconcile($cashBookId, [
                'reconciled_date' => $data['reconciled_date'],
                'statement_balance' => $data['statement_balance'],
                'notes' => $data['notes'] ?? null,
            ], $session->getUserId());

            $auditService->log(
                $charity['id'],
                $session->getUserId(),
                'cashbook_reconciled',
                'cash_book',
                $cashBookId,
                [
                    'reconciliation_id' => $reconciliationId,
                    'date' => $data['reconciled_date'],
                    'balance' => $data['statement_balance'],
                ]
            );

            $this->flash('success', 'Reconciliation recorded successfully.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/{$cashBookId}");
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to record reconciliation: ' . $e->getMessage());
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/{$cashBookId}/reconcile");
        }
    }

    public function unlinked(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');

        $cashBookService = $this->get(CashBookService::class);
        $transactions = $cashBookService->getUnlinkedTransactions($charity['id']);
        $cashBooks = $cashBookService->getCashBooks($charity['id']);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/cashbooks/unlinked.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'transactions' => $transactions,
                'cash_books' => $cashBooks,
                'active_nav' => 'cashbooks',
            ]
        ));
    }

    public function assignTransactions(Request $request, Response $response): Response
    {
        $charity = $request->getAttribute('charity');
        $session = $request->getAttribute('session');
        $data = $this->getPostData($request);

        $cashBookService = $this->get(CashBookService::class);
        $auditService = $this->get(AuditService::class);

        $transactionIds = $data['transaction_ids'] ?? [];
        $cashBookId = (int) ($data['cash_book_id'] ?? 0);

        if (empty($transactionIds) || !$cashBookId) {
            $this->flash('error', 'Please select transactions and a cash book.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/unlinked");
        }

        // Verify cash book belongs to charity
        $cashBook = $cashBookService->getCashBook($cashBookId);
        if (!$cashBook || $cashBook['charity_id'] !== $charity['id']) {
            $this->flash('error', 'Invalid cash book.');
            return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/unlinked");
        }

        $count = $cashBookService->assignTransactions($transactionIds, $cashBookId);

        $auditService->log(
            $charity['id'],
            $session->getUserId(),
            'transactions_assigned_to_cashbook',
            'cash_book',
            $cashBookId,
            ['count' => $count, 'transaction_ids' => $transactionIds]
        );

        $this->flash('success', "Assigned {$count} transaction(s) to {$cashBook['name']}.");
        return $this->redirect($response, "/charity/{$charity['id']}/cashbooks/unlinked");
    }
}
