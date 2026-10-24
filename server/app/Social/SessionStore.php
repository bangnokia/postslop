<?php

declare(strict_types=1);

namespace App\Social;

use App\PrivateFiles;

/**
 * Every account's handle and browser session, in one private JSON file:
 * {"accounts": {"x": {"handle": "…", "enabled": true, "importedAt": "…", "source": "chrome", "session": {…}}}}
 * One account per site. A turned-off account keeps its session but is never offered or published to.
 */
// The store is the session file's whole interface.
// @mago-expect lint:cyclomatic-complexity,too-many-methods
final readonly class SessionStore
{
    private const array PLATFORMS = ['x', 'bluesky', 'dev-null', 'linkedin', 'threads'];

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

    /**
     * What is known about an account without its secrets.
     *
     * @return array{connected: bool, enabled: bool, handle: ?string, importedAt: ?string, source: ?string}
     */
    public function summary(string $platform): array
    {
        $account = $this->account($platform);
        return [
            'connected' => is_array($account['session'] ?? null),
            'enabled' => ($account['enabled'] ?? true) !== false,
            'handle' => is_string($account['handle'] ?? null) ? $account['handle'] : null,
            'importedAt' => is_string($account['importedAt'] ?? null) ? $account['importedAt'] : null,
            'source' => is_string($account['source'] ?? null) ? $account['source'] : null,
        ];
    }

    public function enabled(string $platform): bool
    {
        return $this->summary($platform)['enabled'];
    }

    public function setEnabled(string $platform, bool $enabled): void
    {
        self::check($platform);
        $this->change(static function (array $accounts) use ($platform, $enabled): array {
            $accounts[$platform] = [...($accounts[$platform] ?? []), 'enabled' => $enabled];
            return $accounts;
        });
    }

    /** Forgets the account: its handle and session leave the file. */
    public function remove(string $platform): void
    {
        self::check($platform);
        $this->change(static function (array $accounts) use ($platform): array {
            unset($accounts[$platform]);
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
                    // Importing an account again turns it back on.
                    'enabled' => true,
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
