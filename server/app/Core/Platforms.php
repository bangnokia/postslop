<?php

declare(strict_types=1);

namespace App\Core;

final class Platforms
{
    public const array IDS = ['x', 'bluesky', 'dev-null', 'linkedin', 'threads'];

    public const array LABELS = [
        'x' => 'X',
        'bluesky' => 'Bluesky',
        'dev-null' => 'dev-null.sh',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
    ];

    // Clients count characters with these; each publisher still enforces its site's own rule.
    public const array TEXT_LIMITS = [
        'x' => 280,
        'bluesky' => 300,
        'dev-null' => 4000,
        'linkedin' => 3000,
        'threads' => 500,
    ];

    // The longest video each site takes from Postslop, in seconds. A site without an entry takes no videos yet.
    public const array VIDEO_SECONDS = [
        'x' => 140,
        'bluesky' => 180,
        'linkedin' => 600,
    ];

    // The shortest video a site takes, in seconds, where it has a minimum.
    public const array VIDEO_MIN_SECONDS = ['linkedin' => 3];

    /**
     * The longest video all of these sites take, or null when one of them takes no videos.
     *
     * @param list<string> $platforms
     */
    public static function videoSeconds(array $platforms): ?int
    {
        $limits = [];
        foreach ($platforms as $platform) {
            if (!array_key_exists($platform, self::VIDEO_SECONDS)) {
                return null;
            }
            $limits[] = self::VIDEO_SECONDS[$platform];
        }
        return $limits === [] ? null : min($limits);
    }

    public static function maxImages(array $platforms): int
    {
        return in_array('dev-null', $platforms, true) ? 3 : 4;
    }
}
