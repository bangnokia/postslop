<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Publishing;
use App\PrivateFiles;
use App\Social\SessionStore;
use Crab\App;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Queue\Queue;

/**
 * Boots the application on a throwaway database and private folders, never the real storage.
 */
function test_app(string $directory): App
{
    PrivateFiles::directory($directory);
    putenv('DB_DSN=sqlite:' . $directory . '/database.sqlite');
    putenv('CACHE_STORE=array');
    putenv('POSTSLOP_LOCK_FILE=' . $directory . '/publishing.lock');
    putenv('POSTSLOP_MEDIA_PATH=' . $directory . '/media');
    putenv('POSTSLOP_SESSIONS_FILE=' . $directory . '/sessions.json');
    $app = require dirname(__DIR__) . '/app.php';
    $app->container->get(Migrator::class)->run();
    return $app;
}

function test_publishing(App $app, array $publishers, string $directory): Publishing
{
    // Each fake site counts as connected; the fakes never read the session.
    $sessions = $app->container->get(SessionStore::class);
    $sessions->import(array_map(
        static fn(): array => ['handle' => 'fixture', 'session' => ['cookies' => [], 'origins' => []]],
        $publishers,
    ), 'test');
    return new Publishing(
        $publishers,
        $app->container->get(Database::class),
        $app->container->get(Cache::class),
        $app->container->get(Queue::class),
        $directory . '/publishing.lock',
        $directory . '/media',
        $sessions,
    );
}

/**
 * Writes a post as a crashed or earlier run left it.
 */
function test_seed(App $app, string $id, string $fingerprint, array $results, string $status = 'publishing'): void
{
    $db = $app->container->get(Database::class);
    $db->table('posts')->insert([
        'id' => $id,
        'text' => 'fixture',
        'platforms' => json_encode(array_column($results, 'platform')),
        'fingerprint' => $fingerprint,
        'status' => $status,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    foreach ($results as $position => $result) {
        $db->table('deliveries')->insert([
            'post_id' => $id,
            'position' => $position,
            'platform' => $result['platform'],
            'status' => $result['status'],
            'result' => json_encode($result, JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}

function test_remove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                test_remove($path . '/' . $name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
}
