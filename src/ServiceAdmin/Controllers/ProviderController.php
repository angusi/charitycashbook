<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Auth\OAuthProviderService;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * OAuth provider management controller
 */
class ProviderController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $providerService = $this->get(OAuthProviderService::class);
        
        $providers = $providerService->getAllProviders();
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/providers/index.html.twig', [
            'user' => $this->getUserData($session),
            'providers' => $providers,
            'flash' => $this->getFlash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/providers/create.html.twig', [
            'user' => $this->getUserData($session),
            'provider_types' => ['google', 'microsoft', 'oidc'],
            'flash' => $this->getFlash(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'provider_type' => 'required|in:google,microsoft,oidc',
                'name' => 'required|max:100',
                'client_id' => 'required|max:500',
                'client_secret' => 'required',
            ]);
            
            // Additional validation for Microsoft
            if ($data['provider_type'] === 'microsoft') {
                if (empty($data['tenant_id'])) {
                    throw new ValidationException(['Microsoft providers require a tenant ID (use "common" for any Microsoft account)']);
                }
            }
            
            // Additional validation for OIDC
            if ($data['provider_type'] === 'oidc') {
                if (empty($data['authorization_endpoint']) || empty($data['token_endpoint'])) {
                    throw new ValidationException(['OIDC providers require authorization and token endpoints']);
                }
            }
            
            $providerService = $this->get(OAuthProviderService::class);
            
            $id = $providerService->createProvider([
                'provider_type' => $data['provider_type'],
                'name' => trim($data['name']),
                'client_id' => trim($data['client_id']),
                'client_secret' => $data['client_secret'],
                'tenant_id' => $data['tenant_id'] ?? null,
                'discovery_url' => $data['discovery_url'] ?? null,
                'authorization_endpoint' => $data['authorization_endpoint'] ?? null,
                'token_endpoint' => $data['token_endpoint'] ?? null,
                'userinfo_endpoint' => $data['userinfo_endpoint'] ?? null,
                'scopes' => $data['scopes'] ?? null,
                'is_enabled' => !empty($data['is_enabled']),
            ]);
            
            $this->flash('success', 'OAuth provider created. Callback URL: ' . rtrim($this->get('config')['app']['url'], '/') . '/oauth/callback/' . $id);
            return $this->redirect($response, "/service-admin/providers");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/providers/create");
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $providerId = (int) $args['id'];
        
        $providerService = $this->get(OAuthProviderService::class);
        $provider = $providerService->getProviderById($providerId);
        
        if (!$provider) {
            $this->flash('error', 'Provider not found.');
            return $this->redirect($response, "/service-admin/providers");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/providers/edit.html.twig', [
            'user' => $this->getUserData($session),
            'provider' => $provider,
            'flash' => $this->getFlash(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:100',
                'client_id' => 'required|max:500',
            ]);
            
            $providerService = $this->get(OAuthProviderService::class);
            $provider = $providerService->getProviderById($providerId);
            
            // Additional validation for Microsoft
            if ($provider['provider_type'] === 'microsoft' && empty($data['tenant_id'])) {
                throw new ValidationException(['Microsoft providers require a tenant ID (use "common" for any Microsoft account)']);
            }
            
            // Additional validation for OIDC
            if ($provider['provider_type'] === 'oidc') {
                if (empty($data['authorization_endpoint']) || empty($data['token_endpoint'])) {
                    throw new ValidationException(['OIDC providers require authorization and token endpoints']);
                }
            }
            
            $updateData = [
                'name' => trim($data['name']),
                'client_id' => trim($data['client_id']),
                'tenant_id' => $data['tenant_id'] ?? null,
                'authorization_endpoint' => $data['authorization_endpoint'] ?? null,
                'token_endpoint' => $data['token_endpoint'] ?? null,
                'userinfo_endpoint' => $data['userinfo_endpoint'] ?? null,
                'scopes' => $data['scopes'] ?? null,
            ];
            
            // Only update secret if provided
            if (!empty($data['client_secret'])) {
                $updateData['client_secret'] = $data['client_secret'];
            }
            
            $providerService->updateProvider($providerId, $updateData);
            
            $this->flash('success', 'Provider updated.');
            return $this->redirect($response, "/service-admin/providers");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/providers/$providerId/edit");
        }
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) $args['id'];
        
        $providerService = $this->get(OAuthProviderService::class);
        
        try {
            $provider = $providerService->getProviderById($providerId);
            if (!$provider) {
                throw new \Exception('Provider not found');
            }
            
            $providerService->updateProvider($providerId, [
                'is_enabled' => !$provider['is_enabled'],
            ]);
            
            $status = $provider['is_enabled'] ? 'disabled' : 'enabled';
            $this->flash('success', "Provider {$status}.");
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/service-admin/providers");
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

    /**
     * Get user data for template
     */
    private function getUserData($session): array
    {
        return [
            'display_name' => $session->getDisplayName(),
            'is_super_admin' => $session->isSuperAdmin(),
        ];
    }
}
