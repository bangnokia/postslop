<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\PrivateFiles;
use App\Social\ImageAttachment;
use App\Social\SessionStore;
use App\Social\XBrowserPublisher;
use Crab\Engine\EventLoop;

// An assertion evaluates a condition rather than selecting behavior.
// @mago-expect lint:no-boolean-flag-parameter
function browser_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$configuredNode = getenv('POSTSLOP_NODE_BINARY');
$node = $configuredNode === false ? '' : $configuredNode;
if ($node === '') {
    foreach ([...explode(PATH_SEPARATOR, (string) getenv('PATH')), '/opt/homebrew/bin'] as $directory) {
        $candidate = $directory . '/node';
        if (is_file($candidate) && is_executable($candidate)) {
            $node = $candidate;
            break;
        }
    }
}
browser_check(is_executable($node), 'Set POSTSLOP_NODE_BINARY to the installed Node executable.');
$directory = sys_get_temp_dir() . '/postslop-browser-' . bin2hex(random_bytes(6));
$sessions = new SessionStore($directory . '/sessions.json');
$sessions->import(['x' => ['handle' => 'tester', 'session' => ['cookies' => []]]], 'test');
$workerPath = $directory . '/fixture worker.mjs';
file_put_contents($workerPath, <<<'JS'
    import { readFileSync, writeFileSync } from 'node:fs';
    import { createHash } from 'node:crypto';
    const scenario = JSON.parse(readFileSync(new URL('./scenario.json', import.meta.url)));
    let raw = '';
    for await (const chunk of process.stdin) raw += chunk;
    const input = JSON.parse(raw);
    writeFileSync(new URL('./received.json', import.meta.url), JSON.stringify({
      operation: input.operation,
      expectedHandle: input.expectedHandle,
      session: input.session,
      profilePath: input.profilePath,
      baseUrl: input.baseUrl,
      text: input.text,
      images: input.images?.map(image => ({
        filename: image.filename, mimeType: image.mimeType, alt: image.alt,
        sha256: createHash('sha256').update(Buffer.from(image.data, 'base64')).digest('hex'),
      })),
    }));
    if (scenario.mode === 'hang' || scenario.mode === 'ignore-term') {
      writeFileSync(new URL('./pid', import.meta.url), String(process.pid));
      process.on('SIGTERM', () => {
        writeFileSync(new URL('./terminated', import.meta.url), 'closed');
        if (scenario.mode !== 'ignore-term') process.exit(0);
      });
      setInterval(() => {}, 1000);
    } else {
      if (scenario.delay) await new Promise(resolve => setTimeout(resolve, scenario.delay));
      if (scenario.mode === 'stderr') process.stderr.write('private-fixture-cookie'.repeat(4000));
      process.stdout.write(scenario.raw ?? JSON.stringify(scenario.result));
      process.exitCode = scenario.exitCode ?? 0;
    }
    JS);
chmod($workerPath, 0o600);
$scenarioPath = $directory . '/scenario.json';
$publisher = new XBrowserPublisher(
    $sessions,
    $node,
    $workerPath,
    $directory . '/profile',
    timeoutSeconds: 3,
    baseUrl: 'http://127.0.0.1:12345/x',
);
$published = ['platform' => 'x', 'status' => 'published', 'url' => 'https://x.com/tester/status/123'];
$requestId = '00000000-0000-4000-8000-000000000001';

