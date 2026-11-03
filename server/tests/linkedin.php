<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Failure;
use App\Core\PostRequest;
use App\Social\LinkedInPublisher;
use App\Social\SessionStore;
use App\Social\VideoAttachment;
use Crab\Contracts\HttpClient\Client;

function linkedin_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * A dummy MP4 holding only the boxes App\Core\VideoFile reads, around random media bytes.
 */
function linkedin_video(int $milliseconds, int $padding = 200_000): string
{
    $box = static fn(string $type, string $payload): string => pack('N', 8 + strlen($payload)) . $type . $payload;
    $mvhd = $box('mvhd', "\0\0\0\0" . pack('NNNN', 0, 0, 1000, $milliseconds) . str_repeat("\0", 80));
    $matrix = pack('N9', 0x1_0000, 0, 0, 0, 0x1_0000, 0, 0, 0, 0x4000_0000);
    $tkhd = $box(
        'tkhd',
        "\0\0\0\x03"
            . pack('NNNNN', 0, 0, 1, 0, $milliseconds)
            . str_repeat("\0", 8)
            . pack('nnnn', 0, 0, 0, 0)
            . $matrix
            . pack('NN', 640 << 16, 360 << 16),
    );
    $hdlr = $box('hdlr', "\0\0\0\0" . pack('N', 0) . 'vide' . str_repeat("\0", 12) . "VideoHandler\0");
    $moov = $box('moov', $mvhd . $box('trak', $tkhd . $box('mdia', $hdlr)));
    return $box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41') . $box('mdat', random_bytes($padding)) . $moov;
}

