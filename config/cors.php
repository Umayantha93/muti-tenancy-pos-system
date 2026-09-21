<?php

$frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        $frontend,
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ]))),
    'allowed_origins_patterns' => [
        '/^http:\/\/192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$/',
        '/^http:\/\/10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$/',
        '/^http:\/\/172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}(:\d+)?$/',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 60 * 60,
    'supports_credentials' => false,
];
