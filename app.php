<?php

declare(strict_types=1);

use App\Http\LocalAccess;
use App\Providers\AppProvider;
use Crab\App;

// Describes the application run by `php cua`.
// The .env file, config folder and framework providers are loaded by default.

return App::configure(__DIR__)
    ->withProviders([
        AppProvider::class,
    ])
    ->withMiddleware(prepend: [LocalAccess::class])
    ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
    ->create();
