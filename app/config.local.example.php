<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'rame_it',
        'user' => 'root',
        'password' => '',
    ],
    'app_url' => 'http://localhost/Rame/public',
    'timezone' => 'America/Mexico_City',
    'encryption_key' => 'PEGA_AQUI_LA_CLAVE_BASE64_DE_32_BYTES',
    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Rame TI',
        'encryption' => 'tls',
    ],
];