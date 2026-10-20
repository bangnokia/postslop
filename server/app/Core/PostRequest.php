<?php

declare(strict_types=1);

namespace App\Core;

use App\Social\ImageAttachment;

// Keep validation at the input boundary, with explicit errors for each invalid shape.
// @mago-expect lint:cyclomatic-complexity,kan-defect
final readonly class PostRequest
{
    public const int MAX_IMAGES = 4;
    public const int MAX_IMAGE_BYTES = 1_048_576;
    public const array MODES = ['now', 'draft', 'schedule'];

    /**
     * @param list<string> $platforms
     * @param list<ImageAttachment> $images
     */
    // A validated post keeps every part a publisher needs together.
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        public string $requestId,
        public string $text,
        public array $platforms,
        public array $images,
        public string $fingerprint,
        public string $mode,
        public ?\DateTimeImmutable $scheduledAt,
    ) {}

    /**
     * Images arrive as base64 data. A remote caller never names a file on this machine.
     */
    public static function fromApi(array $data): self
    {
        return self::from($data, self::decode(...));
    }

    /**
     * The local command line may also attach images by path.
     */
    public static function fromCli(array $data): self
    {
        return self::from($data, static function (array $upload): string {
            if (!is_string($upload['path'] ?? null)) {
                return self::decode($upload);
            }
            $path = $upload['path'];
            if (str_contains($path, "\0") || str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
                throw Failure::invalid('Cannot read the image ' . $path . '.');
            }
            if (filesize($path) > self::MAX_IMAGE_BYTES) {
                throw Failure::invalid('The image ' . basename($path) . ' exceeds 1 MiB. Resize it first.');
            }
            return (string) file_get_contents($path);
        });
    }

    private static function decode(array $upload): string
    {
        if (!is_string($upload['data'] ?? null) || strlen($upload['data']) > 1_398_104) {
            throw Failure::invalid('Each image must be at most 1 MiB.');
        }
        $bytes = base64_decode($upload['data'], true);
        if ($bytes === false) {
            throw Failure::invalid('The image data is invalid or exceeds 1 MiB.');
        }
        return $bytes;
    }

    /**
     * @param \Closure(array): string $read
     */
    private static function from(array $data, \Closure $read): self
    {
        $requestId = $data['requestId'] ?? self::uuid();
        $text = $data['text'] ?? '';
        $platforms = $data['platforms'] ?? null;
        $uploads = $data['images'] ?? [];
        $mode = $data['mode'] ?? (($data['scheduledAt'] ?? null) !== null ? 'schedule' : 'now');
        if (
            !is_string($requestId)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $requestId) !== 1
        ) {
            throw Failure::invalid('A requestId UUID is required.');
        }
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 3000) {
            throw Failure::invalid('Post text must contain at most 3,000 characters.');
        }
        if (!is_array($platforms) || !array_is_list($platforms) || $platforms === [] || count($platforms) > 3) {
            throw Failure::invalid('Select at least one destination.');
        }
        foreach ($platforms as $platform) {
            if (!in_array($platform, Platforms::IDS, true)) {
                throw Failure::invalid('Unknown publishing destination.');
            }
        }
        if (count(array_unique($platforms)) !== count($platforms)) {
            throw Failure::invalid('Choose each destination only once.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw Failure::invalid('The mode must be now, draft, or schedule.');
        }
        $scheduledAt = self::scheduledAt($mode, $data['scheduledAt'] ?? null);
        $limit = Platforms::maxImages($platforms);
        if (!is_array($uploads) || !array_is_list($uploads) || count($uploads) > $limit) {
            throw Failure::invalid('These destinations allow at most ' . $limit . ' images.');
        }
        if (trim($text) === '' && $uploads === []) {
            throw Failure::invalid('Write something or attach an image.');
        }
        $images = [];
        foreach ($uploads as $upload) {
            if (!is_array($upload)) {
                throw Failure::invalid('Each image must be an object.');
            }
            $images[] = self::image($read($upload), $upload, count($images) + 1);
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
        return new self($requestId, $text, $platforms, $images, $fingerprint, $mode, $scheduledAt);
    }

    private static function image(string $bytes, array $upload, int $number): ImageAttachment
    {
        $alt = $upload['alt'] ?? '';
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw Failure::invalid('The image data is invalid or exceeds 1 MiB.');
        }
        if (!is_string($alt) || mb_strlen($alt) > 1000) {
            throw Failure::invalid('Image descriptions must contain at most 1,000 characters.');
        }
        // File signatures are checked before asking the decoder to inspect dimensions.
        $mime = new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!array_key_exists($mime, $extensions)) {
            throw Failure::invalid('Attach a JPEG, PNG, or WebP image.');
        }
        // Malformed image data emits a PHP warning; the false result becomes a refusal below.
        // @mago-expect lint:no-error-control-operator
        $dimensions = @getimagesizefromstring($bytes);
        if (
            $dimensions === false
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || ($dimensions[0] * $dimensions[1]) > 40_000_000
        ) {
            throw Failure::invalid('The image is invalid or larger than 40 megapixels.');
        }
        if (($upload['mimeType'] ?? $mime) !== $mime) {
            throw Failure::invalid('The image type does not match its contents.');
        }
        $filename = 'postslop-' . $number . '.' . $extensions[$mime];
        return new ImageAttachment($filename, $mime, $bytes, $alt, $dimensions[0], $dimensions[1]);
    }

    private static function scheduledAt(string $mode, mixed $value): ?\DateTimeImmutable
    {
        if ($mode !== 'schedule') {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw Failure::invalid('Give a time to schedule the post for.');
        }
        try {
            $time = new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw Failure::invalid('The scheduled time is not a date and time.');
        }
        if ($time->getTimestamp() < (time() - 60)) {
            throw Failure::invalid('The scheduled time has already passed.');
        }
        return $time;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
