<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public legal pages - Privacy Policy, Terms of Service
 */
class LegalController extends BaseController
{
    public function privacy(Request $request, Response $response): Response
    {
        return $this->render($response, 'legal/privacy.html.twig', [
            'app_name' => $_ENV['APP_NAME'] ?? 'Charity Cashbook',
            'last_updated' => '6 January 2026',
        ], $request);
    }

    public function terms(Request $request, Response $response): Response
    {
        return $this->render($response, 'legal/terms.html.twig', [
            'app_name' => $_ENV['APP_NAME'] ?? 'Charity Cashbook',
            'last_updated' => '6 January 2026',
        ], $request);
    }
}
