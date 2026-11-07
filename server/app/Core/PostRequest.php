<?php

declare(strict_types=1);

namespace App\Core;

use App\Social\ImageAttachment;
use App\Social\VideoAttachment;

// Keep validation at the input boundary, with explicit errors for each invalid shape.
// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods,halstead
final readonly class PostRequest
{
    public const int MAX_IMAGES = 4;
    // Four images this size arrive as about 27 MiB of base64, within the server's body limit and a 128 MB PHP worker.
    // Each site still gets an image shrunk to its own limit when it is sent (see ImageFit).
    public const int MAX_IMAGE_BYTES = 5_242_880;
    // A video this size, sent as base64 in JSON and decoded, still fits a 128 MB PHP worker.
    public const int MAX_VIDEO_BYTES = 20_971_520;
    public const array MODES = ['now', 'draft', 'schedule'];
    private const array VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

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
        public ?VideoAttachment $video = null,
    ) {}

    /**
     * The same post with another mode; the fingerprint does not include the mode, so it still matches.
     */
    public function as(string $mode): self
    {
        return new self(
            $this->requestId,
            $this->text,
            $this->platforms,
            $this->images,
            $this->fingerprint,
            $mode,
            $this->scheduledAt,
            $this->video,
        );
    }

    /**
     * Images and videos arrive as base64 data. A remote caller never names a file on this machine.
     */
    public static function fromApi(array $data): self
    {
        return self::from($data, self::decode(...));
    }

    /**
     * The local command line may also attach images and a video by path.
     */
    public static function fromCli(array $data): self
    {
        return self::from($data, static function (array $upload, string $kind): string {
            if (!is_string($upload['path'] ?? null)) {
                return self::decode($upload, $kind);
            }
            $path = $upload['path'];
            if (str_contains($path, "\0") || str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
                throw Failure::invalid('Cannot read the ' . $kind . ' ' . $path . '.');
            }
            if (filesize($path) > self::maxBytes($kind)) {
                throw Failure::invalid(
                    $kind === 'video'
                        ? 'The video ' . basename($path) . ' exceeds 20 MiB. Compress it first.'
                        : 'The image ' . basename($path) . ' exceeds 5 MiB. Resize it first.',
                );
            }
            return (string) file_get_contents($path);
        });
    }

    private static function decode(array $upload, string $kind): string
    {
        $limit = $kind === 'video' ? '20 MiB' : '5 MiB';
        if (
            !is_string($upload['data'] ?? null)
            || strlen($upload['data']) > (4 * intdiv(self::maxBytes($kind) + 2, 3))
        ) {
            throw Failure::invalid(
                $kind === 'video' ? 'The video must be at most 20 MiB.' : 'Each image must be at most 5 MiB.',
            );
        }
        $bytes = base64_decode($upload['data'], true);
        if ($bytes === false) {
            throw Failure::invalid('The ' . $kind . ' data is invalid or exceeds ' . $limit . '.');
        }
        return $bytes;
    }

    private static function maxBytes(string $kind): int
    {
        return $kind === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;
    }

    /**
     * @param \Closure(array, string): string $read Reads an upload's bytes; the string says image or video.
     */
    private static function from(array $data, \Closure $read): self
    {
        $requestId = $data['requestId'] ?? self::uuid();
        $text = $data['text'] ?? '';
        $platforms = $data['platforms'] ?? null;
        $uploads = $data['images'] ?? [];
        $videoUpload = $data['video'] ?? null;
        // Agents fill an unused video field with empty data; that is no video, not a video beside images.
        if (
            is_array($videoUpload)
            && ($videoUpload['data'] ?? null) === ''
            && ($videoUpload['path'] ?? null) === null
        ) {
            $videoUpload = null;
        }
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
        if (
            !is_array($platforms)
            || !array_is_list($platforms)
            || $platforms === []
            || count($platforms) > count(Platforms::IDS)
        ) {
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
        if ($videoUpload !== null && !is_array($videoUpload)) {
            throw Failure::invalid('The video must be an object.');
        }
        if ($videoUpload !== null && $uploads !== []) {
            throw Failure::invalid('Attach images or one video, not both.');
        }
        if (trim($text) === '' && $uploads === [] && $videoUpload === null) {
            throw Failure::invalid('Write something or attach an image or a video.');
        }
        $images = [];
        foreach ($uploads as $upload) {
            if (!is_array($upload)) {
                throw Failure::invalid('Each image must be an object.');
            }
            $images[] = self::image($read($upload, 'image'), $upload, count($images) + 1);
        }
        $video = $videoUpload === null ? null : self::video($read, $videoUpload, $platforms);
        sort($platforms);
        $parts = [
            $text,
            $platforms,
            array_map(static fn(ImageAttachment $image): array => [
                hash('sha256', $image->bytes),
                $image->alt,
            ], $images),
        ];
        // Only a post with a video gains this part, so posts saved before videos keep their fingerprints.
        if ($video !== null) {
            $parts[] = ['video', hash('sha256', $video->bytes), $video->alt];
        }
        $fingerprint = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
        return new self($requestId, $text, $platforms, $images, $fingerprint, $mode, $scheduledAt, $video);
    }

    /**
     * @param \Closure(array, string): string $read
     * @param list<string> $platforms
     */
    private static function video(\Closure $read, array $upload, array $platforms): VideoAttachment
    {
        // Destinations are checked before the bytes are read, so a refused video costs nothing.
        $seconds = Platforms::videoSeconds($platforms);
        if ($seconds === null) {
            $without = array_filter(
                $platforms,
                static fn(string $platform): bool => !array_key_exists($platform, Platforms::VIDEO_SECONDS),
            );
            throw Failure::invalid(
                implode(', ', array_map(static fn(string $platform): string => Platforms::LABELS[$platform], $without))
                    . ' cannot take videos from Postslop yet. Remove the video or those destinations.',
            );
        }
        $bytes = $read($upload, 'video');
        $alt = $upload['alt'] ?? '';
        if ($bytes === '' || strlen($bytes) > self::MAX_VIDEO_BYTES) {
            throw Failure::invalid('The video data is invalid or exceeds 20 MiB.');
        }
        if (!is_string($alt) || mb_strlen($alt) > 1000) {
            throw Failure::invalid('Video descriptions must contain at most 1,000 characters.');
        }
        $mime = new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
        if (!array_key_exists($mime, self::VIDEO_TYPES)) {
            throw Failure::invalid('Attach an MP4 or MOV video.');
        }
        if (($upload['mimeType'] ?? $mime) !== $mime) {
            throw Failure::invalid('The video type does not match its contents.');
        }
        $info = VideoFile::inspect($bytes);
        if ($info === null) {
            throw Failure::invalid('Postslop could not read the video track. Export the video again as MP4.');
        }
        $shortest = max([
            0,
            ...array_map(static fn(string $platform): int => Platforms::VIDEO_MIN_SECONDS[$platform] ?? 0, $platforms),
        ]);
        if ($info['milliseconds'] < ($shortest * 1000)) {
            throw Failure::invalid(
                'These destinations take videos of at least ' . self::clock($shortest) . '; this one is shorter.',
            );
        }
        if ($info['milliseconds'] > ($seconds * 1000)) {
            throw Failure::invalid(
                'These destinations take videos up to '
                . self::clock($seconds)
                . '; this one runs '
                . self::clock(intdiv($info['milliseconds'] + 999, 1000))
                . '.',
            );
        }
        return new VideoAttachment(
            'postslop-video.' . self::VIDEO_TYPES[$mime],
            $mime,
            $bytes,
            $alt,
            $info['width'],
            $info['height'],
            $info['milliseconds'],
        );
    }

    private static function clock(int $seconds): string
    {
        return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    private static function image(string $bytes, array $upload, int $number): ImageAttachment
    {
        $alt = $upload['alt'] ?? '';
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw Failure::invalid('The image data is invalid or exceeds 5 MiB.');
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
