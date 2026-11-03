<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads the display size and length of an MP4 or QuickTime file from its boxes, without decoding it, so the core can
 * check a video before any site sees it.
 */
// One pass over ISO base media boxes; each branch refuses a malformed box.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final class VideoFile
{
    /**
     * @return array{width: int, height: int, milliseconds: int}|null Null when the file has no readable video track.
     */
    public static function inspect(string $bytes): ?array
    {
        $length = strlen($bytes);
        if ($length < 16 || substr($bytes, 4, 4) !== 'ftyp') {
            return null;
        }
        $moov = self::find($bytes, 0, $length, 'moov');
        $mvhd = $moov === null ? null : self::find($bytes, $moov[0], $moov[1], 'mvhd');
        if ($mvhd === null || ($mvhd[1] - $mvhd[0]) < 20) {
            return null;
        }
        $version = ord($bytes[$mvhd[0]]);
        if ($version === 1 && ($mvhd[1] - $mvhd[0]) < 32) {
            return null;
        }
        [$timescale, $duration] = $version === 1
            ? [self::uint32($bytes, $mvhd[0] + 20), self::uint64($bytes, $mvhd[0] + 24)]
            : [self::uint32($bytes, $mvhd[0] + 12), self::uint32($bytes, $mvhd[0] + 16)];
        if ($timescale < 1 || $duration < 1 || $duration > intdiv(PHP_INT_MAX, 1000)) {
            return null;
        }
        foreach (self::boxes($bytes, $moov[0], $moov[1]) as [$type, $start, $end]) {
            if ($type !== 'trak') {
                continue;
            }
            $mdia = self::find($bytes, $start, $end, 'mdia');
            $hdlr = $mdia === null ? null : self::find($bytes, $mdia[0], $mdia[1], 'hdlr');
            if ($hdlr === null || ($hdlr[1] - $hdlr[0]) < 12 || substr($bytes, $hdlr[0] + 8, 4) !== 'vide') {
                continue;
            }
            $size = self::trackSize($bytes, self::find($bytes, $start, $end, 'tkhd'));
            if ($size !== null) {
                return [...$size, 'milliseconds' => intdiv($duration * 1000, $timescale)];
            }
        }
        return null;
    }

    /**
     * The track's width and height as it is shown, swapped when its matrix turns it a quarter.
     *
     * @param array{0: int, 1: int}|null $tkhd
     * @return array{width: int, height: int}|null
     */
    private static function trackSize(string $bytes, ?array $tkhd): ?array
    {
        if ($tkhd === null || ($tkhd[1] - $tkhd[0]) < 4) {
            return null;
        }
        // Version 1 widens the times and duration to 64 bits; the matrix and size follow them.
        $matrix = $tkhd[0] + (ord($bytes[$tkhd[0]]) === 1 ? 52 : 40);
        if ($tkhd[1] < ($matrix + 44)) {
            return null;
        }
        $width = self::uint32($bytes, $matrix + 36) >> 16;
        $height = self::uint32($bytes, $matrix + 40) >> 16;
        if ($width < 1 || $height < 1) {
            return null;
        }
        $quarterTurn = self::uint32($bytes, $matrix) === 0 && self::uint32($bytes, $matrix + 16) === 0;
        return $quarterTurn ? ['width' => $height, 'height' => $width] : ['width' => $width, 'height' => $height];
    }

    /**
     * @return array{0: int, 1: int}|null The payload of the first child box of this type.
     */
    private static function find(string $bytes, int $start, int $end, string $type): ?array
    {
        foreach (self::boxes($bytes, $start, $end) as [$found, $payload, $boxEnd]) {
            if ($found === $type) {
                return [$payload, $boxEnd];
            }
        }
        return null;
    }

    /**
     * @return \Generator<array{0: string, 1: int, 2: int}> Each child box's type, payload start and end.
     */
    private static function boxes(string $bytes, int $start, int $end): \Generator
    {
        $offset = $start;
        while (($offset + 8) <= $end) {
            $size = self::uint32($bytes, $offset);
            // A size of 1 means a 64-bit size follows the type; 0 means the box runs to the end.
            $header = $size === 1 ? 16 : 8;
            if ($header === 16) {
                if (($offset + 16) > $end) {
                    return;
                }
                $size = self::uint64($bytes, $offset + 8);
            }
            if ($size === 0 && $header === 8) {
                $size = $end - $offset;
            }
            if ($size < $header || $size > ($end - $offset)) {
                return;
            }
            yield [substr($bytes, $offset + 4, 4), $offset + $header, $offset + $size];
            $offset += $size;
        }
    }

    private static function uint32(string $bytes, int $offset): int
    {
        return unpack('N', $bytes, $offset)[1];
    }

    private static function uint64(string $bytes, int $offset): int
    {
        $value = unpack('J', $bytes, $offset)[1];
        // Sizes past PHP's signed range cannot fit any file Postslop accepts.
        return $value < 0 ? PHP_INT_MAX : $value;
    }
}
