<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Publishing;

/**
 * Sends one destination of a post, on that site's own queue. Safe to run twice: the core sends a delivery only
 * while it is still waiting to be sent.
 */
final readonly class PublishDelivery
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function handle(string $id, string $platform): void
    {
        $this->publishing->deliver($id, $platform);
    }
}
