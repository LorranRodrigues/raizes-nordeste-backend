<?php

declare(strict_types=1);



$env = static fn (string $key, ?string $default = null): ?string =>
    getenv($key) !== false ? getenv($key) : $default;

return [
    'app' => [
        'name' => 'API Raízes do Nordeste',
        'env' => $env('APP_ENV', 'local'),
        'debug' => filter_var($env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOL),
        'timezone' => 'America/Recife',
        'base_path' => $env('APP_BASE_PATH', '/lorran/apiBackendUninter/public'),
    ],

    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '3306'),
        'database' => $env('DB_NAME', 'raizes_nordeste'),
        'username' => $env('DB_USER', 'root'),
        'password' => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'auth' => [
        'secret' => $env('AUTH_SECRET', 'troque-este-segredo-em-producao'),
        'token_ttl' => 60 * 60 * 8,
    ],

    'payment' => [
        //fiz um gateway simulado
        'gateway_url' => $env('PAYMENT_GATEWAY_URL', 'http://gateway.simulado.local'),
        'webhook_secret' => $env('PAYMENT_WEBHOOK_SECRET', 'webhook-secret-simulado'),
    ],
];
