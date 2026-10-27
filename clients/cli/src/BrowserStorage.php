<?php

declare(strict_types=1);

namespace Postslop\Client;

use RuntimeException;

/** Bounded Snappy/LevelDB decoding for the browsers' Bluesky localStorage. */
// Binary formats need explicit bounds checks at each transition.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead,too-many-methods
final class BrowserStorage
{
    public const MAX_BYTES = 8_388_608;

    public static function bluesky(string $value, string $expected): array
    {
        $state = json_decode($value, true);
        $session = $state['session'] ?? null;
        $current = $session['currentAccount']['did'] ?? null;
        $accounts = $session['accounts'] ?? null;
        if (!is_string($current) || $current === '' || !is_array($accounts) || !array_is_list($accounts)) {
            throw new RuntimeException('Bluesky has no identifiable active account.');
        }
        $matches = array_values(array_filter(
            $accounts,
            static fn($account): bool => is_array($account) && ($account['did'] ?? null) === $current,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('Bluesky’s active account is ambiguous.');
        }
        $account = $matches[0];
        if (!is_string($account['handle'] ?? null) || strcasecmp($account['handle'], $expected) !== 0) {
            throw new RuntimeException('Bluesky’s active account does not match the expected handle.');
        }
        if (
            !is_string($account['accessJwt'] ?? null)
            || $account['accessJwt'] === ''
            || !is_string($account['refreshJwt'] ?? null)
            || $account['refreshJwt'] === ''
        ) {
            throw new RuntimeException('Bluesky’s active account is missing session credentials.');
        }
        $state['session']['accounts'] = [$account];
        $state['session']['currentAccount'] = ['did' => $current];
        return [['name' => 'BSKY_STORAGE', 'value' => json_encode($state, JSON_THROW_ON_ERROR)]];
    }

    public static function decode(string $bytes, string $encoding): string
    {
        // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
        // @mago-expect lint:no-error-control-operator
        $value = @iconv($encoding, 'UTF-8', $bytes);
        if ($value === false || strlen($value) > self::MAX_BYTES) {
            throw new RuntimeException('The browser localStorage has unsupported text encoding or size.');
        }
        return $value;
    }

    public static function firefox(string $profile, string $expected): array
    {
        $row = Snapshot::read($profile
        . '/storage/default/https+++bsky.app/ls/data.sqlite', static function (\PDO $db): array {
            if ($db->query('SELECT origin FROM database')->fetchAll(\PDO::FETCH_COLUMN) !== ['https://bsky.app']) {
                throw new RuntimeException('Bluesky localStorage has an unexpected origin.');
            }
            $row = $db->query(
                "SELECT utf16_length, conversion_type, compression_type, value FROM data WHERE key = 'BSKY_STORAGE'",
            )->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Bluesky has no persisted session in this Firefox profile.');
            }
            return $row;
        });
        if (
            !in_array($row['compression_type'], [0, 1], true)
            || !in_array($row['conversion_type'], [0, 1], true)
            || !is_string($row['value'])
        ) {
            throw new RuntimeException('Unsupported Firefox localStorage encoding.');
        }
        $bytes = $row['compression_type'] === 1 ? self::snappy($row['value']) : $row['value'];
        if (strlen($bytes) > self::MAX_BYTES) {
            self::invalid();
        }
        $nativeEncoding = pack('S', 1) === "\1\0" ? 'UTF-16LE' : 'UTF-16BE';
        $value = self::decode($bytes, $row['conversion_type'] === 1 ? 'UTF-8' : $nativeEncoding);
        if (strlen(iconv('UTF-8', 'UTF-16LE', $value)) !== (2 * (int) $row['utf16_length'])) {
            throw new RuntimeException('Bluesky localStorage text length does not match.');
        }
        return self::bluesky($value, $expected);
    }