try {
    PrivateFiles::json($scenarioPath, ['result' => [
        'id' => 'x',
        'label' => 'X',
        'available' => true,
        'handle' => '@TESTER',
    ]]);
    browser_check($publisher->status()['handle'] === '@tester', 'Verified identity was not normalized.');
    $received = json_decode(file_get_contents($directory . '/received.json'), true);
    browser_check(
        $received['operation'] === 'status'
        && $received['expectedHandle'] === 'tester'
        && $received['session'] === ['cookies' => []]
        && $received['profilePath'] === $directory . '/profile'
        && $received['baseUrl'] === 'http://127.0.0.1:12345/x',
        'The worker did not receive its isolated session/profile configuration.',
    );

    foreach ([
        ['id' => 'x', 'available' => true, 'handle' => '@someone_else'],
        ['id' => 'bluesky', 'available' => true, 'handle' => '@tester'],
        ['id' => 'x', 'available' => 'true', 'handle' => '@tester'],
    ] as $status) {
        PrivateFiles::json($scenarioPath, ['result' => $status]);
        browser_check($publisher->status()['available'] === false, 'An unverified account was marked connected.');
    }

    PrivateFiles::json($scenarioPath, ['result' => [
        'id' => 'x',
        'available' => false,
        'code' => 'signed_out',
        'reason' => 'private-fixture-cookie',
    ]]);
    browser_check(
        str_contains($publisher->status()['reason'], 'signed out')
        && !str_contains($publisher->status()['reason'], 'private-fixture-cookie'),
        'The worker error was not replaced by a safe diagnostic.',
    );

    $bytes = str_repeat("\0\xFFimage", 100_000);
    $image = new ImageAttachment('paste image.png', 'image/png', $bytes, 'Clipboard image 🦀', 1, 1);
    $text = 'Line one' . "\n" . '$(touch should-not-exist); `touch should-not-exist`';
    PrivateFiles::json($scenarioPath, ['result' => $published, 'delay' => 180]);
    $ticks = 0;
    $loop = EventLoop::current();
    $timer = $loop->repeat(0.01, static function () use (&$ticks): void {
        $ticks++;
    });
    try {
        browser_check(
            $publisher->publish($requestId, $text, [$image]) === $published,
            'Publication confirmation was lost.',
        );
    } finally {
        $loop->cancel($timer);
    }
    browser_check($ticks >= 5, 'Waiting for the real child process blocked the HTTP event loop.');
    $received = json_decode(file_get_contents($directory . '/received.json'), true);
    browser_check($received['text'] === $text, 'Multiline text changed across the child-process boundary.');
    browser_check(
        $received['images'] === [[
            'filename' => $image->filename,
            'mimeType' => $image->mimeType,
            'alt' => $image->alt,
            'sha256' => hash('sha256', $bytes),
        ]],
        'Large clipboard image bytes or alt text changed across the child-process boundary.',
    );
    browser_check(!file_exists($directory . '/should-not-exist'), 'Post text was interpreted as shell code.');

    foreach ([
        'https://x.com/someone_else/status/123',
        'https://x.com/tester/status/123?utm_source=fixture',
        'https://x.com.evil.test/tester/status/123',
        'http://x.com/tester/status/123',
        'https://x.com/tester/status/not-a-post',
    ] as $url) {
        PrivateFiles::json($scenarioPath, ['result' => [...$published, 'url' => $url]]);
        browser_check(
            $publisher->publish($requestId, 'fixture', [])['status'] === 'unknown',
            'An unsafe result URL was accepted.',
        );
    }

    foreach ([
        ['raw' => 'not JSON'],
        ['raw' => '{}{}'],
        ['raw' => '[]'],
        ['raw' => str_repeat('x', 70_000)],
        ['result' => $published, 'mode' => 'stderr'],
        ['result' => $published, 'exitCode' => 1],
        ['result' => [...$published, 'platform' => 'bluesky']],
        ['result' => ['platform' => 'x', 'status' => 'failed', 'error' => 'private-fixture-cookie']],
    ] as $scenario) {
        PrivateFiles::json($scenarioPath, $scenario);
        $result = $publisher->publish($requestId, 'fixture', []);
        browser_check($result['status'] === 'unknown', 'A broken worker response became retryable or confirmed.');
        browser_check(!str_contains($result['error'], 'private-fixture-cookie'), 'Worker diagnostics leaked.');
    }
    PrivateFiles::json($scenarioPath, ['result' => [
        'platform' => 'x',
        'status' => 'failed',
        'code' => 'busy',
        'error' => 'private-fixture-cookie',
    ]]);
    $busy = $publisher->publish($requestId, 'fixture', []);
    browser_check(
        $busy['status'] === 'failed' && str_contains($busy['error'], 'already'),
        'A known pre-submit failure was lost.',
    );

    PrivateFiles::json($scenarioPath, ['mode' => 'hang']);
    $shortDeadline = new XBrowserPublisher(
        $sessions,
        $node,
        $workerPath,
        $directory . '/profile',
        timeoutSeconds: 0.25,
    );
    $started = microtime(true);
    browser_check(
        $shortDeadline->publish($requestId, 'fixture', [])['status'] === 'unknown',
        'Timed-out submission became retryable.',
    );
    browser_check((microtime(true) - $started) < 1.5, 'The worker deadline did not bound process cleanup.');
    browser_check(file_exists($directory . '/terminated'), 'The timed-out worker was not asked to clean up.');
    if (function_exists('posix_kill')) {
        browser_check(
            !posix_kill((int) file_get_contents($directory . '/pid'), 0),
            'The timed-out child is still running.',
        );
    }

    PrivateFiles::json($scenarioPath, ['mode' => 'ignore-term']);
    $started = microtime(true);
    browser_check(
        $shortDeadline->publish($requestId, 'fixture', [])['status'] === 'unknown',
        'A worker ignoring SIGTERM became retryable.',
    );
    browser_check((microtime(true) - $started) < 3.5, 'A worker ignoring SIGTERM was not killed within its deadline.');
    if (function_exists('posix_kill')) {
        browser_check(
            !posix_kill((int) file_get_contents($directory . '/pid'), 0),
            'The unresponsive worker was not killed.',
        );
    }
    $missingNode = new XBrowserPublisher(
        $sessions,
        $directory . '/missing-node',
        $workerPath,
        $directory . '/profile',
    );
    browser_check($missingNode->status()['available'] === false, 'A missing runtime was marked available.');
    browser_check(
        $missingNode->publish($requestId, 'fixture', [])['status'] === 'unknown',
        'An abnormal process start was treated as a confirmed outcome.',
    );

    PrivateFiles::json($directory . '/sessions.json', ['accounts' => ['x' => ['handle' => 'tester']]]);
    browser_check($publisher->status()['available'] === false, 'A missing session was marked connected.');
    browser_check(
        $publisher->publish($requestId, 'fixture', [])['status'] === 'failed',
        'A missing session attempted publication.',
    );
    browser_check($loop->getIdentifiers() === [], 'The process bridge retained event-loop watchers.');
    echo
        "X browser bridge checks passed: identity, image/text transport, responsive event loop, bounded output, safe errors, uncertain outcomes, and process cleanup.\n";
} finally {
    $files = glob($directory . '/*');
    foreach ($files === false ? [] : $files as $path) {
        unlink($path);
    }
    rmdir($directory);
}
