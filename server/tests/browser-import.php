<?php

declare(strict_types=1);

foreach (['Snapshot', 'BrowserStorage', 'BrowserCookies', 'BrowserSessions'] as $class) {
    require dirname(__DIR__, 2) . '/clients/cli/src/' . $class . '.php';
}

use Postslop\Client\BrowserCookies;
use Postslop\Client\BrowserSessions;
use Postslop\Client\BrowserStorage;
use Postslop\Client\Snapshot;

function import_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function import_refuses(callable $action): void
{
    try { $action(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Unsafe or malformed browser data was accepted');
}
function import_remove(string $path): void
{
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') continue;
        is_dir($path . '/' . $item) ? import_remove($path . '/' . $item) : unlink($path . '/' . $item);
    }
    rmdir($path);
}
function import_varint(int $value): string
{
    $bytes = '';
    do {
        $byte = $value & 127; $value >>= 7;
        $bytes .= chr($byte | ($value > 0 ? 128 : 0));
    } while ($value > 0);
    return $bytes;
}
function import_snappy(string $bytes): string
{
    $length = strlen($bytes);
    $literal = $length < 61 ? chr(($length - 1) << 2) : chr(61 << 2) . pack('v', $length - 1);
    return import_varint($length) . $literal . $bytes;
}

$directory = sys_get_temp_dir() . '/postslop-import-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$db = $local = null;
try {
    $db = new PDO('sqlite:' . $directory . '/cookies.sqlite');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA user_version=17');
    $db->exec('CREATE TABLE moz_cookies (name TEXT, value TEXT, host TEXT, path TEXT, expiry INTEGER, isSecure INTEGER, isHttpOnly INTEGER, sameSite INTEGER, originAttributes TEXT)');
    $insert = $db->prepare('INSERT INTO moz_cookies VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?)');
    foreach (['sessionid', 'csrftoken', 'ds_user_id'] as $name) $insert->execute([$name, $name === 'ds_user_id' ? '12345' : 'fixture-' . $name, '.threads.com', '/', (time() + 86400) * 1000, '']);
    $insert->execute(['expired', 'private', '.threads.com', '/', (time() - 100) * 1000, '']);
    $insert->execute(['container', 'private', '.threads.com', '/', (time() + 100) * 1000, '^userContextId=2']);
    $insert->execute(['foreign', 'private', '.example.invalid', '/', (time() + 100) * 1000, '']);
    $before = array_map('hash_file', array_fill(0, 2, 'sha256'), [$directory . '/cookies.sqlite', $directory . '/cookies.sqlite-wal']);
    $report = BrowserSessions::read('firefox', $directory, ['threads' => 'tester']);
    $cookies = $report['accounts']['threads']['session']['cookies'];
    import_check(count($cookies) === 3 && $cookies[0]['expires'] > time(), 'Firefox scopes, expiry or committed WAL not honored');
    import_check($before === array_map('hash_file', array_fill(0, 2, 'sha256'), [$directory . '/cookies.sqlite', $directory . '/cookies.sqlite-wal']), 'Reading changed the browser database or WAL');

    $state = ['session' => ['currentAccount' => ['did' => 'did:plc:one'], 'accounts' => [
        ['did' => 'did:plc:one', 'handle' => 'tester.test', 'accessJwt' => 'fixture-access', 'refreshJwt' => 'fixture-refresh'],
        ['did' => 'did:plc:two', 'handle' => 'other.test', 'accessJwt' => 'other-private', 'refreshJwt' => 'other-private'],
    ]], 'label' => 'café 🌱'];
    $json = json_encode($state, JSON_UNESCAPED_UNICODE);
    $ls = $directory . '/storage/default/https+++bsky.app/ls';
    mkdir($ls, 0700, true);
    $local = new PDO('sqlite:' . $ls . '/data.sqlite');
    $local->exec('CREATE TABLE database (origin TEXT)');
    $local->exec("INSERT INTO database VALUES ('https://bsky.app')");
    $local->exec('CREATE TABLE data (key TEXT, utf16_length INTEGER, conversion_type INTEGER, compression_type INTEGER, value BLOB)');
    $write = $local->prepare('INSERT INTO data VALUES (?, ?, ?, ?, ?)');
    foreach ([[1, 0, $json], [1, 1, import_snappy($json)], [0, 0, iconv('UTF-8', pack('S', 1) === "\1\0" ? 'UTF-16LE' : 'UTF-16BE', $json)]] as [$conversion, $compression, $bytes]) {
        $local->exec('DELETE FROM data');
        $write->execute(['BSKY_STORAGE', strlen(iconv('UTF-8', 'UTF-16LE', $json)) / 2, $conversion, $compression, $bytes]);
        $result = BrowserStorage::firefox($directory, 'tester.test');
        import_check(count(json_decode($result[0]['value'], true)['session']['accounts']) === 1 && !str_contains($result[0]['value'], 'other-private'), 'Bluesky kept another account or lost its encoding');
    }
    import_refuses(fn() => BrowserStorage::firefox($directory, 'someone_else'));
    $local->exec('UPDATE data SET utf16_length=0');
    import_refuses(fn() => BrowserStorage::firefox($directory, 'tester.test'));
    import_check(BrowserStorage::snappy("\x09\x08abc\x16\x03\x00") === 'abcabcabc', 'Snappy overlapping copy failed');
    foreach (["\xff", "\x02\x00a", "\x03\x02\x00\x00", import_varint(9000000)] as $bad) import_refuses(fn() => BrowserStorage::snappy($bad));

    // Known Chromium v10 derivation and schema-24 host binding; no Keychain or real profile is read.
    $key = hash_pbkdf2('sha1', 'fixture-keychain-password', 'saltysalt', 1003, 16, true);
    $encrypt = static fn(string $host, string $value): string => 'v10' . openssl_encrypt(hash('sha256', $host, true) . $value, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, str_repeat(' ', 16));
    import_check(BrowserCookies::decrypt($encrypt('.threads.com', 'fixture-secret'), '.threads.com', $key) === 'fixture-secret', 'Chrome cookie did not decrypt');
    import_refuses(fn() => BrowserCookies::decrypt($encrypt('.threads.com', 'fixture-secret'), '.example.invalid', $key));
    import_refuses(fn() => BrowserCookies::decrypt('v20unsupported', '.threads.com', $key));
    $chrome = new PDO('sqlite:' . $directory . '/Cookies');
    $chrome->exec('CREATE TABLE meta (key TEXT, value TEXT)');
    $chrome->exec("INSERT INTO meta VALUES ('version','24')");
    $chrome->exec('CREATE TABLE cookies (host_key TEXT, name TEXT, value TEXT, encrypted_value BLOB, path TEXT, expires_utc INTEGER, is_persistent INTEGER, is_secure INTEGER, is_httponly INTEGER, samesite INTEGER, top_frame_site_key TEXT)');
    $cookie = $chrome->prepare('INSERT INTO cookies VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?)');
    foreach ([['.www.threads.com', 'sessionid', '', time() + 60], ['.threads.com', 'partitioned', 'https://other.invalid', time() + 60], ['.threads.com', 'expired', '', time() - 60]] as [$host, $name, $partition, $expiry]) {
        $cookie->execute([$host, $name, '', $encrypt($host, 'fixture-secret'), '/', ($expiry + 11644473600) * 1000000, $partition]);
    }
    $chrome = null;
    $result = Snapshot::read($directory . '/Cookies', static fn(PDO $db): array => BrowserCookies::chrome($db, 'threads.com', $key));
    import_check(count($result) === 1 && $result[0]['name'] === 'sessionid', 'Chrome cookie scope filtering failed');

    // Chrome LevelDB log, a newer table, and a later deletion. Only BSKY_STORAGE is imported.
    $level = $directory . '/Local Storage/leveldb'; mkdir($level, 0700, true);
    $storageKey = "_https://bsky.app\0\1BSKY_STORAGE";
    $value = "\0" . iconv('UTF-8', 'UTF-16LE', $json);
    $batch = pack('P', 1) . pack('V', 1) . "\1" . import_varint(strlen($storageKey)) . $storageKey . import_varint(strlen($value)) . $value;
    file_put_contents($level . '/000001.log', str_repeat("\0", 4) . pack('v', strlen($batch)) . "\1" . $batch);
    import_check(count(BrowserStorage::chrome($directory, 'tester.test')) === 1, 'Chrome LevelDB log was not read');
    $entry = static fn(string $key, string $value): string => "\0" . import_varint(strlen($key)) . import_varint(strlen($value)) . $key . $value . pack('V2', 0, 1);
    $data = $entry($storageKey . pack('P', (2 << 8) | 1), $value);
    $compressed = import_snappy($data);
    $table = $compressed . "\1" . str_repeat("\0", 4);
    $index = $entry('last', "\0" . import_varint(strlen($compressed)));
    $indexOffset = strlen($table);
    $table .= $index . str_repeat("\0", 5);
    $footer = "\0\0" . import_varint($indexOffset) . import_varint(strlen($index));
    $table .= str_pad($footer, 40, "\0") . hex2bin('57fb808b247547db');
    file_put_contents($level . '/000002.ldb', $table);
    import_check(count(BrowserStorage::chrome($directory, 'tester.test')) === 1, 'Compressed LevelDB table was not read');
    $batch = pack('P', 3) . pack('V', 1) . "\0" . import_varint(strlen($storageKey)) . $storageKey;
    file_put_contents($level . '/000003.log', str_repeat("\0", 4) . pack('v', strlen($batch)) . "\1" . $batch);
    import_refuses(fn() => BrowserStorage::chrome($directory, 'tester.test'));
    echo "Browser import checks passed: SQLite WAL snapshots, browser scope/expiry filtering, Chrome AES host binding, Firefox encodings, Snappy, Chrome LevelDB logs/tables/deletions, and active Bluesky identity.\n";
} finally {
    $insert = $write = $cookie = $db = $local = $chrome = null;
    import_remove($directory);
}
