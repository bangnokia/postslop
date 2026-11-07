<?php

declare(strict_types=1);

namespace App\Core;

use App\Social\ImageAttachment;

/**
 * Fits an image to one site's limits when it is sent. Callers send images as they are; an image within the site's
 * limits goes through untouched, and a larger one is scaled down and recompressed as a JPEG until it fits, with
 * transparency turned white, as the clients do.
 */
// Each branch is one limit, quality step or EXIF orientation; splitting them further would hide the order.
// @mago-expect lint:cyclomatic-complexity
final class ImageFit
{
    private const array QUALITIES = [90, 80, 70, 60, 50];

    /**
     * @param array{bytes: int, side?: int, pixels?: int} $limit
     */
    public static function forSite(ImageAttachment $image, array $limit): ImageAttachment
    {
        $side = $limit['side'] ?? PHP_INT_MAX;
        $pixels = $limit['pixels'] ?? PHP_INT_MAX;
        if (
            strlen($image->bytes) <= $limit['bytes']
            && max($image->width, $image->height) <= $side
            && ($image->width * $image->height) <= $pixels
        ) {
            return $image;
        }
        // GD holds about 4 bytes a pixel: the decoded image, a turned copy and the scaled canvas.
        $previous = self::makeRoom($image->width * $image->height * 12);
        try {
            return self::shrink($image, $limit['bytes'], $side, $pixels);
        } finally {
            if ($previous !== null) {
                // The worker process keeps its own limit for everything else.
                // @mago-expect lint:no-ini-set
                ini_set('memory_limit', $previous);
            }
        }
    }

    private static function shrink(ImageAttachment $image, int $bytes, int $side, int $pixels): ImageAttachment
    {
        $source = imagecreatefromstring($image->bytes);
        if ($source === false) {
            throw Failure::invalid('Postslop could not read an attached image to resize it. Attach it again.');
        }
        $source = self::upright($source, $image);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $side / max($width, $height), sqrt($pixels / ($width * $height)));
        while (true) {
            $target = [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
            $jpeg = self::encode(self::scaled($source, $target[0], $target[1]), $bytes);
            if ($jpeg !== null) {
                return new ImageAttachment(
                    (string) preg_replace('/\.[a-z]+$/D', '.jpg', $image->filename),
                    'image/jpeg',
                    $jpeg,
                    $image->alt,
                    $target[0],
                    $target[1],
                );
            }
            if ($target === [1, 1]) {
                throw Failure::invalid('Postslop could not shrink an attached image to fit. Attach a smaller one.');
            }
            $scale *= 0.75;
        }
    }

    /**
     * The image at this size on white, so transparent parts become white as in the clients.
     */
    private static function scaled(\GdImage $source, int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        return $canvas;
    }

    /**
     * The best JPEG quality that fits in these bytes, or null when even the lowest does not.
     */
    private static function encode(\GdImage $canvas, int $bytes): ?string
    {
        foreach (self::QUALITIES as $quality) {
            $stream = fopen('php://memory', 'w+b');
            imagejpeg($canvas, $stream, $quality);
            rewind($stream);
            $jpeg = (string) stream_get_contents($stream);
            fclose($stream);
            if (strlen($jpeg) <= $bytes) {
                return $jpeg;
            }
        }
        return null;
    }

    /**
     * A photo's camera orientation lives in its EXIF data, which re-encoding drops, so the pixels are turned first.
     */
    private static function upright(\GdImage $source, ImageAttachment $image): \GdImage
    {
        if ($image->mimeType !== 'image/jpeg') {
            return $source;
        }
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $image->bytes);
        rewind($stream);
        // Broken EXIF data emits a warning; the image is then used as it is.
        // @mago-expect lint:no-error-control-operator
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $turned = imagerotate($source, $angle, 0);
            $source = $turned === false ? $source : $turned;
        }
        $flip = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL];
        if (array_key_exists($orientation, $flip)) {
            imageflip($source, $flip[$orientation]);
        }
        return $source;
    }

    /**
     * Raises the memory limit for one large image when it is too low, and returns the limit to restore.
     */
    private static function makeRoom(int $bytes): ?string
    {
        $limit = (string) ini_get('memory_limit');
        $current = ini_parse_quantity($limit);
        $needed = memory_get_usage() + $bytes + (32 * 1024 * 1024);
        if ($current < 0 || $current >= $needed) {
            return null;
        }
        // A limit set for the whole process cannot know how large an image will be; it is restored after.
        // @mago-expect lint:no-ini-set
        return ini_set('memory_limit', (string) $needed) === false ? null : $limit;
    }
}
