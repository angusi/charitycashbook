<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Fund management controller
 */
class FundController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $fundService = $this->get(FundService::class);
        
        $showInactive = !empty($params['show_inactive']);
        $funds = $fundService->getAllBalances($charityId);
        
        if (!$showInactive) {
            $funds = array_filter($funds, fn($f) => $f['is_active']);
        }
        
        // Calculate totals
        $totalBalance = array_sum(array_column($funds, 'balance'));
        $totalReceipts = array_sum(array_column($funds, 'receipts'));
        $totalPayments = array_sum(array_column($funds, 'payments'));
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/funds/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'funds' => $funds,
                'show_inactive' => $showInactive,
                'total_balance' => $totalBalance,
                'total_receipts' => $totalReceipts,
                'total_payments' => $totalPayments,
            ]
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/funds/create.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'fund_types' => ['unrestricted', 'restricted', 'endowment'],
            ]
        ));
    }

    public function store(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'fund_type' => 'required|in:unrestricted,restricted,endowment',
            ]);
            
            $fundService = $this->get(FundService::class);
            $auditService = $this->get(AuditService::class);
            
            $id = $fundService->create($charityId, [
                'name' => trim($data['name']),
                'fund_type' => $data['fund_type'],
                'opening_balance' => isset($data['opening_balance']) ? (float) $data['opening_balance'] : 0,
                'opening_balance_date' => $data['opening_balance_date'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : 0,
            ]);
            
            $auditService->log($charityId, $userId, 'fund_created', 'fund', $id, [
                'name' => $data['name'],
                'fund_type' => $data['fund_type'],
            ]);
            
            $this->flash('success', 'Fund created successfully.');
            return $this->redirect($response, "/charity/$charityId/funds");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/funds/create");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $fundId = (int) $args['id'];
        
        $fundService = $this->get(FundService::class);
        $fund = $fundService->get($fundId, $charityId);
        
        if (!$fund) {
            $this->flash('error', 'Fund not found.');
            return $this->redirect($response, "/charity/$charityId/funds");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/funds/edit.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'fund' => $fund,
                'fund_types' => ['unrestricted', 'restricted', 'endowment'],
            ]
        ));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $fundId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'fund_type' => 'required|in:unrestricted,restricted,endowment',
            ]);
            
            $fundService = $this->get(FundService::class);
            $auditService = $this->get(AuditService::class);
            
            $fund = $fundService->get($fundId, $charityId);
            if (!$fund) {
                $this->flash('error', 'Fund not found.');
                return $this->redirect($response, "/charity/$charityId/funds");
            }
            
            $updateData = [
                'name' => trim($data['name']),
                'fund_type' => $data['fund_type'],
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : 0,
                'is_active' => !empty($data['is_active']),
            ];
            
            // Only allow opening balance change if no transactions
            if (isset($data['opening_balance'])) {
                $updateData['opening_balance'] = (float) $data['opening_balance'];
            }
            if (isset($data['opening_balance_date'])) {
                $updateData['opening_balance_date'] = $data['opening_balance_date'];
            }
            
            $fundService->update($fundId, $charityId, $updateData);
            
            $auditService->log($charityId, $userId, 'fund_updated', 'fund', $fundId, [
                'changes' => $updateData,
            ]);
            
            $this->flash('success', 'Fund updated successfully.');
            return $this->redirect($response, "/charity/$charityId/funds");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/funds/$fundId/edit");
        }
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
                    $errors[] = ucfirst($field) . ' is required';
                }
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst($field) . " must be at most $max characters";
                    }
                }
                
                if (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($value, $allowed, true)) {
                        $errors[] = ucfirst($field) . ' must be one of: ' . implode(', ', $allowed);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
