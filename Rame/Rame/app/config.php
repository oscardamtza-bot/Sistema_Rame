<?php
declare(strict_types=1);

$config = [
    'db' => [
        'host' => getenv('RAME_DB_HOST') ?: '127.0.0.1',
        'name' => getenv('RAME_DB_NAME') ?: 'rame_it',
        'user' => getenv('RAME_DB_USER') ?: 'root',
        'password' => getenv('RAME_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app_url' => rtrim(getenv('RAME_APP_URL') ?: 'http://localhost/Rame/public', '/'),
    'timezone' => getenv('RAME_TIMEZONE') ?: 'America/Mexico_City',
    'encryption_key' => getenv('RAME_ENCRYPTION_KEY') ?: '',
    'smtp' => [
        'host' => getenv('RAME_SMTP_HOST') ?: '',
        'port' => (int) (getenv('RAME_SMTP_PORT') ?: 587),
        'username' => getenv('RAME_SMTP_USERNAME') ?: '',
        'password' => getenv('RAME_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('RAME_SMTP_FROM_EMAIL') ?: '',
        'from_name' => getenv('RAME_SMTP_FROM_NAME') ?: 'Rame TI',
        'encryption' => getenv('RAME_SMTP_ENCRYPTION') ?: 'tls',
    ],
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;