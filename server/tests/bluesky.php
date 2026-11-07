<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\PostRequest;
use App\Social\BlueskyPublisher;
use App\Social\SessionStore;
use App\Social\VideoAttachment;
use Crab\Contracts\HttpClient\Client;

// An assertion evaluates a condition rather than selecting behavior.
// @mago-expect lint:no-boolean-flag-parameter
function bluesky_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * A dummy MP4: the boxes App\Core\VideoFile reads (ftyp, mvhd, and a video track's tkhd and hdlr), no real frames.
 */
function bluesky_video(int $width, int $height, int $milliseconds): string
{
    $box = static fn(string $type, string $payload): string => pack('N', 8 + strlen($payload)) . $type . $payload;
    // A full box starts with version 0 and no flags.
    $mvhd = $box('mvhd', "\0\0\0\0" . pack('NNNN', 0, 0, 1000, $milliseconds) . str_repeat("\0", 80));
    $identity = pack('N9', 0x1_0000, 0, 0, 0, 0x1_0000, 0, 0, 0, 0x4000_0000);
    $tkhd = $box(
        'tkhd',
        "\0\0\0\x03" . pack('NNNNN', 0, 0, 1, 0, $milliseconds) . str_repeat("\0", 16) . $identity
            . pack('NN', $width << 16, $height << 16),
    );
    $hdlr = $box('hdlr', "\0\0\0\0" . pack('N', 0) . 'vide' . str_repeat("\0", 12) . "VideoHandler\0");
    $moov = $box('moov', $mvhd . $box('trak', $tkhd . $box('mdia', $hdlr)));
    return $box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41') . $box('mdat', str_repeat("\0", 256)) . $moov;
}

$directory = sys_get_temp_dir() . '/postslop-bluesky-' . bin2hex(random_bytes(6));
$fake = proc_open(
    ['node', __DIR__ . '/fakes/server.mjs', '--port=0'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
bluesky_check(is_resource($fake), 'Could not start fake sites');
stream_set_timeout($pipes[1], 10);
$line = fgets($pipes[1]);
try {
    bluesky_check(
        is_string($line) && preg_match('~http://127\.0\.0\.1:([0-9]+)~', $line, $port) === 1,
        'Fake sites did not start',
    );
    $url = 'http://127.0.0.1:' . $port[1] . '/bluesky';
    putenv('POSTSLOP_BLUESKY_URL=' . $url);
    // A video service off this machine, which never resolves: a local stand-in PDS must keep every video local.
    putenv('POSTSLOP_BLUESKY_VIDEO_URL=https://video.invalid');
    putenv('POSTSLOP_TOKEN=bluesky-test-token');
    $app = test_app($directory);
    $http = $app->container->get(Client::class);
    $sessions = $app->container->get(SessionStore::class);
    $import = static fn() => $sessions->import([
        'bluesky' => [
            'handle' => 'tester.test',
            'session' => [
                'cookies' => [],
                'origins' => [[
                    'origin' => 'https://bsky.app',
                    'localStorage' => [[
                        'name' => 'BSKY_STORAGE',
                        'value' => json_encode([
                            'session' => [
                                'currentAccount' => ['did' => 'did:plc:fixture'],
                                'accounts' => [[
                                    'did' => 'did:plc:fixture',
                                    'handle' => 'tester.test',
                                    'accessJwt' => 'fixture-access',
                                    'refreshJwt' => 'fixture-refresh',
                                    'pdsUrl' => 'https://pds.invalid',
                                    'active' => true,
                                ]],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ],
        ],
    ], 'test');
    $import();
    $publisher = new BlueskyPublisher($http, $sessions, videoPollSeconds: 0.05, videoWaitSeconds: 1.0);
    $reset = static fn(string $scenario = '') => $http->post($url . '/__reset', json: ['scenario' => $scenario]);
    $stats = static fn(): array => $http->get($url . '/__stats')->json();
    $bytes = bluesky_video(640, 360, 3000);
    $video = new VideoAttachment('postslop-video.mp4', 'video/mp4', $bytes, 'A test pattern', 640, 360, 3000);

    // The upload is authorized for the PDS, processed by the video service, then embedded with its blob.
    $reset();
    $result = $publisher->publish(PostRequest::uuid(), 'A video post', [], $video);
    bluesky_check($result['status'] === 'published', 'Video post failed: ' . json_encode($result));
    bluesky_check(
        str_starts_with($result['url'], 'https://bsky.app/profile/tester.test/post/'),
        'Post URL was not clean',
    );
    $sent = $stats();
    $embed = $sent['posts'][0]['record']['embed'] ?? [];
    bluesky_check(
        ($embed['$type'] ?? null) === 'app.bsky.embed.video'
        && ($embed['video']['ref']['$link'] ?? null) === $sent['blobs'][0]['ref']['$link']
        && ($embed['video']['mimeType'] ?? null) === 'video/mp4'
        && ($embed['alt'] ?? null) === 'A test pattern'
        && ($embed['aspectRatio'] ?? null) === ['width' => 640, 'height' => 360],
        'The post did not embed the processed video, its alt text and aspect ratio: ' . json_encode($embed),
    );
    bluesky_check(
        count($sent['uploads']) === 1
        && $sent['uploads'][0]['sha256'] === hash('sha256', $bytes)
        && $sent['uploads'][0]['mimeType'] === 'video/mp4'
        && $sent['uploads'][0]['name'] === 'postslop-video.mp4',
        'The video bytes, type or name changed on the way to the video service',
    );
    bluesky_check(
        $sent['authorizations'][0]['aud'] === 'did:web:127.0.0.1:' . $port[1]
        && $sent['authorizations'][0]['lxm'] === 'com.atproto.repo.uploadBlob',
        'The upload was not authorized for the account\'s PDS',
    );
    bluesky_check($sent['polls'] === 2, 'The publisher did not wait for processing to finish');

    // Without a description the embed carries none.
    $reset();
    $plain = new VideoAttachment('postslop-video.mp4', 'video/mp4', $bytes, '', 640, 360, 3000);
    bluesky_check(
        $publisher->publish(PostRequest::uuid(), '', [], $plain)['status'] === 'published',
        'Video-only post failed',
    );
    bluesky_check(!array_key_exists('alt', $stats()['posts'][0]['record']['embed']), 'An empty description was sent');

    // Both answers the video service gives for a job are read: bare at upload, wrapped later, and a repeat upload.
    foreach (['video-lexicon', 'video-exists'] as $scenario) {
        $reset($scenario);
        $answer = $publisher->publish(PostRequest::uuid(), 'Again', [], $video);
        bluesky_check(
            $answer['status'] === 'published' && count($stats()['posts']) === 1,
            $scenario . ' was not published',
        );
    }

    // Anything that goes wrong before the post is created leaves nothing published, without repeating remote text.
    foreach ([
        'video-failed' => 'could not process',
        'video-refused' => 'verified email',
        'video-stuck' => 'still processing',
    ] as $scenario => $reason) {
        $reset($scenario);
        $answer = $publisher->publish(PostRequest::uuid(), 'Do not post', [], $video);
        bluesky_check(
            $answer['status'] === 'failed' && str_contains($answer['error'], $reason) && $stats()['posts'] === [],
            $scenario . ' was not a safe failure: ' . json_encode($answer),
        );
        bluesky_check(
            !str_contains($answer['error'], 'fixture-') && !str_contains($answer['error'], 'Confirm your email'),
            $scenario . ' exposed a token or the service\'s own message',
        );
    }
    $reset();
    $image = new App\Social\ImageAttachment('postslop-1.png', 'image/png', 'png', '', 1, 1);
    $both = $publisher->publish(PostRequest::uuid(), 'Both', [$image], $video);
    bluesky_check(
        $both['status'] === 'failed' && $stats()['uploads'] === [] && $stats()['posts'] === [],
        'Images and a video were mixed',
    );

    // The core path: a post with a video to Bluesky is validated, stored, and delivered once.
    $core = test_publishing($app, ['bluesky' => $publisher], $directory);
    $import();
    $reset();
    $request = PostRequest::fromApi([
        'text' => 'Core video',
        'platforms' => ['bluesky'],
        'video' => ['data' => base64_encode($bytes), 'mimeType' => 'video/mp4', 'alt' => 'Core alt'],
    ]);
    $post = $core->create($request);
    bluesky_check(
        $post['status'] === 'published',
        'The core did not deliver the video: ' . json_encode($post['results']),
    );
    bluesky_check(
        $post['images'] === []
        && ($post['video']['mimeType'] ?? null) === 'video/mp4'
        && ($post['video']['width'] ?? null) === 640
        && ($post['video']['height'] ?? null) === 360,
        'The stored post did not present its video',
    );
    $embed = $stats()['posts'][0]['record']['embed'];
    bluesky_check(
        $embed['alt'] === 'Core alt' && $embed['aspectRatio'] === ['width' => 640, 'height' => 360],
        'The core sent a different video description or size',
    );
    bluesky_check(
        $core->create($request) === $post && count($stats()['uploads']) === 1,
        'Repeating the request uploaded or posted twice',
    );

    // The fake takes image blobs of at most 2,000,000 bytes, as app.bsky.embed.images does, so a larger image fails
    // when sent as it is, and the core shrinks it to a JPEG that fits while a small image goes byte for byte.
    $large = test_noise_png(1000, 1000);
    bluesky_check(
        strlen($large) > 2_000_000 && strlen($large) <= PostRequest::MAX_IMAGE_BYTES,
        'The large fixture is mis-sized',
    );
    $dot = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($dot);
    $small = ob_get_clean();
    $reset();
    $tooLarge = new App\Social\ImageAttachment('postslop-1.png', 'image/png', $large, '', 1000, 1000);
    bluesky_check(
        $publisher->publish(PostRequest::uuid(), 'Too large', [$tooLarge])['status'] === 'failed'
        && $stats()['posts'] === [],
        'The fake took an image over Bluesky\'s limit',
    );
    $reset();
    $post = $core->create(PostRequest::fromApi([
        'text' => 'Core images',
        'platforms' => ['bluesky'],
        'images' => [
            ['data' => base64_encode($large), 'mimeType' => 'image/png', 'alt' => 'Noise'],
            ['data' => base64_encode($small), 'mimeType' => 'image/png', 'alt' => 'Dot'],
        ],
    ]));
    $sent = $stats();
    $embedded = $sent['posts'][0]['record']['embed']['images'] ?? [];
    bluesky_check(
        $post['status'] === 'published'
        && $sent['blobs'][0]['mimeType'] === 'image/jpeg'
        && $sent['blobs'][0]['size'] <= 2_000_000
        && $embedded[0]['alt'] === 'Noise'
        && $embedded[0]['aspectRatio']['width'] === $embedded[0]['aspectRatio']['height']
        && $embedded[0]['aspectRatio']['width'] <= 1000,
        'The core did not shrink an image to Bluesky\'s limit: ' . json_encode([$post['results'], $sent['blobs']]),
    );
    bluesky_check(
        $sent['blobs'][1]['mimeType'] === 'image/png'
        && $sent['blobs'][1]['sha256'] === hash('sha256', $small)
        && $embedded[1]['aspectRatio'] === ['width' => 8, 'height' => 8],
        'An image within Bluesky\'s limit was changed',
    );
    echo
        "Bluesky checks passed: video service authorization, upload, processing waits, embeds with alt text and aspect ratio, repeat uploads, safe failures, images shrunk to the site's limit, and the core path.\n";
} finally {
    proc_terminate($fake);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($fake);
    test_remove($directory);
}
