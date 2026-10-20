<?php

declare(strict_types=1);

return [
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8087'),

    // Worker processes. More than one needs PHP's pcntl and posix extensions.
    'workers' => env('SERVER_WORKERS', 1),

    // Servers `serve` runs beside HTTP, each switched on from .env. Off while you develop, when you
    // start `queue:work` or `schedule:work` only as you need them; on in production, where one command
    // and one reload then cover everything.
    'with' => [
        'queue' => env('SERVER_QUEUE', false),
        'schedule' => env('SERVER_SCHEDULE', false),
    ],

    // What `php cua serve` watches, to restart the server when you save. False to serve without watching.
    'watch' => ['app', 'app.php', 'config', 'routes', 'public', '.env'],

    // The largest request body taken, in bytes, and how many seconds a client has to send one. Raise both for uploads.
    'max_body' => 7 * 1024 * 1024,
    'body_timeout' => 30,

    // Seconds to finish in-flight requests on stop or reload; connections still open at the limit are cut.
    'drain' => 5.0,

    // Where `php cua serve --daemon` writes its process id and everything it prints.
    'pid' => 'storage/crab.pid',
    'log' => 'storage/logs/server.log',
];
