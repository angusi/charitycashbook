<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Debug toolbar that displays diagnostic information.
 * Only active when debug mode is enabled.
 */
class DebugToolbar
{
    private float $startTime;
    private int $startMemory;
    private ?QueryTracker $queryTracker = null;
    private array $customMetrics = [];
    private array $timeline = [];

    public function __construct()
    {
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $this->startMemory = memory_get_usage();
        
        // First mark uses the actual request start time, not current time
        $this->timeline[] = [
            'label' => 'php_start',
            'time' => $this->startTime,
            'memory' => 0, // Unknown at that point
        ];
        $this->mark('autoload_complete');
    }

    /**
     * Set the query tracker instance
     */
    public function setQueryTracker(QueryTracker $tracker): void
    {
        $this->queryTracker = $tracker;
    }

    /**
     * Add a custom metric to display
     */
    public function addMetric(string $name, mixed $value): void
    {
        $this->customMetrics[$name] = $value;
    }

    /**
     * Mark a point in the timeline
     */
    public function mark(string $label): void
    {
        $this->timeline[] = [
            'label' => $label,
            'time' => microtime(true),
            'memory' => memory_get_usage(),
        ];
    }

    /**
     * Get elapsed time in milliseconds
     */
    public function getElapsedTime(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * Get memory usage in MB
     */
    public function getMemoryUsage(): float
    {
        return memory_get_usage() / 1024 / 1024;
    }

    /**
     * Get peak memory usage in MB
     */
    public function getPeakMemoryUsage(): float
    {
        return memory_get_peak_usage() / 1024 / 1024;
    }

    /**
     * Build timeline HTML showing phases
     */
    private function buildTimelineHtml(): string
    {
        if (count($this->timeline) < 2) {
            return '<p style="padding: 8px; color: #6b7280;">No timeline data</p>';
        }

        $totalTime = $this->getElapsedTime();
        $html = '<div style="padding: 8px;">';
        
        // Calculate phases between marks
        $phases = [];
        for ($i = 1; $i < count($this->timeline); $i++) {
            $prev = $this->timeline[$i - 1];
            $curr = $this->timeline[$i];
            $duration = ($curr['time'] - $prev['time']) * 1000;
            $memoryDelta = ($curr['memory'] - $prev['memory']) / 1024 / 1024;
            
            $phases[] = [
                'name' => $prev['label'] . ' → ' . $curr['label'],
                'duration' => $duration,
                'percent' => $totalTime > 0 ? ($duration / $totalTime) * 100 : 0,
                'memory_delta' => $memoryDelta,
            ];
        }

        // Add final phase to render
        $lastMark = end($this->timeline);
        $renderDuration = (microtime(true) - $lastMark['time']) * 1000;
        $phases[] = [
            'name' => $lastMark['label'] . ' → render',
            'duration' => $renderDuration,
            'percent' => $totalTime > 0 ? ($renderDuration / $totalTime) * 100 : 0,
            'memory_delta' => (memory_get_usage() - $lastMark['memory']) / 1024 / 1024,
        ];

        // Visual timeline bar
        $html .= '<div style="display: flex; height: 24px; margin-bottom: 12px; border-radius: 4px; overflow: hidden;">';
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4'];
        foreach ($phases as $i => $phase) {
            $color = $colors[$i % count($colors)];
            $width = max($phase['percent'], 1); // At least 1% width to be visible
            $html .= sprintf(
                '<div style="width: %.1f%%; background: %s;" title="%s: %.1fms"></div>',
                $width,
                $color,
                htmlspecialchars($phase['name']),
                $phase['duration']
            );
        }
        $html .= '</div>';

        // Phase details table
        $html .= '<table style="width: 100%; font-size: 11px; border-collapse: collapse;">';
        $html .= '<tr style="background: #f3f4f6;"><th style="text-align: left; padding: 4px;">Phase</th><th style="text-align: right; padding: 4px;">Time</th><th style="text-align: right; padding: 4px;">%</th><th style="text-align: right; padding: 4px;">Memory Δ</th></tr>';
        
        foreach ($phases as $i => $phase) {
            $color = $colors[$i % count($colors)];
            $html .= sprintf(
                '<tr><td style="padding: 4px;"><span style="display: inline-block; width: 10px; height: 10px; background: %s; margin-right: 6px; border-radius: 2px;"></span>%s</td><td style="text-align: right; padding: 4px;">%.1fms</td><td style="text-align: right; padding: 4px;">%.1f%%</td><td style="text-align: right; padding: 4px; color: %s;">%+.2fMB</td></tr>',
                $color,
                htmlspecialchars($phase['name']),
                $phase['duration'],
                $phase['percent'],
                $phase['memory_delta'] > 0 ? '#dc2626' : '#16a34a',
                $phase['memory_delta']
            );
        }
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the debug toolbar HTML
     */
    public function render(): string
    {
        $this->mark('toolbar_render');
        
        $elapsed = sprintf('%.1f', $this->getElapsedTime());
        $memory = sprintf('%.1f', $this->getMemoryUsage());
        $peakMemory = sprintf('%.1f', $this->getPeakMemoryUsage());
        
        $queryCount = 0;
        $queryTime = '0.0';
        $queries = [];
        
        if ($this->queryTracker) {
            $queryCount = $this->queryTracker->getQueryCount();
            $queryTime = sprintf('%.1f', $this->queryTracker->getTotalTime());
            $queries = $this->queryTracker->getQueries();
        }

        $phpVersion = PHP_VERSION;
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Calculate application time (total minus query time)
        $appTime = sprintf('%.1f', $this->getElapsedTime() - ($this->queryTracker ? $this->queryTracker->getTotalTime() : 0));

        // Build query list HTML
        $queryListHtml = '';
        foreach ($queries as $i => $q) {
            $duration = sprintf('%.2f', $q['duration'] * 1000);
            $sql = htmlspecialchars($q['sql'], ENT_QUOTES, 'UTF-8');
            $params = htmlspecialchars(json_encode($q['params'], JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
            $backtrace = htmlspecialchars(implode("\n", $q['backtrace']), ENT_QUOTES, 'UTF-8');
            
            // Highlight slow queries (> 50ms)
            $slowClass = $q['duration'] > 0.05 ? 'background: #fee2e2;' : '';
            
            $queryListHtml .= <<<HTML
            <div style="padding: 8px; border-bottom: 1px solid #e5e7eb; {$slowClass}">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-weight: 500; color: #6366f1;">#{$i} {$q['method']}</span>
                    <span style="color: #6b7280;">{$duration}ms</span>
                </div>
                <pre style="margin: 0; white-space: pre-wrap; word-break: break-all; font-size: 11px; color: #1f2937; background: #f9fafb; padding: 4px; border-radius: 2px;">{$sql}</pre>
                <details style="margin-top: 4px;">
                    <summary style="cursor: pointer; font-size: 11px; color: #6b7280;">Parameters & Trace</summary>
                    <pre style="margin: 4px 0 0 0; font-size: 10px; color: #6b7280;">{$params}</pre>
                    <pre style="margin: 4px 0 0 0; font-size: 10px; color: #9ca3af;">{$backtrace}</pre>
                </details>
            </div>
            HTML;
        }

        // Build custom metrics HTML
        $metricsHtml = '';
        foreach ($this->customMetrics as $name => $value) {
            $metricsHtml .= '<span style="margin-right: 16px;">' . htmlspecialchars($name) . ': <strong>' . htmlspecialchars((string)$value) . '</strong></span>';
        }

        // Build timeline HTML
        $timelineHtml = $this->buildTimelineHtml();

        return <<<HTML
        <!-- Debug Toolbar -->
        <div id="debug-toolbar" style="
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #1f2937;
            color: #f3f4f6;
            font-family: ui-monospace, monospace;
            font-size: 12px;
            z-index: 99999;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.3);
        ">
            <!-- Summary Bar -->
            <div id="debug-toolbar-bar" style="
                display: flex;
                align-items: center;
                padding: 8px 16px;
                cursor: pointer;
                user-select: none;
            " onclick="document.getElementById('debug-toolbar-panel').style.display = document.getElementById('debug-toolbar-panel').style.display === 'none' ? 'block' : 'none'">
                <span style="margin-right: 16px; color: #60a5fa;">🔧 Debug</span>
                <span style="margin-right: 16px;">⏱️ <strong>{$elapsed}ms</strong> (app: {$appTime}ms)</span>
                <span style="margin-right: 16px;">💾 <strong>{$memory}MB</strong> (peak: {$peakMemory}MB)</span>
                <span style="margin-right: 16px;">🗄️ <strong>{$queryCount}</strong> queries ({$queryTime}ms)</span>
                <span style="margin-right: 16px;">🐘 PHP {$phpVersion}</span>
                {$metricsHtml}
                <span style="margin-left: auto; color: #9ca3af;">Click to expand</span>
            </div>
            
            <!-- Expanded Panel -->
            <div id="debug-toolbar-panel" style="display: none; background: #fff; color: #1f2937; max-height: 400px; overflow-y: auto;">
                <!-- Request Info -->
                <div style="padding: 12px 16px; background: #f3f4f6; border-bottom: 1px solid #e5e7eb;">
                    <strong>Request:</strong> {$requestMethod} {$requestUri}
                </div>
                
                <!-- Timeline Section -->
                <div style="border-bottom: 1px solid #e5e7eb;">
                    <div style="padding: 8px 16px; background: #f9fafb; font-weight: 600; border-bottom: 1px solid #e5e7eb;">
                        Timeline
                    </div>
                    {$timelineHtml}
                </div>
                
                <!-- Queries Section -->
                <div style="border-bottom: 1px solid #e5e7eb;">
                    <div style="padding: 8px 16px; background: #f9fafb; font-weight: 600; border-bottom: 1px solid #e5e7eb;">
                        Database Queries ({$queryCount} queries, {$queryTime}ms total)
                    </div>
                    <div style="max-height: 300px; overflow-y: auto;">
                        {$queryListHtml}
                    </div>
                </div>
            </div>
        </div>
        HTML;
    }
}

