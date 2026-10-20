<?php

declare(strict_types=1);

namespace App\Http;

use App\Social\ImageAttachment;
use Crab\Http\HttpException;

// Keep validation at the input boundary, with explicit errors for each invalid shape.
// @mago-expect lint:cyclomatic-complexity,halstead
final readonly class PostInput
{
    public const MAX_IMAGES = 4;
    public const MAX_IMAGE_BYTES = 1_048_576;

    public function __construct(
        public string $requestId,
        public string $text,
        public array $platforms,
        public array $images,
        public string $fingerprint,
    ) {}

    public static function from(array $data): self
    {
        $requestId = $data['requestId'] ?? null;
        $text = $data['text'] ?? null;
        $platforms = $data['platforms'] ?? null;
        $uploads = $data['images'] ?? [];
        if (
            !is_string($requestId)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $requestId) !== 1
        ) {
            throw new HttpException(422, 'A requestId UUID is required.');
        }
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 3000) {
            throw new HttpException(422, 'Post text must contain at most 3,000 characters.');
        }
        if (!is_array($platforms) || !array_is_list($platforms) || $platforms === [] || count($platforms) > 3) {
            throw new HttpException(422, 'Select at least one destination.');
        }
        foreach ($platforms as $platform) {
            if (!in_array($platform, ['x', 'bluesky', 'dev-null'], true)) {
                throw new HttpException(422, 'Unknown publishing destination.');
            }
        }
        if (count(array_unique($platforms)) !== count($platforms)) {
            throw new HttpException(422, 'Choose each destination only once.');
        }
        $limit = in_array('dev-null', $platforms, true) ? 3 : self::MAX_IMAGES;
        if (!is_array($uploads) || !array_is_list($uploads) || count($uploads) > $limit) {
            throw new HttpException(422, 'These destinations allow at most ' . $limit . ' images.');
        }
        if (trim($text) === '' && $uploads === []) {
            throw new HttpException(422, 'Write something or attach an image.');
        }
        $images = [];
        foreach ($uploads as $upload) {
            if (!is_array($upload) || !is_string($upload['data'] ?? null) || strlen($upload['data']) > 1_398_104) {
                throw new HttpException(422, 'Each image must be at most 1 MiB.');
            }
            $bytes = base64_decode($upload['data'], true);
            $alt = $upload['alt'] ?? '';
            if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new HttpException(422, 'The image data is invalid or exceeds 1 MiB.');
            }
            if (!is_string($alt) || mb_strlen($alt) > 1000) {
                throw new HttpException(422, 'Image descriptions must contain at most 1,000 characters.');
            }
            // File signatures are checked before asking the decoder to inspect dimensions.
            $mime = new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!array_key_exists($mime, $extensions)) {
                throw new HttpException(422, 'Attach a JPEG, PNG, or WebP image.');
            }
            // Malformed image data emits a PHP warning; the false result becomes a 422 below.
            // @mago-expect lint:no-error-control-operator
            $dimensions = @getimagesizefromstring($bytes);
            if (
                $dimensions === false
                || $dimensions[0] < 1
                || $dimensions[1] < 1
                || ($dimensions[0] * $dimensions[1]) > 40_000_000
            ) {
                throw new HttpException(422, 'The image is invalid or larger than 40 megapixels.');
            }
            if (($upload['mimeType'] ?? $mime) !== $mime) {
                throw new HttpException(422, 'The image type does not match its contents.');
            }
            $filename = 'postslop-' . (count($images) + 1) . '.' . $extensions[$mime];
            $images[] = new ImageAttachment($filename, $mime, $bytes, $alt, $dimensions[0], $dimensions[1]);
        }
        sort($platforms);
        $fingerprint = hash('sha256', json_encode([
            $text,
            $platforms,
            array_map(static fn(ImageAttachment $image): array => [
                hash('sha256', $image->bytes),
                $image->alt,
            ], $images),
        ], JSON_THROW_ON_ERROR));
        return new self($requestId, $text, $platforms, $images, $fingerprint);
    }
}
