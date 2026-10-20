<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Publishing;

/**
 * Publishes one queued post. Safe to run twice: the core sends a post only while it is still queued.
 */
final readonly class PublishPost
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function handle(string $id): void
    {
        $this->publishing->publish($id);
    }
}
