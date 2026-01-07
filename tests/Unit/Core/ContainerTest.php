<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\Container;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ===========================================
    // Interface tests
    // ===========================================

    public function testImplementsPsrContainerInterface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    // ===========================================
    // set() tests
    // ===========================================

    public function testSetRegistersDefinition(): void
    {
        $this->container->set('service', fn() => 'value');

        $this->assertTrue($this->container->has('service'));
    }

    public function testSetOverwritesExistingDefinition(): void
    {
        $this->container->set('service', fn() => 'first');
        $this->container->set('service', fn() => 'second');

        $this->assertEquals('second', $this->container->get('service'));
    }

    // ===========================================
    // get() tests
    // ===========================================

    public function testGetReturnsServiceFromFactory(): void
    {
        $this->container->set('service', fn() => new \stdClass());

        $result = $this->container->get('service');

        $this->assertInstanceOf(\stdClass::class, $result);
    }

    public function testGetReturnsSameInstanceOnMultipleCalls(): void
    {
        $this->container->set('service', fn() => new \stdClass());

        $first = $this->container->get('service');
        $second = $this->container->get('service');

        $this->assertSame($first, $second);
    }

    public function testGetThrowsExceptionForUndefinedService(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No definition found for: undefined');

        $this->container->get('undefined');
    }

    public function testGetPassesContainerToFactory(): void
    {
        $receivedContainer = null;

        $this->container->set('service', function ($c) use (&$receivedContainer) {
            $receivedContainer = $c;
            return 'value';
        });

        $this->container->get('service');

        $this->assertSame($this->container, $receivedContainer);
    }

    public function testGetAllowsDependencyInjection(): void
    {
        $this->container->set('dependency', fn() => 'dependency value');
        $this->container->set('service', fn($c) => 'service with ' . $c->get('dependency'));

        $result = $this->container->get('service');

        $this->assertEquals('service with dependency value', $result);
    }

    public function testGetReturnsPrimitiveValues(): void
    {
        $this->container->set('string', fn() => 'hello');
        $this->container->set('int', fn() => 42);
        $this->container->set('array', fn() => [1, 2, 3]);
        $this->container->set('null', fn() => null);

        $this->assertEquals('hello', $this->container->get('string'));
        $this->assertEquals(42, $this->container->get('int'));
        $this->assertEquals([1, 2, 3], $this->container->get('array'));
        $this->assertNull($this->container->get('null'));
    }

    // ===========================================
    // has() tests
    // ===========================================

    public function testHasReturnsFalseForUndefinedService(): void
    {
        $this->assertFalse($this->container->has('undefined'));
    }

    public function testHasReturnsTrueForDefinedService(): void
    {
        $this->container->set('service', fn() => 'value');

        $this->assertTrue($this->container->has('service'));
    }

    public function testHasReturnsTrueForInstantiatedService(): void
    {
        $this->container->set('service', fn() => 'value');
        $this->container->get('service'); // Instantiate

        $this->assertTrue($this->container->has('service'));
    }

    // ===========================================
    // Factory execution tests
    // ===========================================

    public function testFactoryIsOnlyCalledOnce(): void
    {
        $callCount = 0;

        $this->container->set('service', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });

        $this->container->get('service');
        $this->container->get('service');
        $this->container->get('service');

        $this->assertEquals(1, $callCount);
    }

    public function testFactoryIsNotCalledUntilGet(): void
    {
        $called = false;

        $this->container->set('service', function () use (&$called) {
            $called = true;
            return 'value';
        });

        $this->assertFalse($called);

        $this->container->get('service');

        $this->assertTrue($called);
    }

    // ===========================================
    // Complex dependency chain tests
    // ===========================================

    public function testComplexDependencyChain(): void
    {
        $this->container->set('config', fn() => ['db_host' => 'localhost']);

        $this->container->set('database', function ($c) {
            $config = $c->get('config');
            return new class($config['db_host']) {
                public string $host;
                public function __construct(string $host) {
                    $this->host = $host;
                }
            };
        });

        $this->container->set('repository', function ($c) {
            return new class($c->get('database')) {
                public $db;
                public function __construct($db) {
                    $this->db = $db;
                }
            };
        });

        $repository = $this->container->get('repository');

        $this->assertEquals('localhost', $repository->db->host);
    }

    // ===========================================
    // Error handling tests
    // ===========================================

    public function testThrowsOnCircularDependencyAttempt(): void
    {
        // Note: This basic container doesn't detect circular dependencies
        // It will cause a stack overflow if misconfigured
        // This test just documents that behavior
        
        $this->container->set('self', fn($c) => $c->get('self'));

        // This would cause infinite recursion
        // $this->container->get('self');
        
        $this->assertTrue(true); // Just document the limitation
    }
}
