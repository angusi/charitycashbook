<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Middleware;

use CharityCashbook\Charity\ModuleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Middleware to block access to disabled modules
 */
class ModuleMiddleware implements MiddlewareInterface
{
    private string $moduleId;

    public function __construct(string $moduleId)
    {
        $this->moduleId = $moduleId;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $charityId = (int) $request->getAttribute('charity_id');
        $moduleService = $request->getAttribute('module_service');
        
        if (!$moduleService || !$moduleService->isModuleEnabled($charityId, $this->moduleId)) {
            // Module is disabled - redirect to dashboard with flash message
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['flash']['error'][] = 'This feature is not enabled for your charity.';
            
            $response = new Response();
            return $response
                ->withHeader('Location', "/charity/$charityId")
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
