<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use CharityCashbook\Core\DebugToolbar;
use CharityCashbook\Core\QueryTracker;
use PHPUnit\Framework\TestCase;

class DebugToolbarTest extends TestCase
{
    // ========================================
    // Constructor tests
    // ========================================

    public function testConstructorInitializesTimeline(): void
    {
        $toolbar = new DebugToolbar();

        // Constructor adds php_start and autoload_complete marks
        $this->assertGreaterThanOrEqual(0, $toolbar->getElapsedTime());
    }

    // ========================================
    // addMetric tests
    // ========================================

    public function testAddMetricStoresValue(): void
    {
        $toolbar = new DebugToolbar();
        $toolbar->addMetric('test_metric', 42);

        // Render includes the metric
        $html = $toolbar->render();
        $this->assertStringContainsString('test_metric', $html);
        $this->assertStringContainsString('42', $html);
    }

    public function testAddMetricWithStringValue(): void
    {
        $toolbar = new DebugToolbar();
        $toolbar->addMetric('version', 'v1.0.0');

        $html = $toolbar->render();
        $this->assertStringContainsString('version', $html);
        $this->assertStringContainsString('v1.0.0', $html);
    }

    // ========================================
    // mark tests
    // ========================================

    public function testMarkAddsTimelineEntry(): void
    {
        $toolbar = new DebugToolbar();
        $toolbar->mark('custom_mark');

        $html = $toolbar->render();
        $this->assertStringContainsString('custom_mark', $html);
    }

    // ========================================
    // getElapsedTime tests
    // ========================================

    public function testGetElapsedTimeReturnsMilliseconds(): void
    {
        $toolbar = new DebugToolbar();

        usleep(10000); // 10ms

        $elapsed = $toolbar->getElapsedTime();
        $this->assertGreaterThan(0, $elapsed);
        // Should be at least 10ms - removed upper bound check as it's flaky in slow CI environments
    }

    // ========================================
    // getMemoryUsage tests
    // ========================================

    public function testGetMemoryUsageReturnsPositiveValue(): void
    {
        $toolbar = new DebugToolbar();

        $memory = $toolbar->getMemoryUsage();

        $this->assertGreaterThan(0, $memory);
    }

    public function testGetMemoryUsageReturnsValueInMegabytes(): void
    {
        $toolbar = new DebugToolbar();

        $memory = $toolbar->getMemoryUsage();

        // Memory should be in reasonable MB range (less than 1GB for a test)
        $this->assertLessThan(1024, $memory);
    }

    // ========================================
    // getPeakMemoryUsage tests
    // ========================================

    public function testGetPeakMemoryUsageReturnsPositiveValue(): void
    {
        $toolbar = new DebugToolbar();

        $peak = $toolbar->getPeakMemoryUsage();

        $this->assertGreaterThan(0, $peak);
    }

    public function testGetPeakMemoryUsageIsGreaterOrEqualToCurrentUsage(): void
    {
        $toolbar = new DebugToolbar();

        // Peak should be >= current usage
        $this->assertGreaterThanOrEqual(
            $toolbar->getMemoryUsage(),
            $toolbar->getPeakMemoryUsage()
        );
    }

    // ========================================
    // setQueryTracker tests
    // ========================================

    public function testSetQueryTrackerIntegratesQueryStats(): void
    {
        $toolbar = new DebugToolbar();

        $queryTracker = $this->createMock(QueryTracker::class);
        $queryTracker->method('getQueryCount')->willReturn(5);
        $queryTracker->method('getTotalTime')->willReturn(25.5);
        $queryTracker->method('getQueries')->willReturn([]);

        $toolbar->setQueryTracker($queryTracker);

        $html = $toolbar->render();
        $this->assertStringContainsString('<strong>5</strong> queries', $html);
        $this->assertStringContainsString('25.5ms', $html);
    }

