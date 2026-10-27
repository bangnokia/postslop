<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Failure;
use App\Core\PostRequest;
use App\Core\Publishing;
use App\Social\SessionStore;
use App\Social\ThreadsPublisher;
use Crab\Contracts\HttpClient\Client;
use Crab\Queue\Consumer;
use Crab\Testing\Api;

function threads_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/postslop-threads-' . bin2hex(random_bytes(6));
$fake = proc_open(['node', __DIR__ . '/fakes/server.mjs', '--port=0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
threads_check(is_resource($fake), 'Could not start fake sites');
stream_set_timeout($pipes[1], 10);
$line = fgets($pipes[1]);
try {
    threads_check(is_string($line) && preg_match('~http://127\.0\.0\.1:([0-9]+)~', $line, $port) === 1, 'Fake sites did not start');
    $url = 'http://127.0.0.1:' . $port[1] . '/threads';
    putenv('POSTSLOP_THREADS_URL=' . $url);
    putenv('POSTSLOP_TOKEN=threads-test-token');
    $app = test_app($directory);
    $http = $app->container->get(Client::class);
    $sessions = $app->container->get(SessionStore::class);
    $state = ['cookies' => array_map(static fn(array $cookie): array => [
        'name' => $cookie[0], 'value' => $cookie[1], 'domain' => '.threads.com', 'path' => '/', 'expires' => -1,
    ], [
        ['sessionid', 'fixture-threads-session'], ['csrftoken', 'fixture-threads-csrf'], ['ds_user_id', '12345'],
    ]), 'origins' => []];
    $state['cookies'][] = ['name' => 'foreign', 'value' => 'private', 'domain' => '.instagram.com', 'expires' => -1];
    $state['cookies'][] = ['name' => 'expired', 'value' => 'private', 'domain' => '.threads.com', 'expires' => time() - 100];
    $state['cookies'][] = ['name' => 'scoped', 'value' => 'private', 'domain' => '.threads.com', 'path' => '/other', 'expires' => -1];
    $import = static fn(array $session) => $sessions->import(['threads' => ['handle' => 'tester', 'session' => $session]], 'test');
    $import($state);
    $publisher = new ThreadsPublisher($http, $sessions);
    $core = $app->container->get(Publishing::class);
    $reset = static fn(string $scenario = '') => $http->post($url . '/__reset', json: ['scenario' => $scenario]);
    $stats = static fn(): array => $http->get($url . '/__stats')->json();
    $reset();
    threads_check($publisher->status()['available'], 'Threads account did not verify');
    foreach (['session-cleared', 'session-expired-date', 'expired', 'check-blocked', 'check-rate-limit', 'page-changed'] as $scenario) {
        $reset($scenario);
        $status = $publisher->status();
        threads_check(!$status['available'] && is_string($status['detail'] ?? null), 'Missing account diagnostics for ' . $scenario);
        threads_check(!str_contains(json_encode($status), 'fixture-threads-session'), 'Account diagnostics exposed private response data');
        if (str_starts_with($scenario, 'session-')) {
            threads_check(str_contains($status['reason'], 'rejected the saved session'), 'Session rejection was not explained');
        } else {
            threads_check(!str_contains($status['reason'], 'rejected the saved session'), 'An unverified response was reported as a rejected session');
        }
    }
    $reset();
    $accounts = $core->accounts()['platforms'];
    $account = array_values(array_filter($accounts, static fn(array $item): bool => $item['id'] === 'threads'))[0];
    threads_check($account['available'] && $account['textLimit'] === 500 && $account['maxImages'] === 4, 'Threads capabilities were not exposed');

    $image = imagecreatetruecolor(128, 128);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $attachment = ['data' => base64_encode($png), 'mimeType' => 'image/png', 'alt' => 'A fixture image'];
    foreach ([[], [$attachment], [$attachment, [...$attachment, 'alt' => 'Second image']]] as $images) {
        $reset();
        $request = PostRequest::fromApi(['text' => 'Threads fixture: café & a link https://example.com/', 'platforms' => ['threads'], 'images' => $images]);
        $post = $core->create($request);
        threads_check($post['status'] === 'published', 'Threads text/image publication failed: ' . json_encode($post['results']));
        threads_check($post['results'][0]['url'] === 'https://www.threads.com/@tester/post/Fixture_1', 'Post URL was not clean');
        threads_check($core->create($request) === $post && $stats()['submissions'] === 1, 'Repeated request published twice');
        foreach ($stats()['uploads'] as $upload) {
            threads_check($upload['sha256'] === hash('sha256', $png), 'Image bytes changed');
            threads_check($upload['sidecar'] === (count($images) > 1 ? '1' : '0'), 'Wrong image upload mode');
        }
    }
    $reset();
    $onlyImage = $core->create(PostRequest::fromApi(['text' => '', 'platforms' => ['threads'], 'images' => [$attachment]]));
    threads_check($onlyImage['status'] === 'published', 'Image-only post failed');
    $reset();
    threads_check($publisher->publish(PostRequest::uuid(), str_repeat('é', 500), [])['status'] === 'published', '500-character post failed');
    threads_check($publisher->publish(PostRequest::uuid(), str_repeat('é', 501), [])['status'] === 'failed' && $stats()['submissions'] === 1, 'Overlong text was sent');

    foreach (['wrong-viewer', 'wrong-profile-id', 'wrong-handle', 'missing-viewer', 'missing-profile', 'expired', 'query-changed', 'upload-failed'] as $scenario) {
        $reset($scenario);
        $request = PostRequest::fromApi(['text' => 'Do not submit', 'platforms' => ['threads'], 'images' => [$attachment]]);
        threads_check($core->create($request)['status'] === 'failed', $scenario . ' was not a safe failure');
        threads_check($stats()['submissions'] === 0, $scenario . ' submitted a post');
    }
    $reset();
    $import([...$state, 'cookies' => array_filter($state['cookies'], static fn(array $cookie): bool => $cookie['name'] !== 'sessionid')]);
    threads_check(!$publisher->status()['available'], 'Missing authentication cookie accepted');
    $import($state);

    foreach (['rate-limit', 'rejected'] as $scenario) {
        $reset($scenario);
        $request = PostRequest::fromApi(['text' => 'A refused post', 'platforms' => ['threads']]);
        $post = $core->create($request);
        threads_check($post['status'] === 'failed' && $stats()['submissions'] === 1, 'A rejection was retried');
        threads_check(!str_contains(json_encode($post), 'fixture-threads-session'), 'An error exposed a session value');
        $reset();
        threads_check($core->retry($post['id'])['status'] === 'published' && $stats()['submissions'] === 1, 'Explicit retry of a refused post failed');
    }
    foreach (['unknown', 'timeout', 'disconnect', 'malformed', 'wrong-author', 'wrong-text', 'missing-image', 'missing-alt', 'unsafe-code'] as $scenario) {
        $reset($scenario);
        $request = PostRequest::fromApi(['text' => 'An uncertain post', 'platforms' => ['threads'], 'images' => [$attachment]]);
        $post = $core->create($request);
        threads_check($post['status'] === 'unknown', $scenario . ' was not kept uncertain');
        $core->create($request);
        $refused = false;
        try { $core->retry($post['id']); } catch (Failure $error) { $refused = $error->kind === Failure::INVALID; }
        threads_check($refused && $stats()['submissions'] === 1 && count($stats()['posts']) === 1, 'Uncertain post was resent');
        threads_check(count($core->find($post['id'])['images']) === 1, 'Uncertain post lost its attachment');
    }

    $reset();
    $api = new Api($app)->withHeaders(['Host' => '127.0.0.1:8087'])->withToken('threads-test-token');
    $api->put('/api/v1/accounts/threads', ['handle' => 'tester', 'session' => $state, 'source' => 'firefox'])->assertStatus(200);
    $post = $api->post('/api/v1/posts', ['text' => 'Queued through HTTP', 'platforms' => ['threads']])->assertStatus(200)->json();
    threads_check($post['results'][0]['status'] === 'queued' && $stats()['submissions'] === 0, 'HTTP publication did not queue');
    $consumer = $app->container->get(Consumer::class);
    threads_check(!$consumer->runNext(['x']) && $consumer->runNext(['threads']), 'Threads did not use its own queue');
    $api->get('/api/v1/posts/' . $post['id'])->assertJson('status', 'published');
    $api->patch('/api/v1/accounts/threads', ['enabled' => false])->assertStatus(200);
    threads_check(!in_array('threads', $core->defaultPlatforms(), true), 'Disabled Threads stayed selected');
    $blocked = $core->create(PostRequest::fromApi(['text' => 'Disabled', 'platforms' => ['threads']]));
    threads_check($blocked['status'] === 'failed' && $stats()['submissions'] === 1, 'Disabled account published');
    $api->patch('/api/v1/accounts/threads', ['enabled' => true])->assertStatus(200);

    // The real CLI must save a draft unless --now is explicit, even with Threads selected.
    $command = static function (array $args): array {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/postslop', ...$args, '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $stdio);
        $output = stream_get_contents($stdio[1]);
        $errors = stream_get_contents($stdio[2]);
        fclose($stdio[1]); fclose($stdio[2]);
        threads_check(proc_close($process) === 0, 'CLI failed: ' . $errors . $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    };
    $checked = $command(['accounts', 'check', 'threads']);
    threads_check(count($checked['platforms']) === 1 && $checked['platforms'][0]['available'], 'CLI did not check just Threads');
    $draft = $command(['post', 'CLI draft', '--to=threads']);
    threads_check($draft['status'] === 'draft' && $stats()['submissions'] === 1, 'CLI published without --now');
    $sent = $command(['post', 'CLI synchronous publish', '--to=threads', '--now']);
    threads_check($sent['status'] === 'published' && $stats()['submissions'] === 2, 'CLI --now did not publish synchronously');
    $api->delete('/api/v1/accounts/threads')->assertStatus(200);
    threads_check(!$sessions->summary('threads')['connected'], 'Removing Threads kept its session');
    echo "Threads checks passed: account identity, cookies, text/images/alt text, limits, safe retries, uncertain outcomes, own queue, HTTP account management, and CLI drafts.\n";
} finally {
    proc_terminate($fake);
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($fake);
    test_remove($directory);
}
