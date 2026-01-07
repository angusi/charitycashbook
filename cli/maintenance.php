<?php

declare(strict_types=1);

/**
 * Maintenance CLI tool for cleanup tasks
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;
use CharityCashbook\Auth\SessionManager;
use CharityCashbook\Auth\AccountLinkingService;
use CharityCashbook\Charity\AuditService;
use CharityCashbook\Core\Logger;

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config['db']);
$logger = new Logger($config['paths']['logs']);

if ($argc < 2) {
    showHelp();
    exit(1);
}

$command = $argv[1];

switch ($command) {
    case 'clean-sessions':
        cleanSessions($db, $logger);
        break;
    case 'clean-tokens':
        cleanTokens($db, $logger);
        break;
    case 'clean-logs':
        $args = parseArgs($argv);
        $years = isset($args['older-than']) ? (int) str_replace('years', '', $args['older-than']) : 7;
        cleanLogs($db, $logger, $years);
        break;
    case 'cache-clear':
        clearCache($config);
        break;
    case 'recalc-giftaid':
        $args = parseArgs($argv);
        $charityId = isset($args['charity']) ? (int) $args['charity'] : null;
        recalcGiftAid($db, $logger, $charityId);
        break;
    case 'stats':
        showStats($db);
        break;
    default:
        echo "Unknown command: $command\n";
        showHelp();
        exit(1);
}

function showHelp(): void
{
    echo <<<HELP
Charity Cashbook Maintenance CLI

Usage: php maintenance.php <command> [options]

Commands:
  clean-sessions
      Remove expired sessions from the database
      
  clean-tokens
      Remove expired pending identity links
      
  clean-logs --older-than=<N>years
      Remove audit logs older than N years (default: 7)
      
  cache-clear
      Clear all application caches (Twig templates, etc.)
      
  recalc-giftaid [--charity=<id>]
      Recalculate Gift Aid eligibility for all transactions
      Optionally specify a charity ID to process only that charity
      
  stats
      Show system statistics

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
        }
    }
    return $args;
}

function cleanSessions(Database $db, Logger $logger): void
{
    echo "Cleaning expired sessions...\n";
    
    $count = $db->execute("DELETE FROM sessions WHERE expires_at < NOW()");
    
    echo "Removed $count expired sessions.\n";
    $logger->info("Maintenance: cleaned $count expired sessions");
}

function cleanTokens(Database $db, Logger $logger): void
{
    echo "Cleaning expired pending identity links...\n";
    
    $count = $db->execute("DELETE FROM pending_identity_links WHERE expires_at < NOW()");
    
    echo "Removed $count expired pending links.\n";
    $logger->info("Maintenance: cleaned $count expired pending links");
}

function clearCache(array $config): void
{
    echo "Clearing application caches...\n";
    
    $cacheDir = $config['paths']['cache'];
    $cleared = 0;
    
    // Clear Twig cache
    $twigCache = $cacheDir . '/twig';
    if (is_dir($twigCache)) {
        $count = clearDirectory($twigCache);
        echo "  Twig cache: $count files removed\n";
        $cleared += $count;
    } else {
        echo "  Twig cache: not found\n";
    }
    
    // Add other caches here as needed
    
    echo "\nTotal: $cleared files cleared.\n";
}

function clearDirectory(string $dir): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
            $count++;
        }
    }
    
    return $count;
}

function cleanLogs(Database $db, Logger $logger, int $years): void
{
    echo "Cleaning logs older than $years years...\n";
    
    $cutoffDate = date('Y-m-d', strtotime("-$years years"));
    $totalCount = 0;
    
    // Clean audit_log
    $count = $db->execute(
        "DELETE FROM audit_log WHERE created_at < ?",
        [$cutoffDate]
    );
    echo "Removed $count audit log entries.\n";
    $totalCount += $count;
    
    // Clean login_history
    $count = $db->execute(
        "DELETE FROM login_history WHERE created_at < ?",
        [$cutoffDate]
    );
    echo "Removed $count login history entries.\n";
    $totalCount += $count;
    
    // Clean donor_access_log
    $count = $db->execute(
        "DELETE FROM donor_access_log WHERE created_at < ?",
        [$cutoffDate]
    );
    echo "Removed $count donor access log entries.\n";
    $totalCount += $count;
    
    // Clean transaction_audit (but keep recent)
    $count = $db->execute(
        "DELETE FROM transaction_audit WHERE created_at < ?",
        [$cutoffDate]
    );
    echo "Removed $count transaction audit entries.\n";
    $totalCount += $count;
    
    echo "\nTotal: $totalCount log entries removed.\n";
    $logger->info("Maintenance: cleaned $totalCount log entries older than $years years");
}

function recalcGiftAid(Database $db, Logger $logger, ?int $charityId): void
{
    $charityFilter = $charityId ? " AND t.charity_id = $charityId" : '';
    $charityText = $charityId ? "charity $charityId" : 'all charities';
    
    echo "Recalculating Gift Aid eligibility for $charityText...\n";
    
    // Get all transactions that might need recalculation
    $transactions = $db->query(
        "SELECT t.id, t.charity_id, t.transaction_date, t.donor_id, t.has_oneoff_declaration,
                t.giftaid_status, oc.giftaid_eligible_default, rc.category_type
         FROM transactions t
         JOIN operational_categories oc ON oc.id = t.operational_category_id
         JOIN reporting_categories rc ON rc.id = oc.reporting_category_id
         WHERE t.is_deleted = FALSE 
         AND t.giftaid_status NOT IN ('claimed', 'gasds')
         $charityFilter"
    );
    
    $updated = 0;
    
    foreach ($transactions as $t) {
        $newStatus = calculateGiftAidStatus($db, $t);
        
        if ($newStatus !== $t['giftaid_status']) {
            $db->execute(
                "UPDATE transactions SET giftaid_status = ? WHERE id = ?",
                [$newStatus, $t['id']]
            );
            $updated++;
        }
    }
    
    echo "Processed " . count($transactions) . " transactions.\n";
    echo "Updated $updated transaction(s).\n";
    $logger->info("Maintenance: recalculated Gift Aid for " . count($transactions) . " transactions, updated $updated");
}

function calculateGiftAidStatus(Database $db, array $transaction): string
{
    // If payment, not applicable
    if ($transaction['category_type'] === 'payment') {
        return 'not_applicable';
    }
    
    // If has one-off declaration, eligible
    if ($transaction['has_oneoff_declaration']) {
        return 'eligible';
    }
    
    // If no donor and category doesn't suggest Gift Aid
    if (!$transaction['donor_id']) {
        return $transaction['giftaid_eligible_default'] ? 'not_eligible' : 'not_applicable';
    }
    
    // Check for valid declaration
    $declaration = $db->queryOne(
        "SELECT id FROM declarations 
         WHERE donor_id = ? 
         AND start_date <= ?
         AND (withdrawn_date IS NULL OR withdrawn_date >= ?)",
        [$transaction['donor_id'], $transaction['transaction_date'], $transaction['transaction_date']]
    );
    
    return $declaration ? 'eligible' : 'not_eligible';
}

function showStats(Database $db): void
{
    echo "System Statistics\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // Charities
    $charities = $db->queryOne(
        "SELECT COUNT(*) as total, 
                SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active
         FROM charities"
    );
    echo "Charities:\n";
    echo "  Total: {$charities['total']}\n";
    echo "  Active: {$charities['active']}\n\n";
    
    // Users
    $users = $db->queryOne(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_super_admin THEN 1 ELSE 0 END) as admins
         FROM users"
    );
    echo "Users:\n";
    echo "  Total: {$users['total']}\n";
    echo "  Active: {$users['active']}\n";
    echo "  Super-admins: {$users['admins']}\n\n";
    
    // Sessions
    $sessions = $db->queryOne(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active
         FROM sessions"
    );
    echo "Sessions:\n";
    echo "  Total: {$sessions['total']}\n";
    echo "  Active: {$sessions['active']}\n\n";
    
    // Transactions
    $transactions = $db->queryOne(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN transaction_type = 'receipt' THEN 1 ELSE 0 END) as receipts,
                SUM(CASE WHEN transaction_type = 'payment' THEN 1 ELSE 0 END) as payments
         FROM transactions WHERE is_deleted = FALSE"
    );
    echo "Transactions:\n";
    echo "  Total: {$transactions['total']}\n";
    echo "  Receipts: {$transactions['receipts']}\n";
    echo "  Payments: {$transactions['payments']}\n\n";
    
    // Gift Aid
    $giftaid = $db->query(
        "SELECT giftaid_status, COUNT(*) as count
         FROM transactions
         WHERE is_deleted = FALSE AND transaction_type = 'receipt'
         GROUP BY giftaid_status"
    );
    echo "Gift Aid Status:\n";
    foreach ($giftaid as $row) {
        echo "  {$row['giftaid_status']}: {$row['count']}\n";
    }
    echo "\n";
    
    // Database size
    $size = $db->queryOne(
        "SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
         FROM information_schema.tables 
         WHERE table_schema = DATABASE()"
    );
    echo "Database Size: {$size['size_mb']} MB\n";
}
