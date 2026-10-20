<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Failure;
use App\Core\PostRequest;
use App\Social\Publisher;
use Crab\Contracts\Database\Database;

// An assertion evaluates a boolean condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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

    public function publish(string $requestId, string $text, array $images): array
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

    $lock = fopen($directory . '/publishing.lock', 'c');
    flock($lock, LOCK_EX);
    $busy = PostRequest::fromApi([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000003']);
    refused(fn() => $publishing->create($busy), Failure::BUSY);
    check($publishing->find($busy->requestId)['status'] === 'draft', 'A busy publication lost the post');
    flock($lock, LOCK_UN);
    fclose($lock);
    check(
        $publishing->create($busy)['status'] === 'published' && $publisher->calls === 3,
        'Repeating a busy request did not publish its saved draft',
    );

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

        public function publish(string $requestId, string $text, array $images): array
        {
            $deliveries = $this->db->table('deliveries')->where('post_id', $requestId)->orderBy('position')->get();
            check(
                array_column($deliveries, 'platform') === ['bluesky', 'x']
                && array_column($deliveries, 'status') === ['unknown', 'failed'],
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

    foreach (glob($directory . '/media/*') as $file) {
        check((fileperms($file) & 0o777) === 0o600, 'Stored images are not private');
    }
    echo
        "Core checks passed: repeat/uncertain submission protection, crash recovery, locking, drafts, scheduling, failed-only retries, input validation, and private media.\n";
} finally {
    test_remove($directory);
}
