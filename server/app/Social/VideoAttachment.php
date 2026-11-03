<?php

declare(strict_types=1);

namespace App\Social;

final readonly class VideoAttachment
{
    // A validated video keeps its bytes, media type, description, display size and length together.
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $bytes,
        public string $alt,
        public int $width,
        public int $height,
        public int $milliseconds,
    ) {}
}
