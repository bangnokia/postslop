<?php

declare(strict_types=1);

namespace App\Social;

interface Publisher
{
    public function status(): array;

    /**
     * @param list<ImageAttachment> $images
     */
    public function publish(string $requestId, string $text, array $images): array;
}
