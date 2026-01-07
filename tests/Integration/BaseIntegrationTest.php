<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use CharityCashbook\Core\Container;
use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Logger;

/**
 * Base class for integration tests.
 * 
 * Uses an in-memory SQLite database for isolation and speed.
 * Each test class gets a fresh database, and each test runs in a transaction
 * that is rolled back for isolation.
 */
abstract class BaseIntegrationTest extends TestCase
{
    protected static ?Container $container = null;
    protected static ?App $app = null;
    protected static ?SqliteTestDatabase $db = null;
    
    protected TestFixtures $fixtures;
    
    /**
     * Set up the application once for all tests in this class
     */
    public static function setUpBeforeClass(): void
    {
        // Create fresh SQLite database for this test class
        self::$db = new SqliteTestDatabase();
        
        // Load config (for paths, not database)
        $config = self::loadTestConfig();
        
        // Create container with services
        self::$container = self::createContainer($config, self::$db);
        
        // Create Slim app
        self::$app = self::createApp(self::$container, $config);
    }
    
    /**
     * Clean up after all tests
     */
    public static function tearDownAfterClass(): void
    {
        self::$container = null;
        self::$app = null;
        self::$db = null;
    }
    
    /**
     * Set up each test with a transaction
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Start a transaction that will be rolled back after the test
        self::$db->beginTransaction();
        
        // Create fixtures helper
        $this->fixtures = new TestFixtures(self::$db);
    }
    
    /**
     * Roll back transaction after each test
     */
    protected function tearDown(): void
    {
        // Roll back all changes made during the test
        if (self::$db->inTransaction()) {
            self::$db->rollBack();
        }
        
        parent::tearDown();
    }
    
    /**
     * Load test configuration (for paths, not database)
     */
    protected static function loadTestConfig(): array
    {
        // Use a minimal config for testing - don't require config.php
        $projectRoot = dirname(__DIR__, 2);
        
        return [
            'app' => [
                'name' => 'CharityCashbook Test',
                'url' => 'http://localhost',
                'debug' => false,
            ],
            'paths' => [
                'views' => $projectRoot . '/src/Views',
                'cache' => sys_get_temp_dir() . '/charitycashbook_test_cache',
                'logs' => sys_get_temp_dir(),
            ],
        ];
    }
    
    /**
     * Create the DI container with all services
     */
    protected static function createContainer(array $config, DatabaseInterface $db): Container
    {
        $container = new Container();
        
        $container->set('config', fn() => $config);
        
        // Use provided SQLite database
        $container->set(DatabaseInterface::class, fn() => $db);
        
        // Logger (use temp directory for tests)
        $container->set(Logger::class, fn($c) => new Logger(
            $c->get('config')['paths']['logs'],
            false
        ));
        
        // Register all services
        $container->set(\CharityCashbook\Charity\AuditService::class, fn($c) => 
            new \CharityCashbook\Charity\AuditService(
                $c->get(DatabaseInterface::class),
                $c->get(Logger::class)
            )
        );
        
        $container->set(\CharityCashbook\Charity\CategoryService::class, fn($c) => 
            new \CharityCashbook\Charity\CategoryService($c->get(DatabaseInterface::class))
        );
        
        $container->set(\CharityCashbook\Charity\FundService::class, fn($c) => 
            new \CharityCashbook\Charity\FundService($c->get(DatabaseInterface::class))
        );
        
        $container->set(\CharityCashbook\Charity\PermissionService::class, fn($c) => 
            new \CharityCashbook\Charity\PermissionService($c->get(DatabaseInterface::class))
        );
        
        $container->set(\CharityCashbook\Charity\ModuleService::class, fn($c) => 
            new \CharityCashbook\Charity\ModuleService($c->get(DatabaseInterface::class))
        );
        
        $container->set(\CharityCashbook\Charity\DonorService::class, fn($c) => 
            new \CharityCashbook\Charity\DonorService(
                $c->get(DatabaseInterface::class),
                $c->get(Logger::class),
                $c->get(\CharityCashbook\Charity\AuditService::class)
            )
        );
        
        $container->set(\CharityCashbook\Charity\MemberService::class, fn($c) => 
            new \CharityCashbook\Charity\MemberService(
                $c->get(DatabaseInterface::class),
                $c->get(Logger::class),
                $c->get(\CharityCashbook\Charity\AuditService::class)
            )
        );
        
        $container->set(\CharityCashbook\Charity\TransactionService::class, fn($c) => 
            new \CharityCashbook\Charity\TransactionService(
                $c->get(DatabaseInterface::class),
                $c->get(Logger::class),
                $c->get(\CharityCashbook\Charity\CategoryService::class),
                $c->get(\CharityCashbook\Charity\AuditService::class)
            )
        );
        
        $container->set(\CharityCashbook\Charity\CharityRepository::class, fn($c) => 
            new \CharityCashbook\Charity\CharityRepository($c->get(DatabaseInterface::class))
        );
        
        $container->set(\CharityCashbook\Charity\CashBookService::class, fn($c) => 
            new \CharityCashbook\Charity\CashBookService($c->get(DatabaseInterface::class))
        );
        
        return $container;
    }
    
