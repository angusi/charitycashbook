<?php

/**
 * Example configuration file
 * Copy this to config.php and fill in your values
 */

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 3306,
        'database' => getenv('DB_NAME') ?: 'charity_cashbook',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Charity Cashbook',
        'url' => getenv('APP_URL') ?: 'http://localhost',
        'debug' => filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN),
        'timezone' => 'Europe/London',
    ],
    'session' => [
        'name' => 'charity_cashbook_session',
        'lifetime_days' => 30,
    ],
    'encryption' => [
        'key' => getenv('ENCRYPTION_KEY') ?: '', // Generate with: base64_encode(random_bytes(32))
    ],
    'mail' => [
        'from_address' => getenv('MAIL_FROM') ?: 'noreply@example.org',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Charity Cashbook',
    ],
    'paths' => [
        'logs' => __DIR__ . '/../logs',
        'cache' => __DIR__ . '/../cache',
        'views' => __DIR__ . '/../src/Views',
    ],
];