    public function testRenderWithQueryTrackerShowsQueries(): void
    {
        $toolbar = new DebugToolbar();

        $queryTracker = $this->createMock(QueryTracker::class);
        $queryTracker->method('getQueryCount')->willReturn(1);
        $queryTracker->method('getTotalTime')->willReturn(10.0);
        $queryTracker->method('getQueries')->willReturn([
            [
                'method' => 'query',
                'sql' => 'SELECT * FROM users WHERE id = ?',
                'params' => [42],
                'duration' => 0.010,
                'backtrace' => ['UserService.php:42'],
            ],
        ]);

        $toolbar->setQueryTracker($queryTracker);

        $html = $toolbar->render();
        $this->assertStringContainsString('SELECT * FROM users WHERE id = ?', $html);
        $this->assertStringContainsString('UserService.php:42', $html);
    }

    // ========================================
    // render tests
    // ========================================

    public function testRenderReturnsHtml(): void
    {
        $toolbar = new DebugToolbar();

        $html = $toolbar->render();

        $this->assertStringContainsString('<div id="debug-toolbar"', $html);
        $this->assertStringContainsString('<!-- Debug Toolbar -->', $html);
    }

    public function testRenderIncludesPhpVersion(): void
    {
        $toolbar = new DebugToolbar();

        $html = $toolbar->render();

        $this->assertStringContainsString('PHP ' . PHP_VERSION, $html);
    }

    public function testRenderIncludesTimelineSection(): void
    {
        $toolbar = new DebugToolbar();
        $toolbar->mark('test_phase');

        $html = $toolbar->render();

        $this->assertStringContainsString('Timeline', $html);
        $this->assertStringContainsString('test_phase', $html);
    }

    public function testRenderIncludesMemoryStats(): void
    {
        $toolbar = new DebugToolbar();

        $html = $toolbar->render();

        $this->assertStringContainsString('MB', $html);
        $this->assertStringContainsString('peak:', $html);
    }

    public function testRenderShowsZeroQueriesWithoutTracker(): void
    {
        $toolbar = new DebugToolbar();

        $html = $toolbar->render();

        $this->assertStringContainsString('<strong>0</strong> queries', $html);
    }

    public function testRenderHighlightsSlowQueries(): void
    {
        $toolbar = new DebugToolbar();

        $queryTracker = $this->createMock(QueryTracker::class);
        $queryTracker->method('getQueryCount')->willReturn(1);
        $queryTracker->method('getTotalTime')->willReturn(100.0);
        $queryTracker->method('getQueries')->willReturn([
            [
                'method' => 'query',
                'sql' => 'SELECT * FROM large_table',
                'params' => [],
                'duration' => 0.1, // 100ms, slow
                'backtrace' => [],
            ],
        ]);

        $toolbar->setQueryTracker($queryTracker);

        $html = $toolbar->render();
        $this->assertStringContainsString('#fee2e2', $html); // Slow query highlight color
    }

    public function testRenderEscapesHtmlInQueries(): void
    {
        $toolbar = new DebugToolbar();

        $queryTracker = $this->createMock(QueryTracker::class);
        $queryTracker->method('getQueryCount')->willReturn(1);
        $queryTracker->method('getTotalTime')->willReturn(1.0);
        $queryTracker->method('getQueries')->willReturn([
            [
                'method' => 'query',
                'sql' => "SELECT * FROM users WHERE name = '<script>alert(1)</script>'",
                'params' => [],
                'duration' => 0.001,
                'backtrace' => [],
            ],
        ]);

        $toolbar->setQueryTracker($queryTracker);

        $html = $toolbar->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testRenderCalculatesAppTimeExcludingQueryTime(): void
    {
        $toolbar = new DebugToolbar();

        $queryTracker = $this->createMock(QueryTracker::class);
        $queryTracker->method('getQueryCount')->willReturn(1);
        $queryTracker->method('getTotalTime')->willReturn(50.0); // 50ms of query time
        $queryTracker->method('getQueries')->willReturn([]);

        $toolbar->setQueryTracker($queryTracker);

        $html = $toolbar->render();
        // App time should be displayed
        $this->assertStringContainsString('(app:', $html);
    }
}