    public static function snappy(string $data): string
    {
        $position = 0;
        $expected = self::varint($data, $position);
        if ($expected > self::MAX_BYTES) {
            self::invalid();
        }
        $output = '';
        while ($position < strlen($data)) {
            $tag = ord($data[$position++]);
            $kind = $tag & 3;
            $length = ($tag >> 2) + 1;
            if ($kind === 0) {
                if ($length > 60) {
                    $length = self::integer(self::take($data, $position, $length - 60)) + 1;
                }
                if ((strlen($output) + $length) > $expected) {
                    self::invalid();
                }
                $output .= self::take($data, $position, $length);
                continue;
            }
            $offset = self::integer(self::take($data, $position, [1 => 1, 2 => 2, 3 => 4][$kind]));
            if ($kind === 1) {
                $offset |= ($tag & 0xe0) << 3;
                $length = 4 + (($tag >> 2) & 7);
            }
            if ($offset < 1 || $offset > strlen($output) || (strlen($output) + $length) > $expected) {
                self::invalid();
            }
            for ($i = 0; $i < $length; $i++) {
                $output .= $output[strlen($output) - $offset];
            }
        }
        if (strlen($output) !== $expected) {
            self::invalid();
        }
        return $output;
    }

    public static function chrome(string $profile, string $expected): array
    {
        $logs = glob($profile . '/Local Storage/leveldb/*.log');
        $tables = glob($profile . '/Local Storage/leveldb/*.ldb');
        $files = [...($logs === false ? [] : $logs), ...($tables === false ? [] : $tables)];
        if ($files === [] || array_sum(array_map(filesize(...), $files)) > 268_435_456) {
            throw new RuntimeException('Chrome localStorage was not found or exceeds the import size limit.');
        }
        $latest = -1;
        $value = null;
        $prefix = "_https://bsky.app\0";
        foreach ($files as $file) {
            // Browser files can disappear or contain invalid bytes; report a fixed error instead of a raw warning.
            // @mago-expect lint:no-error-control-operator
            $data = @file_get_contents($file, length: 67_108_865);
            if ($data === false) {
                continue;
            }
            if (strlen($data) > 67_108_864) {
                self::invalid();
            }
            $entries = str_ends_with($file, '.log') ? self::logEntries($data) : self::tableEntries($data);
            foreach ($entries as [$sequence, $key, $candidate]) {
                if (
                    str_starts_with($key, $prefix)
                    && self::chromeString(substr($key, strlen($prefix))) === 'BSKY_STORAGE'
                    && $sequence > $latest
                ) {
                    $latest = $sequence;
                    $value = $candidate;
                }
            }
        }
        if ($value === null) {
            throw new RuntimeException('Bluesky has no persisted session in the selected Chrome profile.');
        }
        if (strlen($value) > self::MAX_BYTES) {
            self::invalid();
        }
        return self::bluesky(self::chromeString($value), $expected);
    }

    private static function chromeString(string $data): string
    {
        return match ($data[0] ?? '') {
            "\0" => self::decode(substr($data, 1), 'UTF-16LE'),
            "\1" => self::decode(substr($data, 1), 'ISO-8859-1'),
            default => throw new RuntimeException('Unsupported Chrome localStorage encoding.'),
        };
    }

    private static function logEntries(string $data): \Generator
    {
        $record = '';
        for ($block = 0; $block < strlen($data); $block += 32_768) {
            $position = $block;
            $end = min($block + 32_768, strlen($data));
            while (($position + 7) <= $end) {
                $length = self::integer(substr($data, $position + 4, 2));
                $kind = ord($data[$position + 6]);
                if ($kind === 0 || ($position + 7 + $length) > $end) {
                    break;
                }
                $payload = substr($data, $position + 7, $length);
                $position += 7 + $length;
                if (in_array($kind, [1, 2], true)) {
                    $record = '';
                }
                if (!in_array($kind, [1, 2, 3, 4], true)) {
                    self::invalid();
                }
                $record .= $payload;
                if (strlen($record) > 67_108_864) {
                    self::invalid();
                }
                if (in_array($kind, [1, 4], true)) {
                    yield from self::batch($record);
                    $record = '';
                }
            }
        }
    }

