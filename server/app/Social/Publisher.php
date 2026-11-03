<?php

declare(strict_types=1);

namespace App\Social;

interface Publisher
{
    public function status(): array;

    /**
     * @param list<ImageAttachment> $images
     * @param ?VideoAttachment $video Never with images. The core sends one only to sites in Platforms::VIDEO_SECONDS.
     */
    public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array;
}
