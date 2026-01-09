<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Core\Exceptions\CsrfException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware for CSRF protection
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        // Only check state-changing methods
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            // Check for token in parsed body (form data)
            $body = $request->getParsedBody();
            $token = $body['csrf_token'] ?? '';

            // Also check X-CSRF-Token header (for JSON/AJAX requests)
            if (empty($token)) {
                $token = $request->getHeaderLine('X-CSRF-Token');
            }

            if (!$this->validateToken($token)) {
                throw new CsrfException();
            }
        }

        return $handler->handle($request);
    }

    private function validateToken(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $storedToken = $_SESSION['csrf_token'] ?? '';
        
        if (empty($storedToken) || empty($token)) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    /**
     * Generate a new CSRF token
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Get the current CSRF token (or generate if needed)
     */
    public static function getToken(): string
    {
        return self::generateToken();
    }
}
