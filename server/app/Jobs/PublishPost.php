<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Publishing;

/**
 * Hands a post queued by an earlier version to its sites' queues. Safe to run twice: the core hands a post on only
 * while it is still queued.
 */
final readonly class PublishPost
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function handle(string $id): void
    {
        $this->publishing->queue($id);
    }
}
