<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\PostInput;
use App\PrivateFiles;
use App\Publishing;
use App\Social\Publisher;
use Crab\Http\HttpException;

// An assertion evaluates a boolean condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function refused(callable $action, int $status): void
{
    $caught = null;
    try {
        $action();
    } catch (HttpException $error) {
        $caught = $error;
    }
    check($caught?->status === $status, 'Expected HTTP ' . $status);
}

$publisher = new class implements Publisher {
    public int $calls = 0;
    public bool $uncertain = false;

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
        return ['platform' => 'x', 'status' => 'published', 'url' => 'https://x.com/fixture/status/123'];
    }
};
$directory = sys_get_temp_dir() . '/postslop-core-' . bin2hex(random_bytes(6));
$publishing = new Publishing(['x' => $publisher], $directory);
$payload = [
    'requestId' => '00000000-0000-4000-8000-000000000001',
    'text' => 'fixture',
    'platforms' => ['x'],
    'images' => [],
];
try {
    $input = PostInput::from($payload);
    $first = $publishing->publish($input);
    $second = $publishing->publish($input);
    check($first === $second && $publisher->calls === 1, 'A repeated Enter published twice');
    refused(fn() => $publishing->publish(PostInput::from([...$payload, 'text' => 'different'])), 409);
    $publisher->uncertain = true;
    $uncertain = PostInput::from([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000002']);
    check($publishing->publish($uncertain)['status'] === 'unknown', 'Lost outcomes must be unknown');
    $publishing->publish($uncertain);
    check($publisher->calls === 2, 'An uncertain submission was retried');
    $interrupted = PostInput::from([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-000000000004',
        'platforms' => ['bluesky', 'x'],
    ]);
    $interruptedResults = [
        ['platform' => 'bluesky', 'status' => 'unknown', 'error' => 'Submission interrupted.'],
        ['platform' => 'x', 'status' => 'failed', 'error' => 'Not attempted.'],
    ];
    PrivateFiles::json($directory . '/' . $interrupted->requestId . '.json', [
        'fingerprint' => $interrupted->fingerprint,
        'status' => 'failed',
        'results' => $interruptedResults,
    ]);
    $recovered = $publishing->publish($interrupted);
    check(
        $recovered['status'] === 'unknown' && $recovered['results'] === $interruptedResults,
        'Crash recovery lost an unattempted destination',
    );
    check($publisher->calls === 2, 'Crash recovery attempted an unstarted destination');
    $completed = PostInput::from([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000005']);
    PrivateFiles::json($directory . '/' . $completed->requestId . '.json', [
        'fingerprint' => $completed->fingerprint,
        'status' => 'unknown',
        'results' => [['platform' => 'x', 'status' => 'published', 'url' => 'https://x.com/fixture/status/456']],
    ]);
    check(
        $publishing->find($completed->requestId)['status'] === 'published',
        'Confirmed publication retained a stale unknown summary',
    );
    check(
        $publishing->publish($completed)['status'] === 'published' && $publisher->calls === 2,
        'Confirmed crash recovery repeated publication',
    );
    $unstarted = PostInput::from([...$payload, 'requestId' => '00000000-0000-4000-8000-000000000006']);
    PrivateFiles::json($directory . '/' . $unstarted->requestId . '.json', [
        'fingerprint' => $unstarted->fingerprint,
        'status' => 'unknown',
        'results' => [['platform' => 'x', 'status' => 'failed', 'error' => 'Not attempted.']],
    ]);
    check(
        $publishing->publish($unstarted)['status'] === 'failed' && $publisher->calls === 2,
        'An unstarted receipt was retried',
    );
    check($publishing->status()['platforms'][0]['handle'] === 'fixture', 'Handle normalization failed');
    $lock = fopen($directory . '/publishing.lock', 'c');
    flock($lock, LOCK_EX);
    refused(fn() => $publishing->publish(PostInput::from([
        ...$payload,
        'requestId' => '00000000-0000-4000-8000-000000000003',
    ])), 409);
    flock($lock, LOCK_UN);
    fclose($lock);
    refused(fn() => PostInput::from([...$payload, 'text' => '']), 422);
    refused(fn() => PostInput::from([...$payload, 'platforms' => ['x', 'x']]), 422);
    refused(fn() => PostInput::from([...$payload, 'requestId' => '../../secrets']), 422);
    refused(fn() => PostInput::from([
        ...$payload,
        'images' => [['data' => base64_encode('<script>not an image</script>')]],
    ]), 422);
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    $attachment = ['data' => base64_encode($bytes), 'mimeType' => 'image/png', 'alt' => 'Small fixture'];
    $imageOnly = PostInput::from([...$payload, 'text' => '', 'images' => [$attachment]]);
    check(
        $imageOnly->images[0]->width === 8 && $imageOnly->images[0]->bytes === $bytes,
        'Image data was not preserved',
    );
    refused(fn() => PostInput::from([
        ...$payload,
        'platforms' => ['dev-null'],
        'images' => array_fill(0, 4, $attachment),
    ]), 422);
    refused(fn() => PostInput::from([...$payload, 'images' => [[...$attachment, 'mimeType' => 'image/jpeg']]]), 422);
    $beforeRemote = new class($directory) implements Publisher {
        public function __construct(
            private readonly string $directory,
        ) {}

        public function status(): array
        {
            return ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => true];
        }

        public function publish(string $requestId, string $text, array $images): array
        {
            $receipt = json_decode(file_get_contents($this->directory . '/' . $requestId . '.json'), true);
            check(
                array_column($receipt['results'], 'platform') === ['bluesky', 'x']
                && array_column($receipt['results'], 'status') === ['unknown', 'failed'],
                'All destinations must be persisted before the first remote call',
            );
            return ['platform' => 'bluesky', 'status' => 'published'];
        }
    };
    $publisher->uncertain = false;
    $multiple = new Publishing(['bluesky' => $beforeRemote, 'x' => $publisher], $directory);
    check(
        $multiple->publish(PostInput::from([
            ...$payload,
            'requestId' => '00000000-0000-4000-8000-000000000007',
            'platforms' => ['bluesky', 'x'],
        ]))['status'] === 'published',
        'A complete multi-destination result was not published',
    );
    foreach (glob($directory . '/*.json') as $file) {
        check((fileperms($file) & 0o777) === 0o600, 'Publication history is not private');
    }
    echo
        "Core checks passed: repeat/uncertain submission protection, crash recovery, locking, input validation, and private receipts.\n";
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
