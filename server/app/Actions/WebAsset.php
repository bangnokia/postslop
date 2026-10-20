<?php

declare(strict_types=1);

namespace App\Actions;

use Crab\Http\HttpException;
use Crab\Http\Response;

final readonly class WebAsset
{
    private const array TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    public function handle(string $file): Response
    {
        // Built file names only: no directories, so nothing outside public/assets is reachable.
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $path = dirname(__DIR__, 2) . '/public/assets/' . $file;
        if (
            preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D', $file) !== 1
            || !array_key_exists($extension, self::TYPES)
            || !is_file($path)
        ) {
            throw new HttpException(404, 'Not found.');
        }
        return new Response(
            headers: [
                'Content-Type' => self::TYPES[$extension],
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            body: (string) file_get_contents($path),
        );
    }
}
