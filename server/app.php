<?php

declare(strict_types=1);

use App\Http\CoreFailures;
use App\Http\TokenAccess;
use App\Setup;
use Crab\App;
use Crab\Queue\QueuePlugin;
use Crab\Schedule\SchedulePlugin;

// Describes the application run by `php cua`.
// The .env file, config folder and core plugins are loaded by default; `start` runs the queue's workers and
// the scheduler beside HTTP because their plugins are listed here.

return App::configure(__DIR__)
    ->withPlugins([QueuePlugin::class, SchedulePlugin::class])
    ->withSetup(Setup::class)
    ->withMiddleware(append: [CoreFailures::class], prepend: [TokenAccess::class])
    ->withRouting(routes: 'routes/web.php', commands: 'routes/console.php', health: '/up')
    ->create();
