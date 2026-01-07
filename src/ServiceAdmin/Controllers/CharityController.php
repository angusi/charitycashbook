<?php

declare(strict_types=1);

namespace CharityCashbook\ServiceAdmin\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\ServiceAdmin\CharityManager;
use CharityCashbook\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Charity management controller for service admin
 */
class CharityController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        $params = $this->getQueryParams($request);
        
        $charityManager = $this->get(CharityManager::class);
        
        $search = $params['search'] ?? null;
        $status = $params['status'] ?? null;
        
        $charities = $charityManager->listCharities($search, $status);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/charities/index.html.twig', [
            'user' => $this->getUserData($session),
            'charities' => $charities,
            'search' => $search,
            'status' => $status,
            'flash' => $this->getFlash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        
        $categoryService = $this->get(\CharityCashbook\Charity\CategoryService::class);
        $templates = $categoryService->getAvailableTemplates();
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/charities/create.html.twig', [
            'user' => $this->getUserData($session),
            'templates' => $templates,
            'flash' => $this->getFlash(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:200',
                'admin_email' => 'required|email|max:255',
            ]);
            
            // Validate year end date
            $yearEndDay = (int) ($data['year_end_day'] ?? 31);
            $yearEndMonth = (int) ($data['year_end_month'] ?? 3);
            if (!$this->isValidDayMonth($yearEndDay, $yearEndMonth)) {
                throw new ValidationException(['Invalid financial year end date']);
            }
            
            $charityManager = $this->get(CharityManager::class);
            
            $id = $charityManager->createCharity([
                'name' => trim($data['name']),
                'charity_number' => $data['charity_number'] ?? null,
                'hmrc_reference' => $data['hmrc_reference'] ?? null,
                'address' => $data['address'] ?? null,
                'year_end_day' => $yearEndDay,
                'year_end_month' => $yearEndMonth,
                'template_id' => $data['template_id'] ?? null,
            ], $data['admin_email']);
            
            $this->flash('success', 'Charity created successfully.');
            return $this->redirect($response, "/service-admin/charities/$id");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/charities/create");
        }
    }
    
    private function isValidDayMonth(int $day, int $month): bool
    {
        if ($month < 1 || $month > 12) {
            return false;
        }
        $maxDays = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return $day >= 1 && $day <= $maxDays[$month - 1];
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $charityId = (int) $args['id'];
        
        $charityManager = $this->get(CharityManager::class);
        
        $charity = $charityManager->getCharity($charityId);
        
        if (!$charity) {
            $this->flash('error', 'Charity not found.');
            return $this->redirect($response, "/service-admin/charities");
        }
        
        $stats = $charityManager->getCharityStats($charityId);
        $users = $charityManager->getCharityUsers($charityId);
        $recentLogins = $charityManager->getRecentLogins($charityId, 10);
        $allowedEmails = $charityManager->getAllowedEmails($charityId);
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/charities/show.html.twig', [
            'user' => $this->getUserData($session),
            'charity' => $charity,
            'stats' => $stats,
            'users' => $users,
            'recent_logins' => $recentLogins,
            'allowed_emails' => $allowedEmails,
            'flash' => $this->getFlash(),
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $session = $request->getAttribute('session');
        $charityId = (int) $args['id'];
        
        $charityManager = $this->get(CharityManager::class);
        $charity = $charityManager->getCharity($charityId);
        
        if (!$charity) {
            $this->flash('error', 'Charity not found.');
            return $this->redirect($response, "/service-admin/charities");
        }
        
        $view = Twig::fromRequest($request);
        return $view->render($response, 'service-admin/charities/edit.html.twig', [
            'user' => $this->getUserData($session),
            'charity' => $charity,
            'flash' => $this->getFlash(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        try {
            $this->validate($data, [
                'name' => 'required|max:200',
            ]);
            
            $yearEndDay = (int) ($data['year_end_day'] ?? 31);
            $yearEndMonth = (int) ($data['year_end_month'] ?? 3);
            if (!$this->isValidDayMonth($yearEndDay, $yearEndMonth)) {
                throw new ValidationException(['Invalid financial year end date']);
            }
            
            $charityManager = $this->get(CharityManager::class);
            $charityManager->updateCharity($charityId, [
                'name' => trim($data['name']),
                'registration_number' => $data['registration_number'] ?? null,
                'hmrc_reference' => $data['hmrc_reference'] ?? null,
                'address' => $data['address'] ?? null,
                'year_end_day' => $yearEndDay,
                'year_end_month' => $yearEndMonth,
            ]);
            
            $this->flash('success', 'Charity updated successfully.');
            return $this->redirect($response, "/service-admin/charities/$charityId");
            
        } catch (ValidationException $e) {
            $this->flash('error', implode(', ', $e->getErrors()));
            return $this->redirect($response, "/service-admin/charities/$charityId/edit");
        }
    }

    public function suspend(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $data = $this->getPostData($request);
        
        $charityManager = $this->get(CharityManager::class);
        
        try {
            $charityManager->suspendCharity($charityId, $data['reason'] ?? null);
            $this->flash('success', 'Charity suspended.');
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/service-admin/charities/$charityId");
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        
        $charityManager = $this->get(CharityManager::class);
        
        try {
            $charityManager->reactivateCharity($charityId);
            $this->flash('success', 'Charity reactivated.');
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/service-admin/charities/$charityId");
    }

    public function sendInvitation(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $data = $this->getPostData($request);
        $email = trim($data['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please provide a valid email address.');
            return $this->redirect($response, "/service-admin/charities/$charityId");
        }

        try {
            $charityManager = $this->get(CharityManager::class);
            $config = $this->get('config');
            $charityManager->sendInvitation($charityId, $email, $config['app']['url']);
            $this->flash('success', "Invitation sent to $email");
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to send invitation: ' . $e->getMessage());
        }

        return $this->redirect($response, "/service-admin/charities/$charityId");
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
                
                if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid email';
                }
                
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters";
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Promote a user to admin role
     */
    public function makeUserAdmin(Request $request, Response $response, array $args): Response
    {
        $charityId = (int) $args['id'];
        $userId = (int) $args['user_id'];
        
        $charityManager = $this->get(CharityManager::class);
        
        try {
            $charityManager->setUserRole($charityId, $userId, 'admin');
            $this->flash('success', 'User promoted to Admin.');
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        
        return $this->redirect($response, "/service-admin/charities/$charityId");
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
