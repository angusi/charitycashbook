<?php

declare(strict_types=1);

namespace CharityCashbook\Public\Controllers;

use CharityCashbook\Core\Controllers\BaseController;
use CharityCashbook\Charity\DeclarationTokenService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public-facing GiftAid declaration controller (no authentication required)
 */
class GiftAidDeclarationController extends BaseController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];
        $tokenService = $this->get(DeclarationTokenService::class);

        $data = $tokenService->validate($token);

        if (!$data || $data['status'] !== 'valid') {
            return $this->renderTokenError($request, $response, $data);
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'public/giftaid_declaration.html.twig', [
            'token' => $token,
            'donor' => [
                'name' => $data['donor_name'],
                'email' => $data['donor_email'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'],
                'address_city' => $data['address_city'],
                'address_postcode' => $data['address_postcode'],
            ],
            'charity_name' => $data['charity_name'],
            'flash' => $this->getFlash(),
        ]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];
        $tokenService = $this->get(DeclarationTokenService::class);

        $data = $tokenService->validate($token);

        if (!$data || $data['status'] !== 'valid') {
            return $this->renderTokenError($request, $response, $data);
        }

        $post = $this->getPostData($request);

        // Validate required fields
        $errors = $this->validateDeclaration($post);
        if (!empty($errors)) {
            $this->flash('error', implode(' ', $errors));
            return $this->redirect($response, "/giftaid/declare/$token");
        }

        $donorService = $this->get(DonorService::class);
        $donorId = (int) $data['donor_id'];
        $charityId = (int) $data['charity_id'];

        // Update donor record if details have changed
        $this->syncDonorDetails($donorService, $donorId, $charityId, $post, $data);

        // Create the declaration
        $declarationId = $donorService->addDeclaration($donorId, $charityId, null, [
            'start_date' => date('Y-m-d'),
            'covers_past_donations' => !empty($post['covers_past']),
            'covers_past_from_date' => !empty($post['covers_past']) && !empty($post['covers_past_from_date'])
                ? $post['covers_past_from_date']
                : null,
            'notes' => 'Submitted online by donor',
        ]);

        // Consume the token
        $tokenService->consume($token, $declarationId);

        return $this->redirect($response, "/giftaid/declare/$token/success");
    }

    public function success(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];
        $tokenService = $this->get(DeclarationTokenService::class);

        $data = $tokenService->validate($token);

        // Token should be 'used' at this point
        $charityName = $data['charity_name'] ?? 'the charity';

        $view = Twig::fromRequest($request);
        return $view->render($response, 'public/giftaid_declaration_success.html.twig', [
            'charity_name' => $charityName,
        ]);
    }

    private function renderTokenError(Request $request, Response $response, ?array $data): Response
    {
        $status = $data['status'] ?? 'invalid';
        $charityName = $data['charity_name'] ?? null;

        $view = Twig::fromRequest($request);
        return $view->render(
            $response,
            'public/giftaid_declaration_expired.html.twig',
            [
                'status' => $status,
                'charity_name' => $charityName,
            ]
        )->withStatus($status === 'used' ? 410 : 404);
    }

    private function validateDeclaration(array $post): array
    {
        $errors = [];

        if (empty(trim($post['name'] ?? ''))) {
            $errors[] = 'Your full name is required.';
        }
        if (empty(trim($post['address_line1'] ?? ''))) {
            $errors[] = 'Your address is required for Gift Aid.';
        }
        if (empty(trim($post['address_postcode'] ?? ''))) {
            $errors[] = 'Your postcode is required for Gift Aid.';
        }
        if (empty($post['confirm_taxpayer'])) {
            $errors[] = 'You must confirm you are a UK taxpayer to make a Gift Aid declaration.';
        }

        return $errors;
    }

    private function syncDonorDetails(
        DonorService $donorService,
        int $donorId,
        int $charityId,
        array $post,
        array $tokenData
    ): void {
        $changed = false;
        $fields = ['name' => 'donor_name', 'address_line1' => 'address_line1', 'address_line2' => 'address_line2', 'address_city' => 'address_city', 'address_postcode' => 'address_postcode'];

        foreach ($fields as $postField => $tokenField) {
            if (trim($post[$postField] ?? '') !== ($tokenData[$tokenField] ?? '')) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $donorService->updateFromPublicDeclaration($donorId, $charityId, [
                'name' => trim($post['name']),
                'address_line1' => trim($post['address_line1'] ?? ''),
                'address_line2' => trim($post['address_line2'] ?? ''),
                'address_city' => trim($post['address_city'] ?? ''),
                'address_postcode' => trim($post['address_postcode'] ?? ''),
            ]);
        }
    }
}
