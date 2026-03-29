<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\QueryTracker;
use CharityCashbook\Core\DebugToolbar;
use CharityCashbook\Core\Logger;
use CharityCashbook\Core\Encryption;
use CharityCashbook\Core\Container;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Auth\IdentityService;
use CharityCashbook\Auth\OAuthProviderService;
use CharityCashbook\Auth\AccountLinkingService;
use CharityCashbook\Charity\CharityRepository;
use CharityCashbook\Charity\FundService;
use CharityCashbook\Charity\CategoryService;
use CharityCashbook\Charity\TransactionService;
use CharityCashbook\Charity\DonorService;
use CharityCashbook\Charity\GiftAidService;
use CharityCashbook\Charity\ReportService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Charity\PermissionService;
use CharityCashbook\Charity\ModuleService;
use CharityCashbook\ServiceAdmin\CharityManager;
use CharityCashbook\Core\Middleware\AuthenticationMiddleware;
use CharityCashbook\Core\Middleware\CharityAccessMiddleware;
use CharityCashbook\Core\Middleware\CharitySessionMiddleware;
use CharityCashbook\Core\Middleware\SuperAdminMiddleware;
use CharityCashbook\Core\Middleware\CsrfMiddleware;
use CharityCashbook\Core\Middleware\DebugToolbarMiddleware;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Create container
$container = new Container();

// Register services
$container->set('config', fn() => $config);

// Check debug mode early
$debugMode = filter_var($config['app']['debug'], FILTER_VALIDATE_BOOLEAN);

// Register the raw Database instance
$container->set('database.raw', fn($c) => new Database($c->get('config')['db']));

// Register QueryTracker (wraps Database for query tracking in debug mode)
$container->set(QueryTracker::class, fn($c) => new QueryTracker(
    $c->get('database.raw'),
    $c->get('config')['app']['debug']
));

// Register DebugToolbar
$container->set(DebugToolbar::class, function($c) {
    $toolbar = new DebugToolbar();
    $toolbar->setQueryTracker($c->get(QueryTracker::class));
    return $toolbar;
});

// Register Database::class to return QueryTracker (same interface, adds tracking)
// All services that inject Database will get QueryTracker instead
$container->set(Database::class, fn($c) => $c->get(QueryTracker::class));
$container->set(DatabaseInterface::class, fn($c) => $c->get(QueryTracker::class));

$container->set(Logger::class, fn($c) => new Logger(
    $c->get('config')['paths']['logs'],
    $c->get('config')['app']['debug']
));

$container->set(Encryption::class, fn($c) => new Encryption($c->get('config')['encryption']['key']));

$container->set(SessionManager::class, fn($c) => new SessionManager(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get('config')['session']['lifetime_days']
));

$container->set(IdentityService::class, fn($c) => new IdentityService(
    $c->get(Database::class),
    $c->get(Logger::class)
));

$container->set(OAuthProviderService::class, fn($c) => new OAuthProviderService(
    $c->get(Database::class),
    $c->get(Encryption::class),
    $c->get(Logger::class),
    $c->get('config')['app']['url']
));

$container->set(AccountLinkingService::class, fn($c) => new AccountLinkingService(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(IdentityService::class)
));

$container->set(AuditService::class, fn($c) => new AuditService(
    $c->get(Database::class),
    $c->get(Logger::class)
));

$container->set(CharityRepository::class, fn($c) => new CharityRepository($c->get(Database::class)));

$container->set(FundService::class, fn($c) => new FundService($c->get(Database::class)));

$container->set(CategoryService::class, fn($c) => new CategoryService($c->get(Database::class)));

$container->set(TransactionService::class, fn($c) => new TransactionService(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(CategoryService::class),
    $c->get(AuditService::class)
));

$container->set(DonorService::class, fn($c) => new DonorService(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(AuditService::class)
));

$container->set(\CharityCashbook\Charity\DeclarationTokenService::class, fn($c) => new \CharityCashbook\Charity\DeclarationTokenService(
    $c->get(Database::class),
    $c->get(Logger::class)
));

$container->set(GiftAidService::class, fn($c) => new GiftAidService(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(AuditService::class)
));

$container->set(\CharityCashbook\Charity\MemberService::class, fn($c) => new \CharityCashbook\Charity\MemberService(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(AuditService::class)
));

$container->set(\CharityCashbook\Charity\CashBookService::class, fn($c) => new \CharityCashbook\Charity\CashBookService(
    $c->get(Database::class),
    $c->get(Logger::class)
));

$container->set(ReportService::class, fn($c) => new ReportService(
    $c->get(Database::class),
    $c->get(FundService::class),
    $c->get(CategoryService::class)
));

