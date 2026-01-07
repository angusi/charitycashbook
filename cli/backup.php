<?php

declare(strict_types=1);

/**
 * Backup CLI tool for database exports
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;
use CharityCashbook\Core\Logger;

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config['db']);
$logger = new Logger($config['paths']['logs']);

$args = parseArgs($argv);

// Determine output file
$output = $args['output'] ?? null;
if (!$output) {
    $defaultName = 'charity-cashbook-' . date('Ymd-His') . '.sql';
    $output = getcwd() . '/' . $defaultName;
}

// Check for charity-specific backup
$charityId = isset($args['charity']) ? (int) $args['charity'] : null;

if (isset($args['help']) || isset($args['h'])) {
    showHelp();
    exit(0);
}

try {
    if ($charityId) {
        exportCharity($db, $charityId, $output, $config, $logger);
    } else {
        fullBackup($config, $output, $logger);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function showHelp(): void
{
    echo <<<HELP
Charity Cashbook Backup Tool

Usage: php backup.php [options]

Options:
  --output=<path>     Output file path (default: charity-cashbook-YYYYMMDD-HHMMSS.sql)
  --charity=<id>      Export only specific charity data (for data export requests)
  --help, -h          Show this help message

Examples:
  # Full database backup
  php backup.php --output=/backups/charity-cashbook.sql
  
  # Export specific charity (for GDPR data export)
  php backup.php --charity=5 --output=/exports/charity-5-export.sql

HELP;
}

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
            $args[$matches[1]] = $matches[2];
        } elseif (preg_match('/^--([^=]+)$/', $arg, $matches)) {
            $args[$matches[1]] = true;
        } elseif (preg_match('/^-([a-z])$/i', $arg, $matches)) {
            $args[$matches[1]] = true;
        }
    }
    return $args;
}

function fullBackup(array $config, string $output, Logger $logger): void
{
    echo "Creating full database backup...\n";
    
    $dbConfig = $config['db'];
    $host = $dbConfig['host'];
    $port = $dbConfig['port'] ?? 3306;
    $database = $dbConfig['database'];
    $user = $dbConfig['user'];
    $password = $dbConfig['password'];
    
    // Create output directory if needed
    $dir = dirname($output);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Build mysqldump command
    $cmd = sprintf(
        'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers %s > %s 2>&1',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        escapeshellarg($password),
        escapeshellarg($database),
        escapeshellarg($output)
    );
    
    exec($cmd, $cmdOutput, $returnCode);
    
    if ($returnCode !== 0) {
        throw new RuntimeException("mysqldump failed: " . implode("\n", $cmdOutput));
    }
    
    $size = filesize($output);
    $sizeFormatted = formatBytes($size);
    
    echo "Backup complete: $output ($sizeFormatted)\n";
    $logger->info("Backup: full database backup created", ['output' => $output, 'size' => $size]);
}

function exportCharity(Database $db, int $charityId, string $output, array $config, Logger $logger): void
{
    echo "Exporting charity ID $charityId...\n";
    
    // Verify charity exists
    $charity = $db->queryOne("SELECT id, name FROM charities WHERE id = ?", [$charityId]);
    if (!$charity) {
        throw new RuntimeException("Charity not found: $charityId");
    }
    
    echo "Charity: {$charity['name']}\n";
    
    // Create output directory if needed
    $dir = dirname($output);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $handle = fopen($output, 'w');
    if (!$handle) {
        throw new RuntimeException("Cannot open output file: $output");
    }
    
    // Write header
    fwrite($handle, "-- Charity Cashbook Export\n");
    fwrite($handle, "-- Charity: {$charity['name']} (ID: $charityId)\n");
    fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- \n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Export charity record
    exportTable($db, $handle, 'charities', "id = $charityId");
    
    // Export charity OAuth providers
    exportTable($db, $handle, 'charity_oauth_providers', "charity_id = $charityId");
    
    // Export charity allowed emails
    exportTable($db, $handle, 'charity_allowed_emails', "charity_id = $charityId");
    
    // Export charity users
    exportTable($db, $handle, 'charity_users', "charity_id = $charityId");
    
    // Export funds
    exportTable($db, $handle, 'funds', "charity_id = $charityId");
    
    // Export reporting categories
    exportTable($db, $handle, 'reporting_categories', "charity_id = $charityId");
    
    // Export operational categories
    exportTable($db, $handle, 'operational_categories', "charity_id = $charityId");
    
    // Export donors
    exportTable($db, $handle, 'donors', "charity_id = $charityId");
    
    // Export declarations (through donors)
    $donorIds = $db->query("SELECT id FROM donors WHERE charity_id = ?", [$charityId]);
    if (!empty($donorIds)) {
        $idList = implode(',', array_column($donorIds, 'id'));
        exportTable($db, $handle, 'declarations', "donor_id IN ($idList)");
    }
    
    // Export Gift Aid claims
    exportTable($db, $handle, 'giftaid_claims', "charity_id = $charityId");
    
    // Export transactions
    exportTable($db, $handle, 'transactions', "charity_id = $charityId");
    
    // Export transaction audit
    $transactionIds = $db->query("SELECT id FROM transactions WHERE charity_id = ?", [$charityId]);
    if (!empty($transactionIds)) {
        $idList = implode(',', array_column($transactionIds, 'id'));
        exportTable($db, $handle, 'transaction_audit', "transaction_id IN ($idList)");
    }
    
    // Export Gift Aid claim items
    $claimIds = $db->query("SELECT id FROM giftaid_claims WHERE charity_id = ?", [$charityId]);
    if (!empty($claimIds)) {
        $idList = implode(',', array_column($claimIds, 'id'));
        exportTable($db, $handle, 'giftaid_claim_items', "claim_id IN ($idList)");
    }
    
    // Export audit log entries for this charity
    exportTable($db, $handle, 'audit_log', "charity_id = $charityId");
    
    // Export donor access log
    exportTable($db, $handle, 'donor_access_log', "charity_id = $charityId");
    
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
    
    $size = filesize($output);
    $sizeFormatted = formatBytes($size);
    
    echo "Export complete: $output ($sizeFormatted)\n";
    $logger->info("Backup: charity export created", [
        'charity_id' => $charityId,
        'output' => $output,
        'size' => $size,
    ]);
}

function exportTable(Database $db, $handle, string $table, string $where): void
{
    $rows = $db->query("SELECT * FROM $table WHERE $where");
    
    if (empty($rows)) {
        fwrite($handle, "-- No data for $table\n\n");
        return;
    }
    
    fwrite($handle, "-- $table: " . count($rows) . " rows\n");
    
    $columns = array_keys($rows[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';
    
    foreach ($rows as $row) {
        $values = array_map(function ($value) {
            if ($value === null) {
                return 'NULL';
            }
            if (is_numeric($value) && !is_string($value)) {
                return $value;
            }
            // Escape string values
            return "'" . addslashes((string) $value) . "'";
        }, array_values($row));
        
        $valueList = implode(', ', $values);
        fwrite($handle, "INSERT INTO `$table` ($columnList) VALUES ($valueList);\n");
    }
    
    fwrite($handle, "\n");
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
