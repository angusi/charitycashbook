#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * OAuth Provider Setup Tool
 * 
 * Use this to configure OAuth providers for login.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;
use CharityCashbook\Core\Encryption;

$config = require __DIR__ . '/../config/config.php';

// Check encryption key is set
if (empty($config['encryption']['key'])) {
    echo "ERROR: ENCRYPTION_KEY not set in environment.\n";
    echo "Generate one with: php -r \"echo base64_encode(random_bytes(32)) . PHP_EOL;\"\n";
    exit(1);
}

$db = new Database($config['db']);
$encryption = new Encryption($config['encryption']['key']);

if ($argc < 2) {
    showHelp();
    exit(1);
}

$command = $argv[1];

// Handle help flag
if ($command === '--help' || $command === '-h' || in_array('--help', $argv) || in_array('-h', $argv)) {
    showHelp();
    exit(0);
}

switch ($command) {
    case 'add-google':
        addGoogle($db, $encryption, $argv, $config);
        break;
    case 'add-microsoft':
        addMicrosoft($db, $encryption, $argv, $config);
        break;
    case 'list':
        listProviders($db, $config);
        break;
    case 'enable':
        toggleProvider($db, $argv, true);
        break;
    case 'disable':
        toggleProvider($db, $argv, false);
        break;
    case 'delete':
        deleteProvider($db, $argv);
        break;
    default:
        echo "Unknown command: $command\n\n";
        showHelp();
        exit(1);
}

