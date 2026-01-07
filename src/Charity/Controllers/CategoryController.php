<?php

declare(strict_types=1);

namespace CharityCashbook\Charity\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Category management controller
 */
class CategoryController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        
        $categoryService = $this->get(CategoryService::class);
        
        $reportingCategories = $categoryService->getReportingCategories($charityId);
        
        // Get operational categories grouped by reporting category
        $operationalByReporting = [];
        foreach ($reportingCategories as $rc) {
            $operationalByReporting[$rc['id']] = $categoryService->getOperationalCategoriesByReporting(
                $charityId, 
                $rc['id']
            );
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/categories/index.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'reporting_categories' => $reportingCategories,
                'operational_by_reporting' => $operationalByReporting,
            ]
        ));
    }

    public function createReporting(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $type = $params['type'] ?? 'receipt';
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/categories/create_reporting.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'type' => $type,
            ]
        ));
    }

    public function storeReporting(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'category_type' => 'required|in:receipt,payment',
            ]);
            
            $categoryService = $this->get(CategoryService::class);
            $auditService = $this->get(AuditService::class);
            
            $id = $categoryService->createReportingCategory($charityId, [
                'name' => trim($data['name']),
                'category_type' => $data['category_type'],
                'description' => $data['description'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : 0,
            ]);
            
            $auditService->log($charityId, $userId, 'category_created', 'reporting_category', $id, [
                'name' => $data['name'],
                'type' => $data['category_type'],
            ]);
            
            $this->flash('success', 'Reporting category created.');
            return $this->redirect($response, "/charity/$charityId/categories");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/categories/reporting/create");
        }
    }

    public function createOperational(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $params = $this->getQueryParams($request);
        
        $categoryService = $this->get(CategoryService::class);

        // Get all reporting categories (both receipt and payment) so user can choose
        $reportingCategories = $categoryService->getReportingCategories($charityId, null);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/categories/create_operational.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'reporting_categories' => $reportingCategories,
            ]
        ));
    }

    public function storeOperational(Request $request, Response $response): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'reporting_category_id' => 'required|numeric',
            ]);
            
            $categoryService = $this->get(CategoryService::class);
            $auditService = $this->get(AuditService::class);
            
            $id = $categoryService->createOperationalCategory($charityId, [
                'name' => trim($data['name']),
                'reporting_category_id' => (int) $data['reporting_category_id'],
                'description' => $data['description'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : 0,
            ]);
            
            $auditService->log($charityId, $userId, 'category_created', 'operational_category', $id, [
                'name' => $data['name'],
                'reporting_category_id' => $data['reporting_category_id'],
            ]);
            
            $this->flash('success', 'Operational category created.');
            return $this->redirect($response, "/charity/$charityId/categories");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/categories/operational/create");
        }
    }

    public function editReporting(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $categoryId = (int) $args['id'];
        
        $categoryService = $this->get(CategoryService::class);
        $category = $categoryService->getReportingCategory($categoryId, $charityId);
        
        if (!$category) {
            $this->flash('error', 'Category not found.');
            return $this->redirect($response, "/charity/$charityId/categories");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/categories/edit_reporting.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'category' => $category,
            ]
        ));
    }

    public function updateReporting(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $categoryId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
            ]);
            
            $categoryService = $this->get(CategoryService::class);
            $auditService = $this->get(AuditService::class);
            
            $category = $categoryService->getReportingCategory($categoryId, $charityId);
            if (!$category) {
                $this->flash('error', 'Category not found.');
                return $this->redirect($response, "/charity/$charityId/categories");
            }
            
            $categoryService->updateReportingCategory($categoryId, $charityId, [
                'name' => trim($data['name']),
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : $category['display_order'],
            ]);
            
            $auditService->log($charityId, $userId, 'category_updated', 'reporting_category', $categoryId, [
                'name' => $data['name'],
            ]);
            
            $this->flash('success', 'Reporting category updated.');
            return $this->redirect($response, "/charity/$charityId/categories");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/categories/reporting/$categoryId/edit");
        }
    }

    public function hideReporting(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $categoryId = (int) $args['id'];
        
        $categoryService = $this->get(CategoryService::class);
        $auditService = $this->get(AuditService::class);
        
        $category = $categoryService->getReportingCategory($categoryId, $charityId);
        if (!$category) {
            $this->flash('error', 'Category not found.');
            return $this->redirect($response, "/charity/$charityId/categories");
        }
        
        $categoryService->deactivateReportingCategory($categoryId, $charityId);
        
        $auditService->log($charityId, $userId, 'category_hidden', 'reporting_category', $categoryId, [
            'name' => $category['name'],
        ]);
        
        $this->flash('success', 'Category hidden. Any operational categories under it have also been hidden.');
        return $this->redirect($response, "/charity/$charityId/categories");
    }

    public function editOperational(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $charity = $request->getAttribute('charity');
        $userRole = $request->getAttribute('user_role');
        $categoryId = (int) $args['id'];
        
        $categoryService = $this->get(CategoryService::class);
        $category = $categoryService->getOperationalCategory($categoryId, $charityId);
        
        if (!$category) {
            $this->flash('error', 'Category not found.');
            return $this->redirect($response, "/charity/$charityId/categories");
        }
        
        $reportingCategories = $categoryService->getReportingCategories($charityId, $category['category_type']);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'charity/categories/edit_operational.html.twig', array_merge(
            $this->getCommonTemplateData($request),
            [
                'category' => $category,
                'reporting_categories' => $reportingCategories,
            ]
        ));
    }

    public function updateOperational(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $categoryId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'reporting_category_id' => 'required|numeric',
            ]);
            
            $categoryService = $this->get(CategoryService::class);
            $auditService = $this->get(AuditService::class);
            
            $category = $categoryService->getOperationalCategory($categoryId, $charityId);
            if (!$category) {
                $this->flash('error', 'Category not found.');
                return $this->redirect($response, "/charity/$charityId/categories");
            }
            
            $categoryService->updateOperationalCategory($categoryId, $charityId, [
                'name' => trim($data['name']),
                'reporting_category_id' => (int) $data['reporting_category_id'],
                'giftaid_eligible_default' => isset($data['giftaid_eligible_default']),
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : $category['display_order'],
            ]);
            
            $auditService->log($charityId, $userId, 'category_updated', 'operational_category', $categoryId, [
                'name' => $data['name'],
            ]);
            
            $this->flash('success', 'Operational category updated.');
            return $this->redirect($response, "/charity/$charityId/categories");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/charity/$charityId/categories/operational/$categoryId/edit");
        }
    }

    public function hideOperational(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $userId = $request->getAttribute('user_id');
        $categoryId = (int) $args['id'];
        
        $categoryService = $this->get(CategoryService::class);
        $auditService = $this->get(AuditService::class);
        
        $category = $categoryService->getOperationalCategory($categoryId, $charityId);
        if (!$category) {
            $this->flash('error', 'Category not found.');
            return $this->redirect($response, "/charity/$charityId/categories");
        }
        
        $categoryService->deactivateOperationalCategory($categoryId, $charityId);
        
        $auditService->log($charityId, $userId, 'category_hidden', 'operational_category', $categoryId, [
            'name' => $category['name'],
        ]);
        
        $this->flash('success', 'Category hidden.');
        return $this->redirect($response, "/charity/$charityId/categories");
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
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
                }
                
                if ($rule === 'numeric' && !is_null($value) && $value !== '' && !is_numeric($value)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a number';
                }
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters";
                    }
                }
                
                if (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($value, $allowed, true)) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be one of: ' . implode(', ', $allowed);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
