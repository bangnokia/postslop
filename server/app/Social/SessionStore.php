<?php

declare(strict_types=1);

namespace App\Social;

use App\PrivateFiles;

/**
 * Every account's handle and browser session, in one private JSON file:
 * {"accounts": {"x": {"handle": "…", "importedAt": "…", "source": "chrome", "session": {"cookies": […], "origins": […]}}}}
 */
final readonly class SessionStore
{
    private const array PLATFORMS = ['x', 'bluesky', 'dev-null'];

    public function __construct(
        private string $path,
    ) {}

    public function read(string $platform): array
    {
        $session = $this->account($platform)['session'] ?? null;
        if (!is_array($session)) {
            throw new \RuntimeException('Import this account’s browser session first.');
        }
        return $session;
    }

    public function write(string $platform, array $state): void
    {
        $this->change(static function (array $accounts) use ($platform, $state): array {
            $accounts[$platform] = [...($accounts[$platform] ?? []), 'session' => $state];
            return $accounts;
        });
    }

    public function expectedHandle(string $platform): ?string
    {
        $handle = $this->account($platform)['handle'] ?? null;
        return is_string($handle) ? $handle : null;
    }

    /** @param array<string, array{handle: string, session: array}> $imported */
    public function import(array $imported, string $source): void
    {
        $this->change(static function (array $accounts) use ($imported, $source): array {
            foreach ($imported as $platform => $account) {
                self::check($platform);
                $accounts[$platform] = [
                    'handle' => $account['handle'],
                    'importedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    'source' => $source,
                    'session' => $account['session'],
                ];
            }
            return $accounts;
        });
    }

    private function account(string $platform): array
    {
        self::check($platform);
        return $this->accounts()[$platform] ?? [];
    }

    private function accounts(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The saved sessions are invalid. Import them again.');
        }
        return is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
    }

    /** Rewrites the file under a lock, so concurrent session refreshes never lose each other's changes. */
    private function change(\Closure $change): void
    {
        PrivateFiles::directory(dirname($this->path));
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Could not lock the saved sessions.');
        }
        try {
            PrivateFiles::json($this->path, ['accounts' => $change($this->accounts())]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function check(string $platform): void
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException('Unknown publishing destination.');
        }
    }
}
