<?php

declare(strict_types=1);

namespace Postslop\Client;

use RuntimeException;

/** The local browser reader shared by the standalone client and the server CLI. Never sends sessions. */
// Keep credential-reading guards together before opening a private snapshot.
// @mago-expect lint:cyclomatic-complexity,kan-defect
final class BrowserSessions
{
    public const SITES = [
        'x' => 'X',
        'bluesky' => 'Bluesky',
        'dev-null' => 'dev-null.sh',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
    ];

    public static function read(string $browser, ?string $profile, array $handles): array
    {
        if (!in_array($browser, ['chrome', 'firefox'], true)) {
            throw new RuntimeException('Choose --browser=chrome or --browser=firefox.');
        }
        if ($handles === [] || array_diff_key($handles, self::SITES) !== []) {
            throw new RuntimeException('Name at least one supported site to import.');
        }
        foreach ($handles as $handle) {
            if (!is_string($handle) || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $handle) !== 1) {
                throw new RuntimeException(
                    'Give the intended account handle: letters, numbers, dots, underscores or hyphens.',
                );
            }
        }
        foreach (['pdo_sqlite', 'openssl', 'iconv'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new RuntimeException('Enable the PHP ' . $extension . ' extension to read browser sessions.');
            }
        }
        if ($browser === 'chrome' && PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Chrome session import is supported on macOS. Use --browser=firefox on Linux.');
        }
        $profile ??= $browser === 'firefox' ? self::defaultFirefoxProfile() : self::defaultChromeProfile();
        $database = $profile . ($browser === 'chrome' ? '/Cookies' : '/cookies.sqlite');
        if (!is_file($database)) {
            throw new RuntimeException('No browser cookies database in that profile. Pass --profile=DIR.');
        }
        $domains = [
            'x' => 'x.com',
            'bluesky' => 'bsky.app',
            'dev-null' => 'dev-null.sh',
            'linkedin' => 'linkedin.com',
            'threads' => 'threads.com',
        ];
        $local = [];
        if (array_key_exists('bluesky', $handles)) {
            $local = $browser === 'chrome'
                ? BrowserStorage::chrome($profile, $handles['bluesky'])
                : BrowserStorage::firefox($profile, $handles['bluesky']);
        }
        $key = $browser === 'chrome' ? BrowserCookies::chromeKey() : '';
        $agent = self::userAgent($browser, $profile);
        $accounts = Snapshot::read($database, static function (\PDO $db) use (
            $handles,
            $browser,
            $domains,
            $key,
            $local,
            $agent,
        ): array {
            $accounts = [];
            foreach ($handles as $site => $handle) {
                $cookies = $browser === 'chrome'
                    ? BrowserCookies::chrome($db, $domains[$site], $key)
                    : BrowserCookies::firefox($db, $domains[$site]);
                self::requireSignIn($site, $browser, $cookies);
                $session = [
                    'cookies' => $cookies,
                    'origins' => $site === 'bluesky'
                        ? [['origin' => 'https://bsky.app', 'localStorage' => $local]]
                        : [],
                ];
                if ($agent !== null && in_array($site, ['linkedin', 'threads'], true)) {
                    $session['userAgent'] = $agent;
                }
                $accounts[$site] = ['handle' => $handle, 'cookieCount' => count($cookies), 'session' => $session];
            }
            return $accounts;
        });
        return ['source' => $browser, 'profile' => $profile, 'accounts' => $accounts];
    }

    private static function requireSignIn(string $site, string $browser, array $cookies): void
    {
        $required = match ($site) {
            'x' => $browser === 'chrome' ? ['auth_token', 'ct0', 'twid'] : ['auth_token', 'ct0'],
            'linkedin' => ['li_at', 'JSESSIONID'],
            'threads' => ['sessionid', 'csrftoken', 'ds_user_id'],
            default => [],
        };
        foreach ($required as $name) {
            $matches = array_values(array_filter(
                $cookies,
                static fn(array $cookie): bool => $cookie['name'] === $name,
            ));
            if (
                $matches === []
                || count(array_unique(array_column($matches, 'value'))) !== 1
                || $matches[0]['value'] === ''
                || $site === 'x' && $browser === 'chrome' && (count($matches) !== 1 || $matches[0]['path'] !== '/')
            ) {
                throw new RuntimeException(
                    'No unambiguous ' . self::SITES[$site] . ' sign-in in that browser profile. Sign in first.',
                );
            }
        }
        if (
            $site === 'dev-null'
            && array_filter(
                $cookies,
                static fn(array $cookie): bool => (
                    $cookie['value'] !== ''
                    && ($cookie['name'] === 'devnull-session' || str_starts_with($cookie['name'], 'remember_web_'))
                ),
            ) === []
        ) {
            throw new RuntimeException('No persisted dev-null.sh sign-in in that browser profile.');
        }
    }

    private static function defaultChromeProfile(): string
    {
        $base = (string) getenv('HOME') . '/Library/Application Support/Google/Chrome';
        $state = is_file($base . '/Local State')
            ? json_decode((string) file_get_contents($base . '/Local State'), true)
            : [];
        $name = $state['profile']['last_used'] ?? 'Default';
        if (!is_string($name) || preg_match('/^(?:Default|Profile [0-9]+)$/D', $name) !== 1) {
            throw new RuntimeException('Choose the Chrome profile explicitly with --profile=DIR.');
        }
        return $base . '/' . $name;
    }

    private static function userAgent(string $browser, string $profile): ?string
    {
        if ($browser === 'chrome') {
            // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
            // @mago-expect lint:no-error-control-operator
            $version = @file_get_contents(dirname($profile) . '/Last Version');
            if (!is_string($version) || preg_match('/^([0-9]+)\./', trim($version), $major) !== 1) {
                return null;
            }
            return (
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/'
                . $major[1]
                . '.0.0.0 Safari/537.36'
            );
        }
        // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
        // @mago-expect lint:no-error-control-operator
        $version = @file_get_contents($profile . '/compatibility.ini');
        if (!is_string($version) || preg_match('/^LastVersion=([0-9]+)\./m', $version, $major) !== 1) {
            return null;
        }
        $system = PHP_OS_FAMILY === 'Darwin' ? 'Macintosh; Intel Mac OS X 10.15' : 'X11; Linux x86_64';
        return 'Mozilla/5.0 (' . $system . '; rv:' . $major[1] . '.0) Gecko/20100101 Firefox/' . $major[1] . '.0';
    }

    private static function defaultFirefoxProfile(): string
    {
        $home = (string) getenv('HOME');
        foreach ([
            $home . '/Library/Application Support/Firefox',
            $home . '/.config/mozilla/firefox',
            $home . '/.mozilla/firefox',
        ] as $base) {
            $ini = is_file($base . '/profiles.ini')
                ? parse_ini_file($base . '/profiles.ini', true, INI_SCANNER_RAW)
                : false;
            if (!is_array($ini)) {
                continue;
            }
            $path = null;
            foreach ($ini as $section => $values) {
                if (!str_starts_with($section, 'Install')) {
                    continue;
                }
                $path ??= $values['Default'] ?? null;
            }
            foreach ($ini as $values) {
                if (($values['Default'] ?? '') !== '1') {
                    continue;
                }
                $path ??= $values['Path'] ?? null;
            }
            if (is_string($path) && $path !== '') {
                return str_starts_with($path, '/') ? $path : $base . '/' . $path;
            }
        }
        throw new RuntimeException('Firefox was not found. Pass the profile folder with --profile=DIR.');
    }
}
