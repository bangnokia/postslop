<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app.php';

use App\PrivateFiles;
use App\Publishing;
use App\Social\DevNullPublisher;
use App\Social\SessionStore;
use Crab\HttpClient\FakeClient;

// This helper asserts a condition rather than choosing a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function review_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/postslop-review-' . bin2hex(random_bytes(6));
$sessions = new SessionStore($directory . '/sessions');
$sessions->write('dev-null', [
    'cookies' => [[
        'name' => 'devnull-session',
        'value' => 'fixture-only',
        'domain' => 'dev-null.sh',
        'path' => '/',
        'expires' => -1,
        'secure' => true,
    ]],
    'origins' => [],
]);
PrivateFiles::json($directory . '/sessions/manifest.json', ['accounts' => ['dev-null' => ['handle' => 'fixture']]]);
$id = '11111111-2222-4333-8444-555555555555';
$requestId = '00000000-0000-4000-8000-000000000001';
$url = 'https://dev-null.sh/@fixture/commits/' . $id;
$remote = ['status' => 'pending', 'author' => 'fixture', 'deleted' => null, 'http' => 200];
$reads = 0;
$http = new FakeClient([
    'GET https://dev-null.sh/settings/profile' => [
        'version' => 'fixture-v1',
        'props' => ['auth' => ['user' => ['id' => 'fixture-user', 'username' => 'fixture']]],
    ],
    'GET ' . $url => static function () use (&$remote, &$reads, $id) {
        $reads++;
        return FakeClient::response($remote['http'], [
            'props' => [
                'commit' => [
                    'id' => $id,
                    'user' => ['username' => $remote['author']],
                    'deleted_at' => $remote['deleted'],
                    'status' => $remote['status'],
                ],
            ],
        ]);
    },
], redirects: 0);
$dev = new DevNullPublisher($http, $sessions);
$publishing = new Publishing(['dev-null' => $dev], $directory . '/posts');
$pending = [
    'platform' => 'dev-null',
    'url' => $url,
    'status' => 'unknown',
    'pendingReview' => true,
    'error' => 'Awaiting moderation.',
];
$receipt = [
    'requestId' => $requestId,
    'fingerprint' => 'fixture',
    'status' => 'partial',
    'results' => [['platform' => 'bluesky', 'status' => 'published'], $pending],
];
$path = $directory . '/posts/' . $requestId . '.json';
try {
    PrivateFiles::json($path, $receipt);
    $result = $publishing->find($requestId);
    review_check(
        $result['status'] === 'partial' && $result['results'][1]['pendingReview'],
        'Pending moderation was falsely confirmed',
    );

    $remote['status'] = 'approved';
    $result = $publishing->find($requestId);
    review_check(
        $result['status'] === 'published' && !array_key_exists('pendingReview', $result['results'][1]),
        'Approval did not resolve the saved result',
    );
    review_check(json_decode(file_get_contents($path), true)['status'] === 'published', 'Approval was not persisted');
    $before = $reads;
    $publishing->find($requestId);
    review_check($reads === $before, 'A confirmed result was unnecessarily checked again');

    PrivateFiles::json($path, $receipt);
    $remote['status'] = 'rejected';
    $result = $publishing->find($requestId)['results'][1];
    review_check(
        $result['status'] === 'failed'
        && !array_key_exists('pendingReview', $result)
        && str_contains($result['error'], 'moderation'),
        'Moderation rejection was not distinguished',
    );

    PrivateFiles::json($path, $receipt);
    $remote['status'] = 'approved';
    $remote['author'] = 'different-user';
    $result = $publishing->find($requestId)['results'][1];
    review_check($result['status'] === 'unknown' && $result['pendingReview'], 'A different author was accepted');

    $remote['author'] = 'fixture';
    $remote['deleted'] = '2026-10-20T00:00:00Z';
    $result = $publishing->find($requestId)['results'][1];
    review_check($result['status'] === 'unknown' && $result['pendingReview'], 'A deleted post was accepted');

    $remote['deleted'] = null;
    $remote['http'] = 503;
    $result = $publishing->find($requestId)['results'][1];
    review_check($result['status'] === 'unknown' && $result['pendingReview'], 'A failed read discarded pending review');

    $lock = fopen($directory . '/posts/publishing.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $before = count($http->requests);
        $publishing->find($requestId);
        review_check(count($http->requests) === $before, 'Result checking ignored the publication lock');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $remote['http'] = 404;
    $result = $publishing->find($requestId)['results'][1];
    review_check(
        $result['status'] === 'unknown'
        && $result['unavailable'] === true
        && !array_key_exists('pendingReview', $result)
        && str_contains($result['error'], 'HTTP 404')
        && !str_contains($result['error'], 'rejected'),
        'A missing saved post was incorrectly labeled pending or rejected',
    );
    $before = $reads;
    $publishing->find($requestId);
    review_check($reads === $before, 'An unavailable post kept polling as pending moderation');

    $before = count($http->requests);
    $invalid = $dev->verifySaved([...$pending, 'url' => 'https://other.example/steal-session']);
    review_check(
        $invalid['status'] === 'unknown' && count($http->requests) === $before,
        'An unrelated URL triggered a remote request',
    );
    review_check($http->sent('POST *') === [], 'Checking a result submitted content');
    echo
        "dev-null review checks passed: pending, approval, rejection, identity, deletion, read failure, unavailable posts, locking, and no resubmission.\n";
} finally {
    foreach (['posts', 'sessions'] as $folder) {
        foreach (glob($directory . '/' . $folder . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory . '/' . $folder);
    }
    rmdir($directory);
}
