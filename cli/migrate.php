<?php

declare(strict_types=1);

/**
 * Migration runner CLI tool
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config['db']);

$command = $argv[1] ?? 'status';

switch ($command) {
    case 'up':
        runMigrations($db);
        break;
    case 'down':
        rollbackMigration($db);
        break;
    case 'status':
        showStatus($db);
        break;
    case 'create':
        $name = $argv[2] ?? null;
        if (!$name) {
            echo "Usage: php migrate.php create <migration_name>\n";
            exit(1);
        }
        createMigration($name);
        break;
    default:
        echo "Unknown command: $command\n";
        echo "Available commands: up, down, status, create\n";
        exit(1);
}

function ensureMigrationsTable(Database $db): void
{
    $db->execute("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT UNSIGNED NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_migration (migration)
        )
    ");
}

function getExecutedMigrations(Database $db): array
{
    ensureMigrationsTable($db);
    $rows = $db->query("SELECT migration FROM migrations ORDER BY id");
    return array_column($rows, 'migration');
}

function getMigrationFiles(): array
{
    $files = glob(__DIR__ . '/../migrations/*.php');
    $migrations = [];
    foreach ($files as $file) {
        $name = basename($file, '.php');
        $migrations[$name] = $file;
    }
    ksort($migrations);
    return $migrations;
}

function runMigrations(Database $db): void
{
    $executed = getExecutedMigrations($db);
    $files = getMigrationFiles();
    $pending = array_diff_key($files, array_flip($executed));

    if (empty($pending)) {
        echo "Nothing to migrate.\n";
        return;
    }

    $batch = (int) $db->queryValue("SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations");

    foreach ($pending as $name => $file) {
        echo "Migrating: $name\n";
        
        $migration = require $file;
        
        try {
            // Note: DDL statements (CREATE TABLE, etc.) cause implicit commits in MySQL/MariaDB
            // so transactions don't protect against partial DDL failures
            $migration['up']($db);
            $db->execute(
                "INSERT INTO migrations (migration, batch) VALUES (?, ?)",
                [$name, $batch]
            );
            echo "Migrated:  $name\n";
        } catch (\Exception $e) {
            // Rollback only if a transaction is still active
            if ($db->inTransaction()) {
                $db->rollback();
            }
            echo "Failed:    $name - " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    echo "Migration complete.\n";
}

function rollbackMigration(Database $db): void
{
    $lastBatch = (int) $db->queryValue("SELECT MAX(batch) FROM migrations");
    
    if ($lastBatch === 0) {
        echo "Nothing to rollback.\n";
        return;
    }

    $migrations = $db->query(
        "SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC",
        [$lastBatch]
    );

    $files = getMigrationFiles();

    foreach ($migrations as $row) {
        $name = $row['migration'];
        $file = $files[$name] ?? null;

        if (!$file || !file_exists($file)) {
            echo "Migration file not found: $name\n";
            continue;
        }

        echo "Rolling back: $name\n";
        
        $migration = require $file;
        
        try {
            // Note: DDL statements cause implicit commits in MySQL/MariaDB
            if (isset($migration['down'])) {
                $migration['down']($db);
            }
            $db->execute("DELETE FROM migrations WHERE migration = ?", [$name]);
            echo "Rolled back: $name\n";
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            echo "Failed:       $name - " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    echo "Rollback complete.\n";
}

function showStatus(Database $db): void
{
    $executed = getExecutedMigrations($db);
    $files = getMigrationFiles();

    echo "Migration Status:\n";
    echo str_repeat('-', 60) . "\n";

    foreach ($files as $name => $file) {
        $status = in_array($name, $executed) ? '[✓]' : '[ ]';
        echo "$status $name\n";
    }

    $pending = count($files) - count($executed);
    echo str_repeat('-', 60) . "\n";
    echo "Total: " . count($files) . " migrations, $pending pending\n";
}

function createMigration(string $name): void
{
    $timestamp = date('Y_m_d_His');
    $filename = $timestamp . '_' . $name . '.php';
    $path = __DIR__ . '/../migrations/' . $filename;

    $template = <<<'PHP'
<?php

declare(strict_types=1);

use CharityCashbook\Core\Database;

return [
    'up' => function (Database $db): void {
        // Migration code here
    },
    
    'down' => function (Database $db): void {
        // Rollback code here
    },
];
PHP;

    file_put_contents($path, $template);
    echo "Created migration: $filename\n";
}