    /**
     * Create the Slim application
     */
    protected static function createApp(Container $container, array $config): App
    {
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        
        // Add error middleware
        $app->addErrorMiddleware(true, true, true);
        
        // Create Twig (use a temp directory for cache in tests)
        $twig = Twig::create($config['paths']['views'], [
            'cache' => false, // Disable cache in tests
        ]);
        
        // Add global template variables
        $twig->getEnvironment()->addGlobal('app_name', $config['app']['name'] ?? 'Test');
        $twig->getEnvironment()->addGlobal('app_url', $config['app']['url'] ?? 'http://localhost');
        $twig->getEnvironment()->addGlobal('csrf_token', 'test-csrf-token');
        $twig->getEnvironment()->addGlobal('debug_mode', false);
        
        $app->add(TwigMiddleware::create($app, $twig));
        
        // Load routes
        $routes = require __DIR__ . '/../../config/routes.php';
        $routes($app);
        
        return $app;
    }
    
    /**
     * Make an HTTP request to the app
     */
    protected function request(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        array $cookies = []
    ): ResponseInterface {
        $request = $this->createRequest($method, $uri, $data, $headers, $cookies);
        return self::$app->handle($request);
    }
    
    /**
     * Create a server request
     */
    protected function createRequest(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        array $cookies = []
    ): ServerRequestInterface {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);
        
        // Add headers
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        
        // Add cookies
        if (!empty($cookies)) {
            $request = $request->withCookieParams($cookies);
        }
        
        // Add body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            $request = $request->withParsedBody($data);
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        
        // Add query params for GET
        if ($method === 'GET' && !empty($data)) {
            $request = $request->withQueryParams($data);
        }
        
        return $request;
    }
    
    /**
     * Get the response body as string
     */
    protected function getResponseBody(ResponseInterface $response): string
    {
        $response->getBody()->rewind();
        return $response->getBody()->getContents();
    }
    
    /**
     * Assert response status code
     */
    protected function assertResponseStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals(
            $expected,
            $response->getStatusCode(),
            "Expected status $expected but got {$response->getStatusCode()}. Body: " . 
            substr($this->getResponseBody($response), 0, 500)
        );
    }
    
    /**
     * Assert response is a redirect
     */
    protected function assertRedirect(ResponseInterface $response, ?string $location = null): void
    {
        $this->assertTrue(
            $response->getStatusCode() >= 300 && $response->getStatusCode() < 400,
            "Expected redirect but got status {$response->getStatusCode()}"
        );
        
        if ($location !== null) {
            $this->assertEquals($location, $response->getHeaderLine('Location'));
        }
    }
    
    /**
     * Assert response body contains string
     */
    protected function assertResponseContains(string $needle, ResponseInterface $response): void
    {
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString($needle, $body);
    }
    
    /**
     * Assert response body does not contain string
     */
    protected function assertResponseNotContains(string $needle, ResponseInterface $response): void
    {
        $body = $this->getResponseBody($response);
        $this->assertStringNotContainsString($needle, $body);
    }
    
    /**
     * Get container service
     */
    protected function getService(string $class): mixed
    {
        return self::$container->get($class);
    }
    
    /**
     * Get database instance
     */
    protected function getDb(): DatabaseInterface
    {
        return self::$db;
    }
}
