<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Failure;
use App\Core\PostRequest;
use App\Social\Publisher;
use App\Social\VideoAttachment;
use Crab\Contracts\Database\Database;

// An assertion evaluates a boolean condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * A dummy MP4: just the boxes App\Core\VideoFile reads (ftyp, mvhd, and a video track's tkhd and hdlr).
 */
function core_test_mp4(int $width, int $height, int $milliseconds, bool $quarterTurn = false): string
{
    $box = static fn(string $type, string $payload): string => pack('N', 8 + strlen($payload)) . $type . $payload;
    $matrix = $quarterTurn
        ? pack('N9', 0, 0x10000, 0, 0xFFFF0000, 0, 0, 0, 0, 0x40000000)
        : pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
    $mvhd = $box('mvhd', "\0\0\0\0" . pack('N4', 0, 0, 1000, $milliseconds) . str_repeat("\0", 80));
    $tkhd = $box('tkhd', "\0\0\0\x03" . pack('N5', 0, 0, 1, 0, $milliseconds) . str_repeat("\0", 16) . $matrix
        . pack('N2', $width << 16, $height << 16));
    $hdlr = $box('hdlr', str_repeat("\0", 8) . 'vide' . str_repeat("\0", 12) . "VideoHandler\0");
    return $box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41')
        . $box('moov', $mvhd . $box('trak', $tkhd . $box('mdia', $hdlr)))
        . $box('mdat', str_repeat("\x07", 256));
}

function refused(callable $action, string $kind): void
{
    $caught = null;
    try {
        $action();
    } catch (Failure $error) {
        $caught = $error;
    }
    check($caught?->kind === $kind, 'Expected a ' . $kind . ' refusal');
}

