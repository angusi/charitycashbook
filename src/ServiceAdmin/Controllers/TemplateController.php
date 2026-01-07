<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\ServiceAdmin\TemplateService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Category template management controller
 */
class TemplateController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $templateService = $this->get(TemplateService::class);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/templates/index.html.twig', [
            'user' => $this->getUserData($session),
            'templates' => $templateService->getAllTemplates(),
            'flash' => $this->getFlash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/templates/create.html.twig', [
            'user' => $this->getUserData($session),
            'flash' => $this->getFlash(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);

        try {
            $this->validate($data, [
                'name' => 'required|max:100',
            ]);

            $templateService = $this->get(TemplateService::class);
            $id = $templateService->createTemplate([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => !empty($data['is_active']),
            ]);

            $this->flash('success', 'Template created. Now add categories.');
            return $this->redirect($response, "/service-admin/templates/$id/edit");

        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/templates/create");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $templateId = (int) $args['id'];

        $templateService = $this->get(TemplateService::class);
        $template = $templateService->getTemplate($templateId);

        if (!$template) {
            $this->flash('error', 'Template not found.');
            return $this->redirect($response, '/service-admin/templates');
        }

        $items = $templateService->getTemplateItems($templateId);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/templates/edit.html.twig', [
            'user' => $this->getUserData($session),
            'template' => $template,
            'items' => $items,
            'flash' => $this->getFlash(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $data = $this->getPostData($request);

        try {
            $this->validate($data, [
                'name' => 'required|max:100',
            ]);

            $templateService = $this->get(TemplateService::class);
            $templateService->updateTemplate($templateId, [
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => !empty($data['is_active']),
            ]);

            $this->flash('success', 'Template updated.');
            return $this->redirect($response, "/service-admin/templates/$templateId/edit");

        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/templates/$templateId/edit");
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];

        $templateService = $this->get(TemplateService::class);
        $templateService->deleteTemplate($templateId);

        $this->flash('success', 'Template deleted.');
        return $this->redirect($response, '/service-admin/templates');
    }

    public function duplicate(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $data = $this->getPostData($request);

        $templateService = $this->get(TemplateService::class);
        $template = $templateService->getTemplate($templateId);

        if (!$template) {
            $this->flash('error', 'Template not found.');
            return $this->redirect($response, '/service-admin/templates');
        }

        $newName = $data['name'] ?? $template['name'] . ' (copy)';
        $newId = $templateService->duplicateTemplate($templateId, $newName);

        $this->flash('success', 'Template duplicated.');
        return $this->redirect($response, "/service-admin/templates/$newId/edit");
    }

    public function addItem(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $data = $this->getPostData($request);

        try {
            $this->validate($data, [
                'category_type' => 'required|in:receipt,payment',
                'reporting_name' => 'required|max:100',
            ]);

            $operationalNames = [];
            if (!empty($data['operational_names'])) {
                $operationalNames = array_filter(
                    array_map('trim', explode("\n", $data['operational_names']))
                );
            }

            $templateService = $this->get(TemplateService::class);
            $templateService->addTemplateItem($templateId, [
                'category_type' => $data['category_type'],
                'reporting_name' => trim($data['reporting_name']),
                'operational_names' => $operationalNames,
            ]);

            $this->flash('success', 'Category added.');

        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }

        return $this->redirect($response, "/service-admin/templates/$templateId/edit");
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $itemId = (int) $args['item_id'];
        $data = $this->getPostData($request);

        try {
            $this->validate($data, [
                'reporting_name' => 'required|max:100',
            ]);

            $operationalNames = [];
            if (!empty($data['operational_names'])) {
                $operationalNames = array_filter(
                    array_map('trim', explode("\n", $data['operational_names']))
                );
            }

            $templateService = $this->get(TemplateService::class);
            $templateService->updateTemplateItem($itemId, [
                'reporting_name' => trim($data['reporting_name']),
                'operational_names' => $operationalNames,
            ]);

            $this->flash('success', 'Category updated.');

        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
        }

        return $this->redirect($response, "/service-admin/templates/$templateId/edit");
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) $args['id'];
        $itemId = (int) $args['item_id'];

        $templateService = $this->get(TemplateService::class);
        $templateService->deleteTemplateItem($itemId);

        $this->flash('success', 'Category removed.');
        return $this->redirect($response, "/service-admin/templates/$templateId/edit");
    }

    private function getUserData($session): array
    {
        return [
            'display_name' => $session->getDisplayName(),
            'is_super_admin' => $session->isSuperAdmin(),
        ];
    }

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
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters";
                    }
                }
                
                if (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($value, $allowed)) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' has an invalid value';
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
