<?php

declare(strict_types=1);

namespace App\Core;

final class Platforms
{
    public const array IDS = ['x', 'bluesky', 'dev-null'];

    // Clients count characters with these; each publisher still enforces its site's own rule.
    public const array TEXT_LIMITS = ['x' => 280, 'bluesky' => 300, 'dev-null' => 4000];

    public static function maxImages(array $platforms): int
    {
        return in_array('dev-null', $platforms, true) ? 3 : 4;
    }
}
