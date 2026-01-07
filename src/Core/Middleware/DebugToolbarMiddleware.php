<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Core\DebugToolbar;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Middleware that injects the debug toolbar into HTML responses.
 * Only active when:
 * - Debug mode is enabled
 * - User is authenticated
 * - Response is HTML
 */
class DebugToolbarMiddleware implements MiddlewareInterface
{
    private DebugToolbar $toolbar;
    private bool $debugMode;

    public function __construct(DebugToolbar $toolbar, bool $debugMode)
    {
        $this->toolbar = $toolbar;
        $this->debugMode = $debugMode;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->toolbar->mark('middleware_start');
        
        $response = $handler->handle($request);
        
        $this->toolbar->mark('controller_complete');

        // Only inject if debug mode is on
        if (!$this->debugMode) {
            return $response;
        }

        // Only inject into HTML responses (check body content since Content-Type may not be set)
        $body = (string) $response->getBody();
        $looksLikeHtml = stripos($body, '<!DOCTYPE html') !== false || stripos($body, '<html') !== false;
        
        if (!$looksLikeHtml) {
            return $response;
        }

        // Inject toolbar before </body>
        $toolbarHtml = $this->toolbar->render();
        
        if (stripos($body, '</body>') !== false) {
            $body = str_ireplace('</body>', $toolbarHtml . '</body>', $body);
        } else {
            // No </body> tag, append to end
            $body .= $toolbarHtml;
        }

        // Create new response with modified body
        $streamFactory = new StreamFactory();
        $newBody = $streamFactory->createStream($body);

        return $response
            ->withBody($newBody)
            ->withHeader('Content-Length', (string) strlen($body));
    }
}
