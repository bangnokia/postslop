<?php

declare(strict_types=1);

namespace App\Social;

final readonly class ImageAttachment
{
    // A validated attachment keeps its bytes, media type, description, and dimensions together.
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $bytes,
        public string $alt,
        public int $width,
        public int $height,
    ) {}
}
