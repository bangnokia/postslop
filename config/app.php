<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Postslap'),

    // Show the details of a failure to the client. Never in production.
    'debug' => env('APP_DEBUG', false),

    // Where the application is reached; the start of every URL Urls writes for a named route.
    'url' => env('APP_URL', 'http://127.0.0.1:8087'),
];
