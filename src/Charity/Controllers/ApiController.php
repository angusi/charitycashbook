<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * AJAX API controller for charity workspace
 */
class ApiController extends BaseController
{
    /**
     * Search donors (AJAX)
     */
    public function searchDonors(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        $donorService = $this->get(DonorService::class);
        $auditService = $this->get(AuditService::class);
        
        $query = $data['query'] ?? '';
        $limit = min(20, (int) ($data['limit'] ?? 10));
        
        $donors = $donorService->search($charityId, $query, $limit);
        
        // Log donor search
        $auditService->logDonorAccess($charityId, $userId, null, 'search', ['query' => $query]);
        
        // Return minimal data for dropdown
        $results = array_map(function ($d) {
            return [
                'id' => $d['id'],
                'name' => $d['display_name'],
                'has_declaration' => $d['has_active_declaration'],
            ];
        }, $donors);
        
        return $this->json($response, ['donors' => $results]);
    }

    /**
     * Validate transaction (AJAX)
     */
    public function validateTransaction(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $data = $this->getPostData($request);
        
        $transactionService = $this->get(TransactionService::class);
        
        $errors = [];
        
        // Validate required fields
        if (empty($data['transaction_date'])) {
            $errors['transaction_date'] = 'Date is required';
        } elseif (!$this->isValidDate($data['transaction_date'])) {
            $errors['transaction_date'] = 'Invalid date format';
        }
        
        if (empty($data['amount']) || (float) $data['amount'] <= 0) {
            $errors['amount'] = 'Amount must be positive';
        }
        
        if (empty($data['description'])) {
            $errors['description'] = 'Description is required';
        }
        
        if (empty($data['fund_id'])) {
            $errors['fund_id'] = 'Fund is required';
        }
        
        if (empty($data['category_id'])) {
            $errors['category_id'] = 'Category is required';
        }
        
        // Check for duplicate
        if (empty($errors)) {
            $duplicate = $transactionService->findDuplicate($charityId, [
                'transaction_date' => $data['transaction_date'],
                'amount' => (float) $data['amount'],
                'description' => $data['description'],
            ]);
            
            if ($duplicate) {
                $errors['_warning'] = 'Similar transaction exists: ' . $duplicate['description'] . 
                                       ' on ' . $duplicate['transaction_date'];
            }
        }
        
        return $this->json($response, [
            'valid' => empty(array_filter($errors, fn($k) => $k !== '_warning', ARRAY_FILTER_USE_KEY)),
            'errors' => $errors,
        ]);
    }

    /**
     * Get categories by type (AJAX)
     */
    public function getCategories(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $type = $args['type'];
        
        if (!in_array($type, ['receipt', 'payment'], true)) {
            return $this->json($response, ['error' => 'Invalid type'], 400);
        }
        
        $categoryService = $this->get(CategoryService::class);
        $categories = $categoryService->getOperationalCategories($charityId, $type);
        
        return $this->json($response, ['categories' => $categories]);
    }

    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

}