$publisher = new class implements Publisher {
    public int $calls = 0;
    public bool $uncertain = false;
    public bool $failing = false;

    public function status(): array
    {
        return ['id' => 'x', 'label' => 'X', 'available' => true, 'handle' => '@fixture'];
    }

    public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array
    {
        $this->calls++;
        if ($this->uncertain) {
            throw new RuntimeException('Simulated interruption after submission');
        }
        if ($this->failing) {
            return ['platform' => 'x', 'status' => 'failed', 'error' => 'Rejected before submission.'];
        }
        return ['platform' => 'x', 'status' => 'published', 'url' => 'https://x.com/fixture/status/123'];
    }
};
$directory = sys_get_temp_dir() . '/postslop-core-' . bin2hex(random_bytes(6));
$app = test_app($directory);
$db = $app->container->get(Database::class);
$publishing = test_publishing($app, ['x' => $publisher], $directory);
$payload = [
    'requestId' => '00000000-0000-4000-8000-000000000001',
    'text' => 'fixture',
    'platforms' => ['x'],
    'images' => [],
];
try {
    $input = PostRequest::fromApi($payload);
    $first = $publishing->create($input);
    $second = $publishing->create($input);
    check($first === $second && $publisher->calls === 1, 'A repeated Enter published twice');
    refused(fn() => $publishing->create(PostRequest::fromApi([...$payload, 'text' => 'different'])), Failure::CONFLICT);
    $publisher->uncertain = true;
    $uncertain = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000002']);
    check($publishing->create($uncertain)['status'] === 'unknown', 'Lost outcomes must be unknown');
    $publishing->create($uncertain);
    check($publisher->calls === 2, 'An uncertain submission was retried');
    refused(fn() => $publishing->retry($uncertain->requestId), Failure::INVALID);
    check($publisher->calls === 2, 'Retry resent an unknown result');
    $publisher->uncertain = false;

    $interrupted = PostRequest::fromApi([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-000000000004',
        'platforms' => ['bluesky', 'x'],
    ]);
    $interruptedResults = [
        ['platform' => 'bluesky', 'status' => 'unknown', 'error' => 'Submission interrupted.'],
        ['platform' => 'x', 'status' => 'failed', 'error' => 'Not attempted.'],
    ];
    test_seed($app, $interrupted->requestId, $interrupted->fingerprint, $interruptedResults);
    $recovered = $publishing->create($interrupted);
    check(
        $recovered['status'] === 'unknown' && $recovered['results'] === $interruptedResults,
        'Crash recovery lost an unattempted destination',
    );
    check($publisher->calls === 2, 'Crash recovery attempted an unstarted destination');

    $completed = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000005']);
    test_seed($app, $completed->requestId, $completed->fingerprint, [[
        'platform' => 'x',
        'status' => 'published',
        'url' => 'https://x.com/fixture/status/456',
    ]], 'unknown');
    check(
        $publishing->find($completed->requestId)['status'] === 'published',
        'Confirmed publication retained a stale unknown summary',
    );
    check(
        $publishing->create($completed)['status'] === 'published' && $publisher->calls === 2,
        'Confirmed crash recovery repeated publication',
    );
    $unstarted = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000006']);
    test_seed($app, $unstarted->requestId, $unstarted->fingerprint, [[
        'platform' => 'x',
        'status' => 'failed',
        'error' => 'Not attempted.',
    ]], 'unknown');
    check(
        $publishing->create($unstarted)['status'] === 'failed' && $publisher->calls === 2,
        'An unstarted receipt was retried',
    );
    check($publishing->accounts()['platforms'][0]['handle'] === 'fixture', 'Handle normalization failed');

    // Each site has its own lock. A delivery left "sending" stays so while its site is busy, and becomes unknown,
    // never sent again, once the site is free: the sender crashed.
    $crashed = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000012']);
    test_seed($app, $crashed->requestId, $crashed->fingerprint, [
        ['platform' => 'x', 'status' => 'sending', 'error' => 'Publishing started.'],
    ]);
    $lock = fopen($directory . '/publishing-x.lock', 'c');
    flock($lock, LOCK_EX);
    check($publishing->find($crashed->requestId)['status'] === 'publishing', 'A delivery being sent was settled');
    flock($lock, LOCK_UN);
    fclose($lock);
    check($publishing->find($crashed->requestId)['status'] === 'unknown', 'A crashed delivery was not made unknown');
    $publishing->deliver($crashed->requestId, 'x');
    check($publisher->calls === 2, 'A crashed delivery was sent again');
    // A delivery waiting far longer than any queue takes was never sent, so it fails and can be retried.
    $stuck = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000013']);
    test_seed($app, $stuck->requestId, $stuck->fingerprint, [['platform' => 'x', 'status' => 'queued']]);
    $db->table('deliveries')->where('post_id', $stuck->requestId)->update(['updated_at' => '2000-01-01T00:00:00Z']);
    check($publishing->find($stuck->requestId)['status'] === 'failed', 'A stuck delivery was not failed');
    check($publisher->calls === 2, 'Failing a stuck delivery sent it');

    $busy = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000003']);
    check(
        $publishing->create($busy)['status'] === 'published' && $publisher->calls === 3,
        'A post was not published',
    );
    $publishing->deliver($busy->requestId, 'x');
    check($publisher->calls === 3, 'A delivery was sent twice');

    refused(fn() => PostRequest::fromApi([...$payload, 'text' => '']), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([...$payload, 'platforms' => ['x', 'x']]), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([...$payload, 'requestId' => '../../secrets']), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([...$payload, 'images' => [['path' => '/etc/passwd']]]), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([
        ...$payload,
        'images' => [['data' => base64_encode('<script>not an image</script>')]],
    ]), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([...$payload, 'mode' => 'schedule', 'scheduledAt' => '2000-01-01T00:00:00Z']), Failure::INVALID);
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    $attachment = ['data' => base64_encode($bytes), 'mimeType' => 'image/png', 'alt' => 'Small fixture'];
    $imageOnly = PostRequest::fromApi([...$payload, 'text' => '', 'images' => [$attachment]]);
    check(
        $imageOnly->images[0]->width === 8 && $imageOnly->images[0]->bytes === $bytes,
        'Image data was not preserved',
    );
    refused(fn() => PostRequest::fromApi([
        ...$payload,
        'platforms' => ['dev-null'],
        'images' => array_fill(0, 4, $attachment),
    ]), Failure::INVALID);
    refused(fn() => PostRequest::fromApi([...$payload, 'images' => [[...$attachment, 'mimeType' => 'image/jpeg']]]), Failure::INVALID);
    file_put_contents($directory . '/fixture.png', $bytes);
    check(
        PostRequest::fromCli([...$payload, 'images' => [['path' => $directory . '/fixture.png']]])->images[0]->bytes === $bytes,
        'The command line could not attach an image by path',
    );

    $beforeRemote = new class($db) implements Publisher {
        public function __construct(
            private readonly Database $db,
        ) {}

        public function status(): array
        {
            return ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => true];
        }

        public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array
        {
            $deliveries = $this->db->table('deliveries')->where('post_id', $requestId)->orderBy('position')->get();
            check(
                array_column($deliveries, 'platform') === ['bluesky', 'x']
                && array_column($deliveries, 'status') === ['sending', 'queued'],
                'All destinations must be persisted before the first remote call',
            );
            check($images[0]->alt === 'Small fixture', 'A stored image lost its description');
            return ['platform' => 'bluesky', 'status' => 'published'];
        }
    };
    $multiple = test_publishing($app, ['bluesky' => $beforeRemote, 'x' => $publisher], $directory);
    check(
        $multiple->create(PostRequest::fromApi([
            ...$payload,
            'requestId' => '00000000-0000-4000-8000-000000000007',
            'platforms' => ['bluesky', 'x'],
            'images' => [$attachment],
        ]))['status'] === 'published',
        'A complete multi-destination result was not published',
    );

    // Drafts and edits send nothing until published, and sent posts are history.
    $draft = $publishing->create(PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000008', 'mode' => 'draft']));
    check($draft['status'] === 'draft' && $publisher->calls === 4, 'A draft was sent');
    $edited = $publishing->update($draft['id'], PostRequest::fromApi([...$payload, 'text' => 'edited', 'mode' => 'draft']));
    check($edited['text'] === 'edited' && $edited['status'] === 'draft', 'A draft could not be edited');
    check($publishing->publish($draft['id'])['status'] === 'published' && $publisher->calls === 5, 'A draft was not published');
    check($publishing->publish($draft['id'])['status'] === 'published' && $publisher->calls === 5, 'A sent draft was sent again');
    refused(fn() => $publishing->update($draft['id'], PostRequest::fromApi([...$payload, 'mode' => 'draft'])), Failure::CONFLICT);
    refused(fn() => $publishing->delete($draft['id']), Failure::CONFLICT);

    // Only failed destinations are retried, with their stored images.
    $publisher->failing = true;
    $failed = $publishing->create(PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000009']));
    check($failed['status'] === 'failed' && $publisher->calls === 6, 'A rejection was not recorded as failed');
    $publisher->failing = false;
    check($publishing->retry($failed['id'])['status'] === 'published' && $publisher->calls === 7, 'Retry did not resend a failure');
    refused(fn() => $publishing->retry($failed['id']), Failure::INVALID);

    // A scheduled post waits for its time, and is sent once.
    $later = new DateTimeImmutable('+1 hour');
    $scheduled = $publishing->create(PostRequest::fromApi([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-000000000010',
        'scheduledAt' => $later->format(DATE_ATOM),
    ]));
    check($scheduled['status'] === 'scheduled' && $publisher->calls === 7, 'A scheduled post was sent early');
    check($publishing->runDue() === [] && $publisher->calls === 7, 'A post was sent before its time');
    $due = $publishing->runDue($later->modify('+1 minute'));
    check(count($due) === 1 && $due[0]['status'] === 'published' && $publisher->calls === 8, 'A due post was not published');
    check($publishing->runDue($later->modify('+2 minutes')) === [] && $publisher->calls === 8, 'A due post was sent twice');
    $unscheduled = $publishing->create(PostRequest::fromApi([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-000000000011',
        'scheduledAt' => $later->format(DATE_ATOM),
    ]));
    check($publishing->schedule($unscheduled['id'], null)['status'] === 'draft', 'A post could not be unscheduled');
    $publishing->delete($unscheduled['id']);
    refused(fn() => $publishing->find($unscheduled['id']), Failure::NOT_FOUND);

    $page = $publishing->list(['published'], 2);
    check(count($page['posts']) === 2 && $page['next'] !== null, 'History did not page');
    check(count($publishing->list(['scheduled'])['posts']) === 0, 'History did not filter by status');

    // Videos: one per post and never beside images, only to sites that take them, within every site's length.
    $videoPublisher = new class implements Publisher {
        public int $calls = 0;
        public ?VideoAttachment $video = null;
        public array $images = [];

        public function status(): array
        {
            return ['id' => 'x', 'label' => 'X', 'available' => true, 'handle' => '@fixture'];
        }

        public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array
        {
            $this->calls++;
            $this->video = $video;
            $this->images = $images;
            return ['platform' => 'x', 'status' => 'published', 'url' => 'https://x.com/fixture/status/456'];
        }
    };
    $videos = test_publishing($app, ['x' => $videoPublisher], $directory);
    $clip = core_test_mp4(640, 360, 3000);
    $videoPost = [
        'requestId' => '00000000-0000-4000-8000-0000000000a1',
        'text' => 'A dummy clip',
        'platforms' => ['x'],
        'video' => ['data' => base64_encode($clip), 'mimeType' => 'video/mp4', 'alt' => 'Dummy clip'],
    ];
    $sent = $videos->create(PostRequest::fromApi($videoPost));
    check(
        $sent['status'] === 'published'
            && $videoPublisher->video?->bytes === $clip
            && $videoPublisher->video->mimeType === 'video/mp4'
            && $videoPublisher->video->filename === 'postslop-video.mp4'
            && $videoPublisher->video->alt === 'Dummy clip'
            && [$videoPublisher->video->width, $videoPublisher->video->height, $videoPublisher->video->milliseconds]
                === [640, 360, 3000]
            && $videoPublisher->images === [],
        'The publisher did not receive the stored video',
    );
    check(
        $sent['images'] === []
            && is_string($sent['video']['id'] ?? null)
            && array_diff_key($sent['video'], ['id' => true])
                === ['mimeType' => 'video/mp4', 'width' => 640, 'height' => 360, 'alt' => 'Dummy clip'],
        'A post did not present its video apart from its images',
    );
    check($videos->media($sent['video']['id'])['mimeType'] === 'video/mp4', 'The video could not be served');
    check(
        $videos->create(PostRequest::fromApi($videoPost)) === $sent && $videoPublisher->calls === 1,
        'Repeating a video post sent it twice',
    );
    refused(
        fn() => $videos->create(PostRequest::fromApi([...$videoPost, 'video' => [...$videoPost['video'], 'alt' => 'Changed']])),
        Failure::CONFLICT,
    );
    check(
        PostRequest::fromCli([
            ...$videoPost,
            'video' => ['path' => (static function () use ($directory): string {
                file_put_contents($directory . '/clip.mp4', core_test_mp4(1280, 720, 5000, true));
                return $directory . '/clip.mp4';
            })()],
        ])->video?->width === 720,
        'A video turned a quarter must report its shown size',
    );
    check(
        PostRequest::fromApi($payload)->fingerprint === hash('sha256', json_encode(['fixture', ['x'], []], JSON_THROW_ON_ERROR)),
        'Posts without a video must keep the fingerprint they were saved with',
    );
    $badVideos = [
        'beside images' => [...$videoPost, 'images' => [['data' => base64_encode($bytes), 'alt' => '']]],
        'to a site without videos' => [...$videoPost, 'platforms' => ['x', 'dev-null']],
        'too long for X' => [...$videoPost, 'video' => ['data' => base64_encode(core_test_mp4(640, 360, 150_000))]],
        'a mismatched type' => [...$videoPost, 'video' => [...$videoPost['video'], 'mimeType' => 'video/quicktime']],
        'an image as video' => [...$videoPost, 'video' => ['data' => base64_encode($bytes)]],
        'no video track' => [...$videoPost, 'video' => ['data' => base64_encode(substr($clip, 0, 32))]],
        'oversized data' => [...$videoPost, 'video' => ['data' => str_repeat('A', 27_962_032)]],
        'not an object' => [...$videoPost, 'video' => 'clip.mp4'],
    ];
    foreach ($badVideos as $case => $bad) {
        refused(fn() => PostRequest::fromApi($bad), Failure::INVALID);
        check($case !== '', 'Unnamed video case');
    }
    try {
        PostRequest::fromApi($badVideos['to a site without videos']);
    } catch (Failure $refusal) {
        check(str_contains($refusal->getMessage(), 'dev-null.sh'), 'A refused video must name the sites that take none');
    }
    $draft = $videos->create(PostRequest::fromApi([
        ...$videoPost,
        'requestId' => '00000000-0000-4000-8000-0000000000a2',
        'mode' => 'draft',
    ]));
    $videoFile = $directory . '/media/' . $draft['video']['id'] . '.mp4';
    check(is_file($videoFile), 'A draft video was not stored');
    $edited = $videos->update($draft['id'], PostRequest::fromApi([
        'requestId' => $draft['id'],
        'text' => 'Now without the clip',
        'platforms' => ['x'],
        'mode' => 'draft',
    ]));
    check($edited['video'] === null && !is_file($videoFile), 'Removing a video in an edit left it behind');
    $x = array_column($videos->accounts()['platforms'], null, 'id')['x'];
    check(
        $x['maxVideoSeconds'] === 140 && $videos->accounts()['limits']['maxVideoBytes'] === PostRequest::MAX_VIDEO_BYTES,
        'Accounts must say which sites take videos and how long',
    );

    // Images are taken as they are, above 1 MiB, and shrunk only for a site whose limits they exceed.
    $large = test_noise_png(700, 700);
    check(strlen($large) > 1_048_576 && strlen($large) <= PostRequest::MAX_IMAGE_BYTES, 'The large fixture is mis-sized');
    $largePost = [...$payload, 'images' => [['data' => base64_encode($large), 'mimeType' => 'image/png', 'alt' => 'Noise']]];
    check(PostRequest::fromApi($largePost)->images[0]->bytes === $large, 'An image over 1 MiB was refused or changed');
    refused(fn() => PostRequest::fromApi([
        ...$payload,
        'images' => [['data' => str_repeat('A', 6_990_512), 'alt' => '']],
    ]), Failure::INVALID);
    $wide = imagecreatetruecolor(6500, 10);
    imagealphablending($wide, false);
    imagesavealpha($wide, true);
    imagefill($wide, 0, 0, imagecolorallocatealpha($wide, 0, 0, 0, 127));
    ob_start();
    imagepng($wide);
    $widePng = ob_get_clean();
    $devNull = new class implements Publisher {
        public array $images = [];

        public function status(): array
        {
            return ['id' => 'dev-null', 'label' => 'dev-null.sh', 'available' => true, 'handle' => 'fixture'];
        }

        public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array
        {
            $this->images = $images;
            return ['platform' => 'dev-null', 'status' => 'published', 'url' => 'https://dev-null.sh/fixture'];
        }
    };
    $fitting = test_publishing($app, ['dev-null' => $devNull, 'x' => $videoPublisher], $directory);
    $fitted = $fitting->create(PostRequest::fromApi([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-0000000000b1',
        'platforms' => ['dev-null', 'x'],
        'images' => [
            ['data' => base64_encode($widePng), 'mimeType' => 'image/png', 'alt' => 'Wide'],
            ['data' => base64_encode($bytes), 'mimeType' => 'image/png', 'alt' => 'Small'],
        ],
    ]));
    [$shrunk, $kept] = $devNull->images;
    $pixel = imagecolorsforindex($decoded = imagecreatefromstring($shrunk->bytes), imagecolorat($decoded, 0, 0));
    check(
        $fitted['status'] === 'published'
            && $shrunk->mimeType === 'image/jpeg'
            && $shrunk->filename === 'postslop-1.jpg'
            && [$shrunk->width, $shrunk->height] === [6000, 9]
            && [imagesx($decoded), imagesy($decoded)] === [6000, 9]
            && $shrunk->alt === 'Wide'
            && $pixel['red'] > 250 && $pixel['green'] > 250 && $pixel['blue'] > 250,
        'An image wider than dev-null.sh takes was not shrunk to a white-backed JPEG of its size',
    );
    check(
        $kept->bytes === $bytes && $kept->mimeType === 'image/png' && $videoPublisher->images[0]->bytes === $widePng,
        'An image within a site\'s limits was changed',
    );

    // An unused video field with empty data is no video, even beside images.
    $placeholder = ['data' => '', 'mimeType' => 'video/mp4', 'alt' => ''];
    check(PostRequest::fromApi([...$payload, 'images' => [], 'video' => $placeholder])->video === null, 'An empty video was kept');
    check(
        count(PostRequest::fromApi([...$payload, 'images' => [$attachment], 'video' => $placeholder])->images) === 1,
        'An empty video was refused beside images',
    );

    foreach (glob($directory . '/media/*') as $file) {
        check((fileperms($file) & 0o777) === 0o600, 'Stored images are not private');
    }
    echo
        "Core checks passed: repeat/uncertain submission protection, crash recovery, per-site locking, drafts, scheduling, failed-only retries, input validation, images fitted to each site, videos, and private media.\n";
} finally {
    test_remove($directory);
}
