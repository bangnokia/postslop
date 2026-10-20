<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Publishing;

/**
 * Pushed onto the queue every minute by the schedule. Safe to run twice: the core never publishes a post twice.
 */
final readonly class PublishDuePosts
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function handle(): void
    {
        $this->publishing->runDue();
    }
}
