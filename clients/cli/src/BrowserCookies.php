<?php

declare(strict_types=1);

namespace Postslop\Client;

use PDO;
use RuntimeException;

/** Browser-specific cookie schema checks. No network calls or persistent credential writes. */
// Keep browser protocol guards with their decoding steps.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final class BrowserCookies
{
    public static function firefox(PDO $db, string $domain): array
    {
        $columns = $db->query('PRAGMA table_info(moz_cookies)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $required = [
            'name',
            'value',
            'host',
            'path',
            'expiry',
            'isSecure',
            'isHttpOnly',
            'sameSite',
            'originAttributes',
        ];
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if (array_diff($required, $columns) !== [] || $version < 10 || $version > 17) {
            throw new RuntimeException('Unsupported Firefox cookie schema.');
        }
        $rows = $db->prepare('SELECT * FROM moz_cookies WHERE host = ? OR host = ? OR host LIKE ?');
        $rows->execute([$domain, '.' . $domain, '%.' . $domain]);
        $cookies = [];
        foreach ($rows as $row) {
            if (
                $row['originAttributes'] !== ''
                || ($row['isPartitionedAttributeSet'] ?? 0)
                || $domain === 'x.com' && $row['name'] === 'auth_multi'
            ) {
                continue;
            }
            $expires = $row['expiry'] / ($version >= 16 ? 1000 : 1);
            $session = (bool) ($row['isSession'] ?? false);
            if (!$session && $expires > 0 && $expires <= time()) {
                continue;
            }
            $sameSite = [0 => 'None', 1 => 'Lax', 2 => 'Strict', 256 => 'Lax'][$row['sameSite']] ?? null;
            if ($sameSite === null) {
                throw new RuntimeException('Unsupported Firefox SameSite value.');
            }
            foreach (['name', 'value', 'host', 'path'] as $field) {
                if (!is_string($row[$field]) || preg_match('//u', $row[$field]) !== 1) {
                    throw new RuntimeException('Invalid Firefox cookie text.');
                }
            }
            $cookies[] = [
                'name' => $row['name'],
                'value' => $row['value'],
                'domain' => $row['host'],
                'path' => $row['path'],
                'expires' => $session || $expires <= 0 ? -1 : $expires,
                'httpOnly' => (bool) $row['isHttpOnly'],
                'secure' => (bool) $row['isSecure'],
                'sameSite' => $sameSite,
            ];
        }
        return $cookies;
    }

    public static function chrome(PDO $db, string $domain, #[\SensitiveParameter] string $key): array
    {
        $version = $db->query("SELECT value FROM meta WHERE key = 'version'")->fetchColumn();
        $columns = $db->query('PRAGMA table_info(cookies)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $required = [
            'host_key',
            'name',
            'value',
            'encrypted_value',
            'path',
            'expires_utc',
            'is_persistent',
            'is_secure',
            'is_httponly',
            'samesite',
            'top_frame_site_key',
        ];
        if ((string) $version !== '24' || array_diff($required, $columns) !== []) {
            throw new RuntimeException('Unsupported Chrome cookie schema; version 24 is required.');
        }
        $subdomains = in_array($domain, ['linkedin.com', 'threads.com'], true);
        $rows = $db->prepare(
            'SELECT * FROM cookies WHERE host_key IN (?, ?)' . ($subdomains ? ' OR host_key LIKE ?' : ''),
        );
        $rows->execute([$domain, '.' . $domain, ...($subdomains ? ['%.' . $domain] : [])]);
        $cookies = [];
        foreach ($rows as $row) {
            if ($row['top_frame_site_key'] !== '' || $domain === 'x.com' && $row['name'] === 'auth_multi') {
                continue;
            }
            $expires = $row['is_persistent'] ? ($row['expires_utc'] / 1_000_000) - 11_644_473_600 : -1;
            if ($row['is_persistent'] && $expires <= time()) {
                continue;
            }
            $sameSite = [-1 => 'Lax', 0 => 'None', 1 => 'Lax', 2 => 'Strict'][$row['samesite']] ?? null;
            if ($sameSite === null || $row['value'] !== '' || !is_string($row['encrypted_value'])) {
                throw new RuntimeException('Unsupported Chrome cookie value or metadata.');
            }
            foreach (['name', 'host_key', 'path'] as $field) {
                if (!is_string($row[$field]) || preg_match('//u', $row[$field]) !== 1) {
                    throw new RuntimeException('Invalid Chrome cookie text.');
                }
            }
            $cookies[] = [
                'name' => $row['name'],
                'value' => self::decrypt($row['encrypted_value'], $row['host_key'], $key),
                'domain' => $row['host_key'],
                'path' => $row['path'],
                'expires' => $expires,
                'httpOnly' => (bool) $row['is_httponly'],
                'secure' => (bool) $row['is_secure'],
                'sameSite' => $sameSite,
            ];
        }
        return $cookies;
    }

    public static function decrypt(
        #[\SensitiveParameter]
        string $encrypted,
        string $host,
        #[\SensitiveParameter]
        string $key,
    ): string {
        if (!str_starts_with($encrypted, 'v10') || strlen($encrypted) <= 3 || ((strlen($encrypted) - 3) % 16) !== 0) {
            throw new RuntimeException('Unsupported Chrome cookie encryption.');
        }
        $plain = openssl_decrypt(substr($encrypted, 3), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, str_repeat(' ', 16));
        if ($plain === false || !hash_equals(hash('sha256', $host, true), substr($plain, 0, 32))) {
            throw new RuntimeException('Chrome cookie decryption or host verification failed.');
        }
        $value = substr($plain, 32);
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Invalid Chrome cookie text.');
        }
        return $value;
    }

    public static function chromeKey(): string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Chrome session import is supported on macOS. Use Firefox on Linux.');
        }
        $process = proc_open(
            ['/usr/bin/security', 'find-generic-password', '-w', '-s', 'Chrome Safe Storage'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not request Chrome Safe Storage from macOS Keychain.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $password = '';
        $deadline = microtime(true) + 30;
        $status = null;
        try {
            do {
                $password .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (strlen($password) > 65_536 || microtime(true) > $deadline) {
                    proc_terminate($process);
                    throw new RuntimeException(
                        'Chrome Safe Storage access timed out. Check the Keychain prompt and retry.',
                    );
                }
                usleep(50_000);
            } while (true);
            $password .= stream_get_contents($pipes[1]);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        $password = str_ends_with($password, "\n") ? substr($password, 0, -1) : $password;
        if (($status['exitcode'] ?? -1) !== 0 || $password === '') {
            throw new RuntimeException('Chrome Safe Storage was unavailable or access was denied.');
        }
        return hash_pbkdf2('sha1', $password, 'saltysalt', 1003, 16, true);
    }
}