$container->set(PermissionService::class, fn($c) => new PermissionService(
    $c->get(Database::class)
));

$container->set(ModuleService::class, fn($c) => new ModuleService(
    $c->get(Database::class)
));

$container->set(CharityManager::class, fn($c) => new CharityManager(
    $c->get(Database::class),
    $c->get(Logger::class),
    $c->get(CategoryService::class),
    $c->get(PermissionService::class),
    $c->get(ModuleService::class)
));

$container->set(\CharityCashbook\ServiceAdmin\TemplateService::class, fn($c) => new \CharityCashbook\ServiceAdmin\TemplateService(
    $c->get(Database::class),
    $c->get(Logger::class)
));

// Create middleware instances
$container->set(AuthenticationMiddleware::class, fn($c) => new AuthenticationMiddleware(
    $c->get(SessionManager::class)
));

$container->set(CharityAccessMiddleware::class, fn($c) => new CharityAccessMiddleware(
    $c->get(CharityRepository::class),
    $c->get(PermissionService::class),
    $c->get(ModuleService::class)
));

$container->set(CharitySessionMiddleware::class, fn($c) => new CharitySessionMiddleware(
    $c->get(SessionManager::class)
));

$container->set(SuperAdminMiddleware::class, fn() => new SuperAdminMiddleware());

// Register controllers
use CharityCashbook\Core\Controllers\HealthController;
use CharityCashbook\Auth\Controllers\LoginController;
use CharityCashbook\Auth\Controllers\AccountController;
use CharityCashbook\Auth\Controllers\OAuthCallbackController;
use CharityCashbook\Charity\Controllers\CharitySelectionController;
use CharityCashbook\Charity\Controllers\DashboardController as CharityDashboardController;
use CharityCashbook\Charity\Controllers\TransactionController;
use CharityCashbook\Charity\Controllers\FundController;
use CharityCashbook\Charity\Controllers\CategoryController as CharityCategoryController;
use CharityCashbook\Charity\Controllers\DonorController;
use CharityCashbook\Charity\Controllers\GiftAidController;
use CharityCashbook\Charity\Controllers\ReportController;
use CharityCashbook\Charity\Controllers\SettingsController;
use CharityCashbook\Charity\Controllers\UserController;
use CharityCashbook\Charity\Controllers\AuditController;
use CharityCashbook\Charity\Controllers\ApiController;
use CharityCashbook\Charity\Controllers\MemberController;
use CharityCashbook\ServiceAdmin\Controllers\DashboardController as AdminDashboardController;
use CharityCashbook\ServiceAdmin\Controllers\CharityController as AdminCharityController;
use CharityCashbook\ServiceAdmin\Controllers\ProviderController;
use CharityCashbook\ServiceAdmin\Controllers\DiagnosticsController;
use CharityCashbook\ServiceAdmin\Controllers\MaintenanceController;
use CharityCashbook\ServiceAdmin\Controllers\LogsController;
use CharityCashbook\ServiceAdmin\Controllers\PrivacyController as AdminPrivacyController;
use CharityCashbook\ServiceAdmin\Controllers\SecurityController;
use CharityCashbook\ServiceAdmin\ServiceMetricsService;
use CharityCashbook\Charity\Controllers\PrivacyController as CharityPrivacyController;
use CharityCashbook\Core\PrivacyService;

$container->set(HealthController::class, fn($c) => new HealthController($c));
$container->set(LoginController::class, fn($c) => new LoginController($c));
$container->set(AccountController::class, fn($c) => new AccountController($c));
$container->set(OAuthCallbackController::class, fn($c) => new OAuthCallbackController($c));
$container->set(CharitySelectionController::class, fn($c) => new CharitySelectionController($c));
$container->set(CharityDashboardController::class, fn($c) => new CharityDashboardController($c));
$container->set(TransactionController::class, fn($c) => new TransactionController($c));
$container->set(FundController::class, fn($c) => new FundController($c));
$container->set(CharityCategoryController::class, fn($c) => new CharityCategoryController($c));
$container->set(DonorController::class, fn($c) => new DonorController($c));
$container->set(GiftAidController::class, fn($c) => new GiftAidController($c));
$container->set(MemberController::class, fn($c) => new MemberController($c));
$container->set(\CharityCashbook\Charity\Controllers\CashBookController::class, fn($c) => new \CharityCashbook\Charity\Controllers\CashBookController($c));
$container->set(ReportController::class, fn($c) => new ReportController($c));
$container->set(SettingsController::class, fn($c) => new SettingsController($c));
$container->set(UserController::class, fn($c) => new UserController($c));
$container->set(AuditController::class, fn($c) => new AuditController($c));
$container->set(ApiController::class, fn($c) => new ApiController($c));
$container->set(AdminDashboardController::class, fn($c) => new AdminDashboardController($c));
$container->set(AdminCharityController::class, fn($c) => new AdminCharityController($c));
$container->set(ProviderController::class, fn($c) => new ProviderController($c));
$container->set(DiagnosticsController::class, fn($c) => new DiagnosticsController($c));
$container->set(MaintenanceController::class, fn($c) => new MaintenanceController($c));
$container->set(LogsController::class, fn($c) => new LogsController($c));
$container->set(AdminPrivacyController::class, fn($c) => new AdminPrivacyController($c));
$container->set(SecurityController::class, fn($c) => new SecurityController($c));
$container->set(CharityPrivacyController::class, fn($c) => new CharityPrivacyController($c));

