<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to add security headers to all responses
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Prevent clickjacking
        $response = $response->withHeader('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        // XSS protection for older browsers
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');

        // Referrer policy - don't leak full URLs to external sites
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions policy - disable unnecessary browser features
        $response = $response->withHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=()'
        );

        // Content Security Policy
        // Note: 'unsafe-inline' is needed for Tailwind and inline scripts
        // In production, consider using nonces for scripts
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
        ]);
        $response = $response->withHeader('Content-Security-Policy', $csp);

        return $response;
    }
}
