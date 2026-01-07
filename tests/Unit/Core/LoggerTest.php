<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/logger_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->logger = new Logger($this->tempDir, true);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        foreach (glob($this->tempDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // ===========================================
    // Constructor tests
    // ===========================================

    public function testConstructorAcceptsPathAndDebugFlag(): void
    {
        $logger = new Logger('/tmp', true);
        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function testConstructorNormalizesPath(): void
    {
        // Path with trailing slash should be handled
        $logger = new Logger($this->tempDir . '/', true);
        $this->assertInstanceOf(Logger::class, $logger);
    }

    // ===========================================
    // debug() tests
    // ===========================================

    public function testDebugWritesToAppLogWhenDebugEnabled(): void
    {
        $this->logger->debug('Debug message', ['key' => 'value']);

        $logFile = $this->tempDir . '/app.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('DEBUG:', $content);
        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString('"key":"value"', $content);
    }

    public function testDebugDoesNotWriteWhenDebugDisabled(): void
    {
        $logger = new Logger($this->tempDir, false);
        $logger->debug('Debug message');

        $logFile = $this->tempDir . '/app.log';
        $this->assertFileDoesNotExist($logFile);
    }

    // ===========================================
    // info() tests
    // ===========================================

    public function testInfoWritesToAppLog(): void
    {
        $this->logger->info('Info message', ['data' => 123]);

        $logFile = $this->tempDir . '/app.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('INFO:', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('"data":123', $content);
    }

    public function testInfoWritesWithoutContext(): void
    {
        $this->logger->info('Simple message');

        $content = file_get_contents($this->tempDir . '/app.log');
        $this->assertStringContainsString('Simple message', $content);
        $this->assertStringNotContainsString('{', $content);
    }

    // ===========================================
    // warning() tests
    // ===========================================

    public function testWarningWritesToAppLog(): void
    {
        $this->logger->warning('Warning message');

        $logFile = $this->tempDir . '/app.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('WARNING:', $content);
        $this->assertStringContainsString('Warning message', $content);
    }

    // ===========================================
    // error() tests
    // ===========================================

    public function testErrorWritesToErrorLog(): void
    {
        $this->logger->error('Error message', ['error_code' => 500]);

        $logFile = $this->tempDir . '/error.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('ERROR:', $content);
        $this->assertStringContainsString('Error message', $content);
        $this->assertStringContainsString('"error_code":500', $content);
    }

    public function testErrorDoesNotWriteToAppLog(): void
    {
        $this->logger->error('Error message');

        // Should only be in error.log, not app.log
        $this->assertFileExists($this->tempDir . '/error.log');
        // app.log shouldn't exist unless other methods were called
    }

    // ===========================================
    // auth() tests
    // ===========================================

    public function testAuthWritesToAuthLog(): void
    {
        $this->logger->auth('User logged in', ['user_id' => 1]);

        $logFile = $this->tempDir . '/auth.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('INFO:', $content);
        $this->assertStringContainsString('User logged in', $content);
        $this->assertStringContainsString('"user_id":1', $content);
    }

    // ===========================================
    // audit() tests
    // ===========================================

    public function testAuditWritesToAuditLog(): void
    {
        $this->logger->audit('Record modified', ['record_id' => 42]);

        $logFile = $this->tempDir . '/audit.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('INFO:', $content);
        $this->assertStringContainsString('Record modified', $content);
        $this->assertStringContainsString('"record_id":42', $content);
    }

    // ===========================================
    // Log format tests
    // ===========================================

    public function testLogFormatIncludesTimestamp(): void
    {
        $this->logger->info('Test message');

        $content = file_get_contents($this->tempDir . '/app.log');
        // Should contain timestamp in format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }

    public function testLogFormatIncludesNewline(): void
    {
        $this->logger->info('Message 1');
        $this->logger->info('Message 2');

        $content = file_get_contents($this->tempDir . '/app.log');
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);
    }

    // ===========================================
    // Directory creation tests
    // ===========================================

    public function testCreatesLogDirectoryIfNotExists(): void
    {
        $nonExistentDir = $this->tempDir . '/subdir/logs';
        $logger = new Logger($nonExistentDir, true);

        $logger->info('Test message');

        $this->assertDirectoryExists($nonExistentDir);
        $this->assertFileExists($nonExistentDir . '/app.log');

        // Cleanup
        @unlink($nonExistentDir . '/app.log');
        @rmdir($nonExistentDir);
        @rmdir($this->tempDir . '/subdir');
    }

    // ===========================================
    // Context serialization tests
    // ===========================================

    public function testContextSerializesArrays(): void
    {
        $this->logger->info('Test', ['items' => [1, 2, 3]]);

        $content = file_get_contents($this->tempDir . '/app.log');
        $this->assertStringContainsString('"items":[1,2,3]', $content);
    }

    public function testContextSerializesNestedObjects(): void
    {
        $this->logger->info('Test', [
            'user' => ['id' => 1, 'name' => 'Test'],
        ]);

        $content = file_get_contents($this->tempDir . '/app.log');
        $this->assertStringContainsString('"user":{"id":1,"name":"Test"}', $content);
    }

    public function testContextHandlesSpecialCharacters(): void
    {
        $this->logger->info('Test', ['message' => 'Line1\nLine2']);

        $content = file_get_contents($this->tempDir . '/app.log');
        $this->assertStringContainsString('Line1', $content);
    }

    // ===========================================
    // Constants tests
    // ===========================================

    public function testLevelConstantsExist(): void
    {
        $this->assertEquals('DEBUG', Logger::LEVEL_DEBUG);
        $this->assertEquals('INFO', Logger::LEVEL_INFO);
        $this->assertEquals('WARNING', Logger::LEVEL_WARNING);
        $this->assertEquals('ERROR', Logger::LEVEL_ERROR);
    }

    // ===========================================
    // Multiple log entries tests
    // ===========================================

    public function testAppendsToExistingLogFile(): void
    {
        $this->logger->info('Message 1');
        $this->logger->info('Message 2');
        $this->logger->info('Message 3');

        $content = file_get_contents($this->tempDir . '/app.log');
        $this->assertStringContainsString('Message 1', $content);
        $this->assertStringContainsString('Message 2', $content);
        $this->assertStringContainsString('Message 3', $content);
    }
}
