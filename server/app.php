<?php

declare(strict_types=1);

use App\Http\CoreFailures;
use App\Http\TokenAccess;
use App\Providers\AppProvider;
use Crab\App;

// Describes the application run by `php cua`.
// The .env file, config folder and framework providers are loaded by default.

return App::configure(__DIR__)
    ->withProviders([
        AppProvider::class,
    ])
    ->withMiddleware(append: [CoreFailures::class], prepend: [TokenAccess::class])
    ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
    ->create();
