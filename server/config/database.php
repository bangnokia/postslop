<?php

declare(strict_types=1);

return [
    // The first connection is the default; its migrations live in database/migrations.
    'connections' => [
        'main' => [
            'driver' => env('DB_DRIVER', 'sqlite'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', dirname(__DIR__) . '/storage/database.sqlite'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),

            // Keep existing installations and test environments that override the connection with a DSN.
            'dsn' => env('DB_DSN'),
        ],
    ],
];
