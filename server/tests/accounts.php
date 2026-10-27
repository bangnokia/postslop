<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\PostRequest;
use App\Core\Publishing;
use App\Social\DevNullPublisher;
use App\Social\SessionStore;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\HttpClient\Client;
use Crab\Testing\Api;

function account_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/postslop-accounts-' . bin2hex(random_bytes(6));
$fake = proc_open(['node', __DIR__ . '/fakes/server.mjs', '--port=0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
account_check(is_resource($fake), 'Could not start fake sites');
stream_set_timeout($pipes[1], 10);
$line = fgets($pipes[1]);
try {
    account_check(is_string($line) && preg_match('~http://127\.0\.0\.1:([0-9]+)~', $line, $port) === 1, 'Fake sites did not start');
    $base = 'http://127.0.0.1:' . $port[1];
    $url = $base . '/dev-null';
    putenv('POSTSLOP_DEV_NULL_URL=' . $url);
    putenv('POSTSLOP_THREADS_URL=' . $base . '/threads');
    putenv('POSTSLOP_TOKEN=accounts-test-token');
    $app = test_app($directory);
    $http = $app->container->get(Client::class);
    $sessions = $app->container->get(SessionStore::class);
    $sessions->import(['dev-null' => ['handle' => 'tester', 'session' => ['cookies' => [[
        'name' => 'dev_null_session', 'value' => 'fixture-session', 'domain' => '127.0.0.1', 'path' => '/', 'expires' => -1,
    ]], 'origins' => []]], 'threads' => ['handle' => 'tester', 'session' => ['cookies' => array_map(static fn(array $pair): array => [
        'name' => $pair[0], 'value' => $pair[1], 'domain' => '.threads.com', 'path' => '/', 'expires' => -1,
    ], [['sessionid', 'fixture-threads-session'], ['csrftoken', 'fixture-threads-csrf'], ['ds_user_id', '12345']]), 'origins' => []]]], 'test');
    $core = $app->container->get(Publishing::class);
    $publisher = $app->container->get(DevNullPublisher::class);
    $reset = static fn(string $scenario = '') => $http->post($url . '/__reset', json: ['scenario' => $scenario]);
    $stats = static fn(): array => $http->get($url . '/__stats')->json();
    $api = new Api($app)->withToken('accounts-test-token');

    // A targeted check must not touch another site, including when its cache is cold.
    $reset();
    $api->post('/api/v1/accounts/threads/check', [])->assertStatus(200)->assertJson('available', true);
    account_check($stats()['checks'] === 0, 'Checking Threads also contacted dev-null');
    $account = $api->post('/api/v1/accounts/dev-null/check', [])->assertStatus(200)->assertJson('available', true)->json();
    account_check($account['handle'] === 'tester' && strtotime($account['checkedAt']) !== false, 'Account context missing');
    $core->accounts();
    account_check($stats()['checks'] === 1, 'Reading accounts ignored the individual check cache');
    $api->post('/api/v1/accounts/threads/check', [])->assertStatus(200);
    account_check($stats()['checks'] === 1, 'Checking Threads refreshed another cached site');
    $app->container->get(Cache::class)->clear();
    $api->post('/api/v1/accounts/threads/check', [])->assertStatus(200);
    account_check($stats()['checks'] === 1, 'Checking Threads refreshed another uncached site');
    new Api($app)->post('/api/v1/accounts/dev-null/check', [])->assertStatus(401);
    $api->post('/api/v1/accounts/mastodon/check', [])->assertStatus(404);
    account_check($stats()['checks'] === 1, 'Unauthorized or invalid checks contacted a site');

    foreach (['', 'legacy'] as $scenario) {
        $reset($scenario);
        account_check($publisher->status()['available'], 'dev-null handle was not recognized');
        $post = $core->create(PostRequest::fromApi(['text' => 'Fixture account check', 'platforms' => ['dev-null']]));
        account_check($post['status'] === 'published' && count($stats()['posts']) === 1, 'dev-null post identity was not verified');
    }
    foreach (['missing-handle', 'missing-user', 'wrong-handle', 'blocked'] as $scenario) {
        $reset($scenario);
        $status = $publisher->status();
        account_check(!$status['available'] && is_string($status['detail'] ?? null), 'Missing diagnostic for ' . $scenario);
        account_check(str_contains($status['reason'], 'does not match') === ($scenario === 'wrong-handle'), 'Missing identity was mislabeled a mismatch');
        account_check(!str_contains(json_encode($status), 'fixture-session'), 'Diagnostics exposed private response data');
        $post = $core->create(PostRequest::fromApi(['text' => 'Do not submit this fixture', 'platforms' => ['dev-null']]));
        account_check($post['status'] === 'failed' && $stats()['posts'] === [], 'Unverified account submitted a post');
    }
    $reset('wrong-author');
    $post = $core->create(PostRequest::fromApi(['text' => 'Uncertain author fixture', 'platforms' => ['dev-null']]));
    account_check($post['status'] === 'unknown', 'A different author was accepted as confirmed publication');
    $reset();
    $core->setEnabled('dev-null', false);
    $before = $stats()['checks'];
    $off = $api->post('/api/v1/accounts/dev-null/check', [])->assertStatus(200)->json();
    account_check(!$off['enabled'] && $off['checkedAt'] === null && $stats()['checks'] === $before, 'Disabled account was checked');
    echo "Account checks passed: individual refresh, caching, authorization, check context, dev-null handle changes, identity refusals, and post verification.\n";
} finally {
    proc_terminate($fake);
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($fake);
    test_remove($directory);
}