function showHelp(): void
{
    echo <<<HELP
OAuth Provider Setup Tool

Usage: php setup-oauth.php <command> [options]

Commands:
  add-google --client-id=<id> --client-secret=<secret> [--name=<name>]
      Add a Google OAuth provider
      
  add-microsoft --client-id=<id> --client-secret=<secret> --tenant-id=<tenant> [--name=<name>]
      Add a Microsoft/Azure AD OAuth provider
      
  list
      List all configured OAuth providers
      
  enable --id=<provider_id>
      Enable a provider
      
  disable --id=<provider_id>
      Disable a provider
      
  delete --id=<provider_id>
      Delete a provider (cannot delete if users are linked)

Setup Instructions:

GOOGLE:
  1. Go to https://console.cloud.google.com/
  2. Create a new project (or select existing)
  3. Go to APIs & Services > Credentials
  4. Create OAuth 2.0 Client ID (Web application)
  5. Add authorized redirect URI (you'll get the exact URL after running add-google)
  6. Copy Client ID and Client Secret
  7. Run: php setup-oauth.php add-google --client-id=xxx --client-secret=xxx
  8. Note the Provider ID shown, then set redirect URI to:
     https://yourdomain.com/oauth/callback/<PROVIDER_ID>

MICROSOFT (Azure AD):
  1. Go to https://portal.azure.com/
  2. Navigate to Azure Active Directory > App registrations
  3. New registration
  4. Add redirect URI (you'll get the exact URL after running add-microsoft)
  5. Go to Certificates & secrets > New client secret
  6. Copy Application (client) ID, Directory (tenant) ID, and client secret value
  7. Run: php setup-oauth.php add-microsoft --client-id=xxx --client-secret=xxx --tenant-id=xxx
  8. Note the Provider ID shown, then set redirect URI to:
     https://yourdomain.com/oauth/callback/<PROVIDER_ID>

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

function addGoogle(Database $db, Encryption $encryption, array $argv, array $config): void
{
    $args = parseArgs($argv);
    
    $clientId = $args['client-id'] ?? null;
    $clientSecret = $args['client-secret'] ?? null;
    $name = $args['name'] ?? 'Google';
    
    if (!$clientId || !$clientSecret) {
        echo "Error: --client-id and --client-secret are required\n";
        exit(1);
    }
    
    // Check if Google provider already exists
    $existing = $db->queryOne(
        "SELECT id FROM oauth_providers WHERE provider_type = 'google'"
    );
    
    if ($existing) {
        echo "Error: Google provider already exists (ID: {$existing['id']})\n";
        echo "Use 'delete --id={$existing['id']}' first if you want to replace it.\n";
        exit(1);
    }
    
    $encryptedSecret = $encryption->encrypt($clientSecret);
    
    $id = $db->insert('oauth_providers', [
        'provider_type' => 'google',
        'name' => $name,
        'client_id' => $clientId,
        'client_secret_encrypted' => $encryptedSecret,
        'is_enabled' => true,
    ]);
    
    echo "Google OAuth provider added successfully!\n";
    echo "Provider ID: $id\n";
    echo "Name: $name\n";
    $appUrl = rtrim($config['app']['url'], '/');
    echo "\n*** IMPORTANT: Set this exact redirect URI in Google Cloud Console:\n";
    echo "    {$appUrl}/oauth/callback/$id\n";
}

function addMicrosoft(Database $db, Encryption $encryption, array $argv, array $config): void
{
    $args = parseArgs($argv);
    
    $clientId = $args['client-id'] ?? null;
    $clientSecret = $args['client-secret'] ?? null;
    $tenantId = $args['tenant-id'] ?? null;
    $name = $args['name'] ?? 'Microsoft';
    
    if (!$clientId || !$clientSecret || !$tenantId) {
        echo "Error: --client-id, --client-secret, and --tenant-id are required\n";
        exit(1);
    }
    
    $encryptedSecret = $encryption->encrypt($clientSecret);
    
    $id = $db->insert('oauth_providers', [
        'provider_type' => 'microsoft',
        'name' => $name,
        'client_id' => $clientId,
        'client_secret_encrypted' => $encryptedSecret,
        'tenant_id' => $tenantId,
        'is_enabled' => true,
    ]);
    
    echo "Microsoft OAuth provider added successfully!\n";
    echo "Provider ID: $id\n";
    echo "Name: $name\n";
    echo "Tenant ID: $tenantId\n";
    $appUrl = rtrim($config['app']['url'], '/');
    echo "\n*** IMPORTANT: Set this exact redirect URI in Azure Portal:\n";
    echo "    {$appUrl}/oauth/callback/$id\n";
}

function listProviders(Database $db, array $config): void
{
    $providers = $db->query(
        "SELECT id, provider_type, name, client_id, tenant_id, is_enabled, created_at 
         FROM oauth_providers ORDER BY id"
    );
    
    if (empty($providers)) {
        echo "No OAuth providers configured.\n";
        echo "Run 'php setup-oauth.php add-google --help' for setup instructions.\n";
        return;
    }
    
    echo "Configured OAuth Providers:\n";
    echo str_repeat('-', 90) . "\n";
    printf("%-4s %-12s %-20s %-30s %-8s\n", "ID", "Type", "Name", "Client ID", "Enabled");
    echo str_repeat('-', 90) . "\n";
    
    foreach ($providers as $p) {
        printf(
            "%-4d %-12s %-20s %-30s %-8s\n",
            $p['id'],
            $p['provider_type'],
            substr($p['name'], 0, 20),
            substr($p['client_id'], 0, 30),
            $p['is_enabled'] ? 'Yes' : 'No'
        );
    }
    
    echo "\nCallback URLs for your OAuth provider settings:\n";
    $appUrl = rtrim($config['app']['url'] ?? 'https://yourdomain.com', '/');
    foreach ($providers as $p) {
        echo "  {$p['name']}: {$appUrl}/oauth/callback/{$p['id']}\n";
    }
}

function toggleProvider(Database $db, array $argv, bool $enable): void
{
    $args = parseArgs($argv);
    $id = $args['id'] ?? null;
    
    if (!$id) {
        echo "Error: --id=<provider_id> is required\n";
        exit(1);
    }
    
    $provider = $db->queryOne("SELECT name FROM oauth_providers WHERE id = ?", [$id]);
    if (!$provider) {
        echo "Error: Provider not found: $id\n";
        exit(1);
    }
    
    $db->execute(
        "UPDATE oauth_providers SET is_enabled = ? WHERE id = ?",
        [$enable, $id]
    );
    
    $status = $enable ? 'enabled' : 'disabled';
    echo "Provider '{$provider['name']}' has been $status.\n";
}

function deleteProvider(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $id = $args['id'] ?? null;
    
    if (!$id) {
        echo "Error: --id=<provider_id> is required\n";
        exit(1);
    }
    
    $provider = $db->queryOne("SELECT name FROM oauth_providers WHERE id = ?", [$id]);
    if (!$provider) {
        echo "Error: Provider not found: $id\n";
        exit(1);
    }
    
    // Check for linked identities
    $linkedCount = $db->queryValue(
        "SELECT COUNT(*) FROM user_identities WHERE oauth_provider_id = ?",
        [$id]
    );
    
    if ($linkedCount > 0) {
        echo "Error: Cannot delete provider - $linkedCount user(s) have linked identities.\n";
        echo "You must first remove or migrate these users.\n";
        exit(1);
    }
    
    $db->execute("DELETE FROM oauth_providers WHERE id = ?", [$id]);
    echo "Provider '{$provider['name']}' has been deleted.\n";
}