$directory = sys_get_temp_dir() . '/postslop-linkedin-' . bin2hex(random_bytes(6));
$fake = proc_open(
    ['node', __DIR__ . '/fakes/server.mjs', '--port=0'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
linkedin_check(is_resource($fake), 'Could not start fake sites');
stream_set_timeout($pipes[1], 10);
$line = fgets($pipes[1]);
try {
    linkedin_check(
        is_string($line) && preg_match('~http://127\.0\.0\.1:([0-9]+)~', $line, $port) === 1,
        'Fake sites did not start',
    );
    $url = 'http://127.0.0.1:' . $port[1] . '/linkedin';
    putenv('POSTSLOP_LINKEDIN_URL=' . $url);
    $app = test_app($directory);
    $http = $app->container->get(Client::class);
    $sessions = $app->container->get(SessionStore::class);
    $publisher = new LinkedInPublisher($http, $sessions);
    $core = test_publishing($app, ['linkedin' => $publisher], $directory);
    // After test_publishing, which connects the account with an empty session.
    $sessions->import([
        'linkedin' => [
            'handle' => 'tester',
            'session' => [
                'cookies' => [
                    [
                        'name' => 'li_at',
                        'value' => 'fixture-li-at',
                        'domain' => '.linkedin.com',
                        'path' => '/',
                        'expires' => -1,
                    ],
                    [
                        'name' => 'JSESSIONID',
                        'value' => '"ajax:fixture"',
                        'domain' => '.www.linkedin.com',
                        'path' => '/',
                        'expires' => -1,
                    ],
                ],
                'origins' => [],
            ],
        ],
    ], 'test');
    $reset = static fn(string $scenario = '') => $http->post($url . '/__reset', json: ['scenario' => $scenario]);
    $stats = static fn(): array => $http->get($url . '/__stats')->json();
    $private = static fn(array $result): bool => (
        str_contains(json_encode($result), 'fixture-li-at') || str_contains(json_encode($result), 'ajax:fixture')
    );
    $reset();
    linkedin_check($publisher->status()['available'], 'The LinkedIn account did not verify');

    $bytes = linkedin_video(5000);
    $request = PostRequest::fromApi([
        'text' => 'LinkedIn video fixture',
        'platforms' => ['linkedin'],
        'video' => ['data' => base64_encode($bytes), 'mimeType' => 'video/mp4'],
    ]);
    $video = $request->video;
    linkedin_check($video instanceof VideoAttachment && $video->milliseconds === 5000, 'The dummy video was not read');

    // The fake asks for parts by default, and for one upload in the single-video scenario.
    foreach (['', 'single-video'] as $scenario) {
        $reset($scenario);
        $result = $publisher->publish(PostRequest::uuid(), 'LinkedIn video fixture', [], $video);
        linkedin_check(
            $result['status'] === 'published'
            && str_starts_with($result['url'], 'https://www.linkedin.com/feed/update/urn:li:share:'),
            'The video post failed (' . ($scenario === '' ? 'multipart' : $scenario) . '): ' . json_encode($result),
        );
        $state = $stats();
        linkedin_check(
            count($state['posts']) === 1 && $state['posts'][0]['media'][0]['category'] === 'VIDEO',
            'The share did not carry the video',
        );
        linkedin_check(
            $state['uploads'][0]['complete'] && $state['uploads'][0]['sha256'] === hash('sha256', $bytes),
            'The video bytes changed on the way',
        );
        $parts = $scenario === '' ? (int) ceil(strlen($bytes) / 65_536) : 0;
        linkedin_check($parts !== 1 && $state['uploads'][0]['parts'] === $parts, 'The video went up the wrong way');
    }

    // Shorter than three seconds: the core refuses it for LinkedIn when the post is saved.
    $reset();
    $shortVideo = ['data' => base64_encode(linkedin_video(2500)), 'mimeType' => 'video/mp4'];
    $refusal = null;
    try {
        PostRequest::fromApi(['text' => 'Too short', 'platforms' => ['linkedin'], 'video' => $shortVideo]);
    } catch (Failure $error) {
        $refusal = $error;
    }
    linkedin_check(
        $refusal?->kind === Failure::INVALID && str_contains($refusal->getMessage(), '0:03'),
        'The core accepted a video too short for LinkedIn',
    );
    // The sender keeps its own guard: a clip made for X alone, which takes it, is still refused here.
    $short = PostRequest::fromApi(['text' => 'Too short', 'platforms' => ['x'], 'video' => $shortVideo])->video;
    $result = $publisher->publish(PostRequest::uuid(), 'Too short', [], $short);
    linkedin_check(
        $result['status'] === 'failed' && str_contains($result['error'], '3 seconds'),
        'A short video was accepted',
    );
    linkedin_check($stats()['uploads'] === [] && $stats()['posts'] === [], 'A short video reached LinkedIn');

    // Every upload failure is a safe failure: nothing is shared, and an address off LinkedIn or a gap between parts
    // stops the upload before any byte is sent.
    foreach ([
        'video-refused',
        'part-fails',
        'missing-etag',
        'complete-fails',
        'gapped-parts',
        'foreign-host',
    ] as $scenario) {
        $reset($scenario);
        $result = $publisher->publish(PostRequest::uuid(), 'Do not share', [], $video);
        linkedin_check(
            $result['status'] === 'failed' && str_contains($result['error'], 'No post was submitted'),
            $scenario . ' was not a safe failure: ' . json_encode($result),
        );
        linkedin_check($stats()['posts'] === [], $scenario . ' shared a post');
        linkedin_check(!$private($result), $scenario . ' exposed a session value');
        if (in_array($scenario, ['gapped-parts', 'foreign-host'], true)) {
            linkedin_check(
                $stats()['uploads'][0]['bytes'] === 0,
                $scenario . ' sent video bytes before checking every part',
            );
        }
    }
    $reset();
    $mixed = $publisher->publish(
        PostRequest::uuid(),
        'Both',
        [new App\Social\ImageAttachment('a.png', 'image/png', 'x', '', 1, 1)],
        $video,
    );
    linkedin_check(
        $mixed['status'] === 'failed' && $stats()['uploads'] === [],
        'Images and a video were sent together',
    );

    // Through the core: stored, presented beside no images, and shared once however often it is repeated.
    $reset();
    $post = $core->create($request);
    linkedin_check(
        $post['status'] === 'published',
        'The core did not publish the video: ' . json_encode($post['results']),
    );
    linkedin_check(
        ($post['video']['mimeType'] ?? null) === 'video/mp4'
        && $post['video']['width'] === 640
        && $post['video']['height'] === 360
        && $post['images'] === [],
        'The core did not present the video',
    );
    linkedin_check(
        $core->create($request) === $post && count($stats()['posts']) === 1,
        'A repeated request shared twice',
    );
    linkedin_check($stats()['uploads'][0]['sha256'] === hash('sha256', $bytes), 'The stored video changed');

    // Images still go up the way they did.
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $reset();
    $imagePost = $core->create(PostRequest::fromApi([
        'text' => 'LinkedIn image fixture',
        'platforms' => ['linkedin'],
        'images' => [['data' => base64_encode($png), 'mimeType' => 'image/png', 'alt' => 'A square']],
    ]));
    linkedin_check(
        $imagePost['status'] === 'published' && $stats()['posts'][0]['media'][0]['category'] === 'IMAGE',
        'Image posts broke: ' . json_encode($imagePost['results']),
    );

    // A share LinkedIn may have saved stays unknown, never resent.
    $reset();
    $unknown = $publisher->publish(PostRequest::uuid(), '[simulate:unknown] A video', [], $video);
    linkedin_check(
        $unknown['status'] === 'unknown' && count($stats()['posts']) === 1,
        'An uncertain video share was not unknown',
    );
    linkedin_check(!$private($unknown), 'An uncertain result exposed a session value');

    echo
        'LinkedIn checks passed: multipart and single video uploads, the 3-second minimum, safe upload failures, '
            . "parts checked before sending, core storage and presentation, duplicate protection, images, and uncertain shares.\n";
} finally {
    proc_terminate($fake);
    proc_close($fake);
    test_remove($directory);
}
