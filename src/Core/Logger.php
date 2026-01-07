<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Logger with multiple log levels and outputs
 */
class Logger
{
    private string $logPath;
    private bool $debug;

    public const LEVEL_DEBUG = 'DEBUG';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR = 'ERROR';

    public function __construct(string $logPath, bool $debug = false)
    {
        $this->logPath = rtrim($logPath, '/');
        $this->debug = $debug;
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->log(self::LEVEL_DEBUG, $message, $context, 'app.log');
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context, 'app.log');
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context, 'app.log');
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context, 'error.log');
    }

    public function auth(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context, 'auth.log');
    }

    public function audit(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context, 'audit.log');
    }

    private function log(string $level, string $message, array $context, string $file): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = "[$timestamp] $level: $message$contextStr\n";

        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }

        $filePath = $this->logPath . '/' . $file;
        @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    }
}