$container->set(\CharityCashbook\Public\Controllers\GiftAidDeclarationController::class, fn($c) => new \CharityCashbook\Public\Controllers\GiftAidDeclarationController($c));

// Services
$container->set(ServiceMetricsService::class, fn($c) => new ServiceMetricsService(
    $c->get(Database::class)
));

// PrivacyService
$container->set(PrivacyService::class, fn($c) => new PrivacyService(
    $c->get(DatabaseInterface::class),
    $c->get(Logger::class),
    $c->get('config')['paths']['cache']
));

// Create Slim app with container
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add error middleware with custom error handler
$errorMiddleware = $app->addErrorMiddleware($debugMode, true, true);

// Set custom error handler to log errors
$logger = $container->get(Logger::class);
$errorMiddleware->setDefaultErrorHandler(function (
    \Psr\Http\Message\ServerRequestInterface $request,
    \Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app, $logger, $debugMode) {
    // Log the error
    $logger->error($exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'code' => $exception->getCode(),
        'trace' => $debugMode ? $exception->getTraceAsString() : null,
        'url' => (string) $request->getUri(),
        'method' => $request->getMethod(),
    ]);

    $response = $app->getResponseFactory()->createResponse();
    
    if ($debugMode) {
        // Show detailed error in debug mode
        $response->getBody()->write('<h1>Error</h1>');
        $response->getBody()->write('<p><strong>Type:</strong> ' . get_class($exception) . '</p>');
        $response->getBody()->write('<p><strong>Code:</strong> ' . $exception->getCode() . '</p>');
        $response->getBody()->write('<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>');
        $response->getBody()->write('<p><strong>File:</strong> ' . $exception->getFile() . '</p>');
        $response->getBody()->write('<p><strong>Line:</strong> ' . $exception->getLine() . '</p>');
        $response->getBody()->write('<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>');
    } else {
        // Show generic error in production
        $response->getBody()->write('<h1>Something went wrong</h1>');
        $response->getBody()->write('<p>An error occurred. Please try again later.</p>');
    }
    
    return $response->withStatus(500)->withHeader('Content-Type', 'text/html');
});

// Create Twig view
// Always use cache, but auto_reload in debug mode recompiles when templates change
$twigCacheDir = $config['paths']['cache'] . '/twig';
if (!is_dir($twigCacheDir)) {
    @mkdir($twigCacheDir, 0775, true);
    @chmod($config['paths']['cache'], 0775);
    @chmod($twigCacheDir, 0775);
}
$twig = Twig::create($config['paths']['views'], [
    'cache' => $twigCacheDir,
    'auto_reload' => $debugMode,
]);

// Add global template variables
$twig->getEnvironment()->addGlobal('app_name', $config['app']['name']);
$twig->getEnvironment()->addGlobal('app_url', rtrim($config['app']['url'], '/'));
$twig->getEnvironment()->addGlobal('csrf_token', CsrfMiddleware::getToken());
$twig->getEnvironment()->addGlobal('debug_mode', $debugMode);

// Add Twig middleware
$app->add(TwigMiddleware::create($app, $twig));

// Add debug toolbar middleware (only active in debug mode)
if ($debugMode) {
    $toolbar = $container->get(DebugToolbar::class);
    $toolbar->mark('bootstrap_complete');
    
    $app->add(new DebugToolbarMiddleware($toolbar, $debugMode));
}

// Add CSRF middleware for state-changing requests
$app->add(new CsrfMiddleware());

// Simple controller resolver that injects dependencies
$controllerResolver = function (string $controllerClass) use ($container) {
    return new $controllerClass($container);
};

// Load routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Mark routing complete if in debug mode
if ($debugMode) {
    $container->get(DebugToolbar::class)->mark('routing_loaded');
}

// Run app
$app->run();
