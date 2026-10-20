<?php

declare(strict_types=1);

namespace App\Social;

use App\PrivateFiles;

final readonly class SessionStore
{
    public function __construct(
        private string $directory,
    ) {}

    public function read(string $platform): array
    {
        $path = $this->path($platform);
        if (!is_file($path)) {
            throw new \RuntimeException('Import this account’s browser session first.');
        }
        try {
            $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The saved session is invalid. Import it again.');
        }
        if (!is_array($state)) {
            throw new \RuntimeException('The saved session is invalid. Import it again.');
        }
        return $state;
    }

    public function write(string $platform, array $state): void
    {
        PrivateFiles::json($this->path($platform), $state);
    }

    public function expectedHandle(string $platform): ?string
    {
        $path = $this->directory . '/manifest.json';
        if (!is_file($path)) {
            return null;
        }
        $manifest = json_decode(file_get_contents($path), true);
        $handle = $manifest['accounts'][$platform]['handle'] ?? null;
        return is_string($handle) ? $handle : null;
    }

    private function path(string $platform): string
    {
        if (!in_array($platform, ['x', 'bluesky', 'dev-null'], true)) {
            throw new \InvalidArgumentException('Unknown publishing destination.');
        }
        return $this->directory . '/' . $platform . '.json';
    }
}
