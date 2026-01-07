<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CharityCashbook\Core\Database;

/**
 * Health check endpoint
 */
class HealthController extends BaseController
{
    public function check(Request $request, Response $response): Response
    {
        $status = 'ok';
        $dbStatus = 'ok';

        try {
            $db = $this->get(Database::class);
            $db->queryValue("SELECT 1");
        } catch (\Exception $e) {
            $dbStatus = 'error';
            $status = 'degraded';
        }

        return $this->json($response, [
            'status' => $status,
            'database' => $dbStatus,
            'version' => '1.0.0',
            'timestamp' => date('c'),
        ]);
    }
}
