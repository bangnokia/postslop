<?php

declare(strict_types=1);

return [
    // A stream such as php://stderr, or a file; a relative path such as storage/logs/app.log is inside the application.
    'path' => env('LOG_PATH', 'php://stderr'),

    // The least severe level written: debug, info, notice, warning, error, critical, alert or emergency.
    'level' => env('LOG_LEVEL', 'debug'),
];
