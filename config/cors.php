<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_merge(
        [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://10.189.98.168:5173',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://10.189.98.168:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3001',
            'http://10.189.98.168:3001',
        ],
        array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
    ))),
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.trycloudflare\.com$#',
        '#^https://(www\.)?hoc\.agency$#',
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^http://10\.\d+\.\d+\.\d+:(3000|3001|5173|5174|5175)$#',
        '#^https?://(\d{1,3}\.){3}\d{1,3}(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
