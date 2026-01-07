<?php

declare(strict_types=1);

/**
 * Admin CLI tool for recovery operations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CharityCashbook\Core\Database;

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config['db']);

if ($argc < 2) {
    showHelp();
    exit(1);
}

$command = $argv[1];

switch ($command) {
    case 'promote-superadmin':
        promoteSuperAdmin($db, $argv);
        break;
    case 'demote-superadmin':
        demoteSuperAdmin($db, $argv);
        break;
    case 'list-superadmins':
        listSuperAdmins($db);
        break;
    case 'add-oauth':
        addOAuth($db, $argv);
        break;
    case 'verify-link':
        verifyLink($db, $argv);
        break;
    case 'create-user':
        createUser($db, $argv);
        break;
    case 'list-users':
        listUsers($db);
        break;
    default:
        echo "Unknown command: $command\n";
        showHelp();
        exit(1);
}

function showHelp(): void
{
    echo <<<HELP
Charity Cashbook Admin CLI

Usage: php admin.php <command> [options]

Commands:
  promote-superadmin --user=<email>
      Promote a user to super-admin status
      
  demote-superadmin --user=<email>
      Remove super-admin status from a user
      
  list-superadmins
      List all super-admin users
      
  add-oauth --user=<email> --provider=<type> --oauth-email=<email>
      Add an OAuth identity to an existing user
      Provider types: google, microsoft, oidc
      
  verify-link --user=<email> --oauth-email=<email>
      Verify a pending identity link (bypass email confirmation)
      
  create-user --email=<email> --name=<name> [--super-admin]
      Create a new user account
      
  list-users
      List all users

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

function promoteSuperAdmin(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $email = $args['user'] ?? null;
    
    if (!$email) {
        echo "Error: --user=<email> is required\n";
        exit(1);
    }
    
    $user = $db->queryOne(
        "SELECT id, display_name, is_super_admin FROM users WHERE primary_email = ?",
        [$email]
    );
    
    if (!$user) {
        echo "Error: User not found: $email\n";
        exit(1);
    }
    
    if ($user['is_super_admin']) {
        echo "User is already a super-admin.\n";
        exit(0);
    }
    
    $db->execute("UPDATE users SET is_super_admin = TRUE WHERE id = ?", [$user['id']]);
    
    // Log the action
    $db->insert('audit_log', [
        'user_id' => null,
        'action' => 'cli.promote_superadmin',
        'entity_type' => 'user',
        'entity_id' => $user['id'],
        'details' => json_encode(['email' => $email, 'cli' => true]),
    ]);
    
    echo "User {$user['display_name']} ($email) has been promoted to super-admin.\n";
}

function demoteSuperAdmin(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $email = $args['user'] ?? null;
    
    if (!$email) {
        echo "Error: --user=<email> is required\n";
        exit(1);
    }
    
    $user = $db->queryOne(
        "SELECT id, display_name, is_super_admin FROM users WHERE primary_email = ?",
        [$email]
    );
    
    if (!$user) {
        echo "Error: User not found: $email\n";
        exit(1);
    }
    
    if (!$user['is_super_admin']) {
        echo "User is not a super-admin.\n";
        exit(0);
    }
    
    // Check there's at least one other super-admin
    $otherAdmins = $db->queryValue(
        "SELECT COUNT(*) FROM users WHERE is_super_admin = TRUE AND id != ?",
        [$user['id']]
    );
    
    if ($otherAdmins == 0) {
        echo "Error: Cannot remove the last super-admin.\n";
        exit(1);
    }
    
    $db->execute("UPDATE users SET is_super_admin = FALSE WHERE id = ?", [$user['id']]);
    
    $db->insert('audit_log', [
        'user_id' => null,
        'action' => 'cli.demote_superadmin',
        'entity_type' => 'user',
        'entity_id' => $user['id'],
        'details' => json_encode(['email' => $email, 'cli' => true]),
    ]);
    
    echo "User {$user['display_name']} ($email) is no longer a super-admin.\n";
}

function listSuperAdmins(Database $db): void
{
    $admins = $db->query(
        "SELECT id, display_name, primary_email, created_at 
         FROM users WHERE is_super_admin = TRUE ORDER BY display_name"
    );
    
    if (empty($admins)) {
        echo "No super-admins found.\n";
        return;
    }
    
    echo "Super-Admins:\n";
    echo str_repeat('-', 70) . "\n";
    printf("%-5s %-25s %-30s %s\n", "ID", "Name", "Email", "Created");
    echo str_repeat('-', 70) . "\n";
    
    foreach ($admins as $admin) {
        printf(
            "%-5d %-25s %-30s %s\n",
            $admin['id'],
            substr($admin['display_name'], 0, 25),
            substr($admin['primary_email'], 0, 30),
            substr($admin['created_at'], 0, 10)
        );
    }
}

function addOAuth(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $email = $args['user'] ?? null;
    $providerType = $args['provider'] ?? null;
    $oauthEmail = $args['oauth-email'] ?? null;
    
    if (!$email || !$providerType || !$oauthEmail) {
        echo "Error: --user, --provider, and --oauth-email are required\n";
        exit(1);
    }
    
    $user = $db->queryOne(
        "SELECT id, display_name FROM users WHERE primary_email = ?",
        [$email]
    );
    
    if (!$user) {
        echo "Error: User not found: $email\n";
        exit(1);
    }
    
    $provider = $db->queryOne(
        "SELECT id FROM oauth_providers WHERE provider_type = ? AND is_enabled = TRUE LIMIT 1",
        [$providerType]
    );
    
    if (!$provider) {
        echo "Error: No enabled provider found for type: $providerType\n";
        exit(1);
    }
    
    // Create identity with a placeholder subject
    $oauthSubject = 'cli_' . bin2hex(random_bytes(16));
    
    $db->insert('user_identities', [
        'user_id' => $user['id'],
        'oauth_provider_id' => $provider['id'],
        'oauth_subject' => $oauthSubject,
        'oauth_email' => $oauthEmail,
        'is_verified' => true,
        'verified_at' => date('Y-m-d H:i:s'),
    ]);
    
    $db->insert('audit_log', [
        'user_id' => null,
        'action' => 'cli.add_oauth',
        'entity_type' => 'user',
        'entity_id' => $user['id'],
        'details' => json_encode([
            'oauth_email' => $oauthEmail,
            'provider' => $providerType,
            'cli' => true,
        ]),
    ]);
    
    echo "OAuth identity added: $oauthEmail -> {$user['display_name']}\n";
    echo "Note: The subject ID is a placeholder. The real ID will be set on first login.\n";
}

function verifyLink(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $email = $args['user'] ?? null;
    $oauthEmail = $args['oauth-email'] ?? null;
    
    if (!$email || !$oauthEmail) {
        echo "Error: --user and --oauth-email are required\n";
        exit(1);
    }
    
    $pending = $db->queryOne(
        "SELECT pil.* FROM pending_identity_links pil
         JOIN users u ON u.id = pil.existing_user_id
         WHERE u.primary_email = ? AND pil.new_oauth_email = ?",
        [$email, $oauthEmail]
    );
    
    if (!$pending) {
        echo "Error: No pending link found for this combination.\n";
        exit(1);
    }
    
    // Create the identity
    $db->insert('user_identities', [
        'user_id' => $pending['existing_user_id'],
        'oauth_provider_id' => $pending['new_oauth_provider_id'],
        'oauth_subject' => $pending['new_oauth_subject'],
        'oauth_email' => $pending['new_oauth_email'],
        'is_verified' => true,
        'verified_at' => date('Y-m-d H:i:s'),
    ]);
    
    // Delete pending link
    $db->execute("DELETE FROM pending_identity_links WHERE id = ?", [$pending['id']]);
    
    $db->insert('audit_log', [
        'user_id' => null,
        'action' => 'cli.verify_link',
        'entity_type' => 'user',
        'entity_id' => $pending['existing_user_id'],
        'details' => json_encode([
            'oauth_email' => $oauthEmail,
            'cli' => true,
        ]),
    ]);
    
    echo "Identity link verified: $oauthEmail -> $email\n";
}

function createUser(Database $db, array $argv): void
{
    $args = parseArgs($argv);
    $email = $args['email'] ?? null;
    $name = $args['name'] ?? null;
    $superAdmin = isset($args['super-admin']);
    
    if (!$email || !$name) {
        echo "Error: --email and --name are required\n";
        exit(1);
    }
    
    // Check if user already exists
    $existing = $db->queryOne(
        "SELECT id FROM users WHERE primary_email = ?",
        [$email]
    );
    
    if ($existing) {
        echo "Error: User already exists with email: $email\n";
        exit(1);
    }
    
    $userId = $db->insert('users', [
        'display_name' => $name,
        'primary_email' => $email,
        'is_super_admin' => $superAdmin,
        'is_active' => true,
    ]);
    
    $db->insert('audit_log', [
        'user_id' => null,
        'action' => 'cli.create_user',
        'entity_type' => 'user',
        'entity_id' => $userId,
        'details' => json_encode([
            'email' => $email,
            'super_admin' => $superAdmin,
            'cli' => true,
        ]),
    ]);
    
    echo "User created: $name ($email)\n";
    if ($superAdmin) {
        echo "User is a super-admin.\n";
    }
    echo "Note: User will need to authenticate via OAuth to complete setup.\n";
}

function listUsers(Database $db): void
{
    $users = $db->query(
        "SELECT id, display_name, primary_email, is_super_admin, is_active, created_at 
         FROM users ORDER BY display_name"
    );
    
    if (empty($users)) {
        echo "No users found.\n";
        return;
    }
    
    echo "Users:\n";
    echo str_repeat('-', 90) . "\n";
    printf("%-5s %-25s %-35s %-7s %-7s %s\n", "ID", "Name", "Email", "Admin", "Active", "Created");
    echo str_repeat('-', 90) . "\n";
    
    foreach ($users as $user) {
        printf(
            "%-5d %-25s %-35s %-7s %-7s %s\n",
            $user['id'],
            substr($user['display_name'], 0, 25),
            substr($user['primary_email'], 0, 35),
            $user['is_super_admin'] ? 'Yes' : 'No',
            $user['is_active'] ? 'Yes' : 'No',
            substr($user['created_at'], 0, 10)
        );
    }
}