    private static function batch(string $data): \Generator
    {
        $position = 0;
        $sequence = self::integer(self::take($data, $position, 8));
        $count = self::integer(self::take($data, $position, 4));
        for ($i = 0; $i < $count; $i++) {
            $kind = ord(self::take($data, $position, 1));
            $key = self::sized($data, $position);
            if ($kind !== 0 && $kind !== 1) {
                self::invalid();
            }
            yield [$sequence + $i, $key, $kind === 1 ? self::sized($data, $position) : null];
        }
        if ($position !== strlen($data)) {
            self::invalid();
        }
    }

    private static function tableEntries(string $data): \Generator
    {
        if (strlen($data) < 48 || substr($data, -8) !== hex2bin('57fb808b247547db')) {
            self::invalid();
        }
        $position = strlen($data) - 48;
        self::varint($data, $position);
        self::varint($data, $position);
        $offset = self::varint($data, $position);
        $size = self::varint($data, $position);
        foreach (self::blockEntries(self::block($data, $offset, $size)) as [, $handle]) {
            $position = 0;
            $offset = self::varint($handle, $position);
            $size = self::varint($handle, $position);
            foreach (self::blockEntries(self::block($data, $offset, $size)) as [$key, $value]) {
                if (strlen($key) < 8) {
                    self::invalid();
                }
                $trailer = self::integer(substr($key, -8));
                if (!in_array($trailer & 255, [0, 1], true)) {
                    self::invalid();
                }
                yield [$trailer >> 8, substr($key, 0, -8), ($trailer & 255) === 1 ? $value : null];
            }
        }
    }

    private static function block(string $data, int $offset, int $size): string
    {
        $position = $offset;
        $block = self::take($data, $position, $size);
        $trailer = self::take($data, $position, 5);
        return match (ord($trailer[0])) {
            0 => $block,
            1 => self::snappy($block),
            default => throw new RuntimeException('Unsupported Chrome localStorage compression.'),
        };
    }

    private static function blockEntries(string $data): \Generator
    {
        if (strlen($data) < 4) {
            self::invalid();
        }
        $end = strlen($data) - 4 - (4 * self::integer(substr($data, -4)));
        if ($end < 0) {
            self::invalid();
        }
        $entries = substr($data, 0, $end);
        $position = 0;
        $key = '';
        while ($position < $end) {
            $shared = self::varint($entries, $position);
            $unshared = self::varint($entries, $position);
            $length = self::varint($entries, $position);
            if ($shared > strlen($key)) {
                self::invalid();
            }
            $key = substr($key, 0, $shared) . self::take($entries, $position, $unshared);
            yield [$key, self::take($entries, $position, $length)];
        }
    }

    private static function sized(string $data, int &$position): string
    {
        $length = self::varint($data, $position);
        return self::take($data, $position, $length);
    }

    private static function take(string $data, int &$position, int $length): string
    {
        if ($length < 0 || $position < 0 || $position > (strlen($data) - $length)) {
            self::invalid();
        }
        $result = substr($data, $position, $length);
        $position += $length;
        return $result;
    }

    private static function varint(string $data, int &$position): int
    {
        $value = 0;
        for ($shift = 0; $shift < 63; $shift += 7) {
            $byte = ord(self::take($data, $position, 1));
            $value |= ($byte & 127) << $shift;
            if ($byte < 128) {
                return $value;
            }
        }
        self::invalid();
    }

    private static function integer(string $data): int
    {
        $value = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $value |= ord($data[$i]) << ($i * 8);
        }
        if ($value < 0) {
            self::invalid();
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw new RuntimeException(
            'The browser localStorage is incomplete, unsupported, or exceeds its size limit. Retry when the browser is idle.',
        );
    }
}
