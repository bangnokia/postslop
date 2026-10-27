<?php

declare(strict_types=1);

// This test deliberately does not boot CrabPHP or read the app's .env or sessions.
function client_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function client_remove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $file) {
            if ($file !== '.' && $file !== '..') client_remove($path . '/' . $file);
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
}

$root = dirname(__DIR__, 2);
$binary = getenv('POSTSLOP_CLIENT_BINARY') ?: $root . '/bin/postslop';
$directory = sys_get_temp_dir() . '/postslop-client-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$fake = proc_open(['node', __DIR__ . '/fakes/remote-client.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
client_check(is_resource($fake), 'Could not start the fake API');
stream_set_timeout($pipes[1], 10);
$url = trim((string) fgets($pipes[1]));
try {
    client_check(preg_match('~^http://127\.0\.0\.1:\d+$~D', $url) === 1, 'Fake API did not start');
    // An actual synthetic Firefox database exercises the distributed Python reader, never a local profile.
    $db = new PDO('sqlite:' . $directory . '/cookies.sqlite');
    $db->exec('PRAGMA user_version=15');
    $db->exec('CREATE TABLE moz_cookies (name TEXT, value TEXT, host TEXT, path TEXT, expiry INTEGER, isSecure INTEGER, isHttpOnly INTEGER, sameSite INTEGER, originAttributes TEXT)');
    $insert = $db->prepare('INSERT INTO moz_cookies VALUES (?, ?, ?, ?, ?, 1, 1, 0, ?)');
    foreach ([['sessionid', 'fixture-client-secret'], ['csrftoken', 'fixture-csrf'], ['ds_user_id', '12345']] as [$name, $value]) {
        $insert->execute([$name, $value, '.threads.com', '/', time() + 86400, '']);
    }
    $insert->execute(['foreign', 'fixture-foreign-secret', '.example.invalid', '/', time() + 86400, '']);
    $db = null;
    $snapshot = hash_file('sha256', $directory . '/cookies.sqlite');
    $run = static function (array $args) use ($binary, $directory): array {
        $env = getenv();
        unset($env['POSTSLOP_SERVER_URL'], $env['POSTSLOP_REMOTE_TOKEN']);
        // The client must import without Python, Node, or any other executable on PATH.
        $env['PATH'] = $directory;
        $proc = proc_open([PHP_BINARY, $binary, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $io, $directory, $env);
        fclose($io[0]);
        $out = stream_get_contents($io[1]); $err = stream_get_contents($io[2]);
        fclose($io[1]); fclose($io[2]);
        $code = proc_close($proc);
        foreach (['fixture-client-secret', 'fixture-client-token', 'fixture-foreign-secret'] as $secret) {
            client_check(!str_contains($out . $err, $secret), 'The client exposed credentials');
        }
        return [$code, $out, $err];
    };
    $request = static function (string $path, ?array $body = null) use ($url): array {
        $curl = curl_init($url . $path);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $result = json_decode(curl_exec($curl), true);
        curl_close($curl);
        return $result;
    };
    $reset = static fn(string $scenario = '') => $request('/__reset', ['scenario' => $scenario]);
    $stats = static fn(): array => $request('/__stats')['requests'];
    $connection = ['--to=' . $url, '--token=fixture-client-token'];
    $setup = ['setup', 'threads', '--threads=tester', '--browser=firefox', '--profile=' . $directory, '--json'];
    client_check($run(['--version'])[0] === 0 && $run(['--help'])[0] === 0, 'Client cannot run outside the repository');
    $reset();
    [$code, $out] = $run([...$setup, '--check-only']);
    client_check($code === 0 && json_decode($out, true)['sent'] === false && $stats() === [], 'Check-only contacted a server');
    [$code, $out, $err] = $run([...$setup, ...$connection]);
    client_check($code === 0 && json_decode($out, true)['platforms'][0]['available'], 'Import failed: ' . $err);
    client_check($stats() === [['method' => 'GET', 'path' => '/api/v1/posts?limit=1'], ['method' => 'PUT', 'path' => '/api/v1/accounts/threads']], 'Import made unexpected requests');
    client_check(hash_file('sha256', $directory . '/cookies.sqlite') === $snapshot, 'Import changed the browser database');
    foreach (['unavailable' => 4, 'missing-status' => 2, 'wrong-handle' => 2] as $scenario => $expected) {
        $reset($scenario);
        client_check($run([...$setup, ...$connection])[0] === $expected, 'Import reported success for ' . $scenario);
        client_check(count($stats()) === 2, 'Import was retried');
    }
    foreach (['redirect', 'error'] as $scenario) {
        $reset($scenario);
        client_check($run([...$setup, ...$connection])[0] === 2 && count($stats()) === 1, 'Unsafe response was followed, retried, or accepted');
    }
    $reset();
    client_check($run([...$setup, '--to=' . $url, '--token=wrong'])[0] === 2 && count($stats()) === 1, 'Invalid token imported a session');
    foreach (['http://example.com', 'https://user:password@example.com', 'https://example.com/?token=private', 'file:///tmp/example'] as $bad) {
        client_check($run(['accounts', '--to=' . $bad])[0] === 2, 'Unsafe destination accepted');
    }
    $reset();
    [$code, $out] = $run(['accounts', 'check', 'threads', '--json', ...$connection]);
    client_check($code === 0 && json_decode($out, true)['platforms'][0]['id'] === 'threads', 'Single-account check failed');
    client_check($stats() === [['method' => 'POST', 'path' => '/api/v1/accounts/threads/check']], 'Single-account check fetched other accounts');
    foreach (['enable', 'disable', 'remove'] as $action) {
        client_check($run(['accounts', $action, 'threads', ...$connection])[0] === 0, 'Account action failed');
    }
    client_check($run(['accounts', '--refresh', '--json', ...$connection])[0] === 0, 'Refresh failed');
    client_check($run(['setup', 'threads', '--browser=firefox', '--check-only'])[0] === 2, 'Noninteractive import guessed a handle');
    client_check($run(['post', 'Do not publish'])[0] === 2, 'Remote account client accepted publication');
    client_check($run(['accounts', '--token', '--json'])[0] === 2, 'Missing option value accepted');
    client_check(!file_exists($directory . '/sessions.json') && !is_dir($directory . '/storage'), 'The client persisted accounts');
    echo "Remote client checks passed: real fixture browser import, separate working directory, safe destinations, no redirects/retries, identity and token failures, credential-free output, and individual account management.\n";
} finally {
    proc_terminate($fake);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($fake);
    client_remove($directory);
}
