<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\BooksLock;
use CharityCashbook\Charity\FinancialYear;
use CharityCashbook\Core\Money;
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

        // Active funds and inactive funds that still hold money are always shown,
        // so the totals are the charity's real totals
        $funds = $fundService->getAllBalances($charityId);
        if ($showInactive) {
            $listed = array_column($funds, 'id');
            foreach ($fundService->getAll($charityId, false) as $fund) {
                if (!in_array($fund['id'], $listed, true)) {
                    $funds[] = array_merge($fund, FundService::emptyBalance());
                }
            }
        }
        
        // Calculate totals
        $totalBalance = Money::sumPence(array_column($funds, 'balance')) / 100;
        $totalReceipts = Money::sumPence(array_column($funds, 'receipts')) / 100;
        $totalPayments = Money::sumPence(array_column($funds, 'payments')) / 100;
        
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
                'fund_types' => FundService::FUND_TYPES,
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
            [$openingBalance, $openingDate] = $this->validateOpeningBalance($data);
            
            $fundService = $this->get(FundService::class);
            $auditService = $this->get(AuditService::class);

            $lockedUntil = $request->getAttribute('charity')['books_locked_until'] ?? null;
            if ($openingDate !== null && BooksLock::isLocked($lockedUntil, $openingDate) && Money::toPence($openingBalance) !== 0) {
                BooksLock::assertUnlocked($lockedUntil, $openingDate);
            }
            
            $id = $fundService->create($charityId, [
                'name' => trim($data['name']),
                'fund_type' => $data['fund_type'],
                'opening_balance' => $openingBalance,
                'opening_balance_date' => $openingDate,
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
                'fund_types' => FundService::FUND_TYPES,
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

            if (array_key_exists('opening_balance', $data) || array_key_exists('opening_balance_date', $data)) {
                [$openingBalance, $openingDate] = $this->validateOpeningBalance([
                    'opening_balance' => $data['opening_balance'] ?? $fund['opening_balance'],
                    'opening_balance_date' => $data['opening_balance_date'] ?? $fund['opening_balance_date'],
                ]);
                $updateData['opening_balance'] = $openingBalance;
                $updateData['opening_balance_date'] = $openingDate;
            }

            // Year-end close: anything that would restate a closed year is locked
            $lockedUntil = $request->getAttribute('charity')['books_locked_until'] ?? null;
            if ($lockedUntil) {
                $openingChanged = array_key_exists('opening_balance', $updateData) && (
                    !Money::equals($updateData['opening_balance'], $fund['opening_balance'])
                    || ($updateData['opening_balance_date'] ?? null) != ($fund['opening_balance_date'] ?? null)
                );
                $typeChanged = $updateData['fund_type'] !== $fund['fund_type'];
                $affectsClosedYear = BooksLock::isLocked($lockedUntil, $fund['opening_balance_date'] ?? null)
                    || BooksLock::isLocked($lockedUntil, $updateData['opening_balance_date'] ?? null)
                    || $fundService->hasTransactionsOnOrBefore($fundId, $charityId, $lockedUntil);

                if (($openingChanged || $typeChanged) && $affectsClosedYear) {
                    throw new ValidationException([
                        "This fund has figures in a closed financial year (up to $lockedUntil), so its opening balance and type cannot be changed",
                    ]);
                }
            }

            // A fund that still holds money cannot be deactivated (it would drop out of the totals)
            if ($fund['is_active'] && !$updateData['is_active']) {
                $balance = $fundService->getBalance($fundId, $charityId);
                if (Money::toPence($balance['balance']) !== 0) {
                    throw new ValidationException([
                        'This fund has a balance of £' . Money::normalise($balance['balance']) . '. Transfer it to another fund before deactivating it.',
                    ]);
                }
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
     * Validate an opening balance and its date
     *
     * @return array{0: string, 1: ?string} [amount, date]
     */
    private function validateOpeningBalance(array $data): array
    {
        $amount = $data['opening_balance'] ?? '';
        $date = $data['opening_balance_date'] ?? '';

        if ($amount === '' || $amount === null) {
            $amount = '0';
        }
        if (!Money::isValid(is_string($amount) ? trim($amount) : $amount)) {
            throw new ValidationException(['Opening balance must be an amount in pounds and pence']);
        }
        if ($date === '' || $date === null) {
            $date = null;
        } elseif (!FinancialYear::isValidDate($date)) {
            throw new ValidationException(['Opening balance date must be a valid date']);
        }
        return [Money::normalise($amount), $date];
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
