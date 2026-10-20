<?php

declare(strict_types=1);

use App\Console\AccountList;
use App\Console\AccountSetup;
use App\Console\PostCreate;
use App\Console\PostDelete;
use App\Console\PostList;
use App\Console\PostPublish;
use App\Console\PostRetry;
use App\Console\PostRunDue;
use App\Console\PostSchedule;
use App\Console\PostShow;
use App\Console\SchedulerInstall;
use Crab\Command;

// bin/postslop gives each of these a short name: postslop post, postslop posts, …
Command::add('account:list', AccountList::class);
Command::add('account:setup', AccountSetup::class);
Command::add('post:create', PostCreate::class);
Command::add('post:list', PostList::class);
Command::add('post:show', PostShow::class);
Command::add('post:publish', PostPublish::class);
Command::add('post:retry', PostRetry::class);
Command::add('post:schedule', PostSchedule::class);
Command::add('post:delete', PostDelete::class);
Command::add('post:run-due', PostRunDue::class);
Command::add('scheduler:install', SchedulerInstall::class);
