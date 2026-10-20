<?php

declare(strict_types=1);

use App\Core\Platforms;

// Each site has its own queue and worker, so one post is sent to every site at the same time while each site
// still gets one post at a time. "default" takes the schedule's job that hands due posts to them.
return [
    // Longer than the slowest send, X's background browser, so a job still running is never given to another worker.
    'retry_after' => 300,
    'supervisors' => [
        'default' => ['queues' => ['default']],
        ...array_combine(Platforms::IDS, array_map(static fn(string $site): array => ['queues' => [
            $site,
        ]], Platforms::IDS)),
    ],
];
