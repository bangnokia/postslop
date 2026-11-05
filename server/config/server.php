<?php

declare(strict_types=1);

return [
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8087'),

    // Worker processes. More than one needs PHP's pcntl and posix extensions.
    'workers' => env('SERVER_WORKERS', 1),

    // What `php cua dev` watches to restart the server when you save. `start` never watches.
    'watch' => ['app', 'app.php', 'config', 'routes', 'public', '.env'],

    // The largest request body taken, in bytes, and how many seconds a client has to send one. Raise both for uploads.
    // A 20 MiB video arrives as about 27 MiB of base64 inside the post's JSON.
    'max_body' => 28 * 1024 * 1024,
    'body_timeout' => 30,

    // Seconds to finish in-flight requests on stop or reload; connections still open at the limit are cut.
    'drain' => 5.0,

    // Where `php cua start --daemon` writes its process id and everything it prints.
    'pid' => 'storage/crab.pid',
    'log' => 'storage/logs/server.log',
];
