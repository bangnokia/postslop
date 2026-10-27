<?php

declare(strict_types=1);

namespace Postslop\Client;

use PDO;
use PDOException;
use RuntimeException;

/** Read a stable private copy of a live SQLite database, including its committed WAL. */
// Keep the capture checks and cleanup around every credential-bearing file.
// @mago-expect lint:cyclomatic-complexity,kan-defect
final class Snapshot
{
    private const MAX_BYTES = 134_217_728;

    public static function read(string $path, callable $read): mixed
    {
        $directory = sys_get_temp_dir() . '/postslop-browser-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Could not create a private browser snapshot.');
        }
        $db = null;
        try {
            self::capture($path, $directory . '/snapshot.sqlite');
            $db = new PDO('sqlite:' . $directory . '/snapshot.sqlite', options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            if ($db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                throw new RuntimeException('The browser database snapshot failed its integrity check.');
            }
            $db->exec('PRAGMA query_only = ON');
            return $read($db);
        } catch (PDOException) {
            throw new RuntimeException('The browser database could not be read. Check the profile and try again.');
        } finally {
            $db = null;
            $files = glob($directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private static function signature(string $path): array
    {
        $result = [];
        foreach (['', '-wal', '-journal'] as $suffix) {
            clearstatcache(true, $path . $suffix);
            // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
            // @mago-expect lint:no-error-control-operator
            $stat = @stat($path . $suffix);
            $result[$suffix] = $stat === false
                ? null
                : array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
            if ($stat !== false && $stat['size'] > self::MAX_BYTES) {
                throw new RuntimeException('The browser database exceeds the import size limit.');
            }
        }
        if ($result[''] === null) {
            throw new RuntimeException('The browser database was not found. Choose its profile with --profile=DIR.');
        }
        if (($result['-journal']['size'] ?? 0) > 0) {
            throw new RuntimeException('The browser has a pending database journal. Retry when it is idle.');
        }
        return $result;
    }

    private static function capture(string $source, string $destination): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = self::signature($source);
            $hashes = [];
            foreach (['', '-wal'] as $suffix) {
                if ($before[$suffix] !== null) {
                    $hashes[$suffix] = self::copy($source . $suffix, $destination . $suffix);
                } elseif (is_file($destination . $suffix)) {
                    unlink($destination . $suffix);
                }
            }
            $middle = self::signature($source);
            $second = [];
            foreach (array_keys($hashes) as $suffix) {
                $second[$suffix] = self::copy($source . $suffix, null);
            }
            if (
                !in_array(null, $hashes, true)
                && $before === $middle
                && $middle === self::signature($source)
                && $hashes === $second
            ) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('The browser changed its database during capture. Retry when it is idle.');
    }

    private static function copy(string $source, ?string $destination): ?string
    {
        // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
        // @mago-expect lint:no-error-control-operator
        $input = @fopen($source, 'rb');
        if ($input === false) {
            return null;
        }
        $output = null;
        try {
            if ($destination !== null) {
                $output = fopen($destination, 'wb');
                if ($output === false || !chmod($destination, 0o600)) {
                    throw new RuntimeException('Could not write the private browser snapshot.');
                }
            }
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($input)) {
                $chunk = fread($input, 65_536);
                if ($chunk === false) {
                    return null;
                }
                $size += strlen($chunk);
                if ($size > self::MAX_BYTES) {
                    throw new RuntimeException('The browser database exceeds the import size limit.');
                }
                hash_update($hash, $chunk);
                if (is_resource($output) && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not finish the private browser snapshot.');
                }
            }
            return hash_final($hash);
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
