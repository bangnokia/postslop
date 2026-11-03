<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Publishing;
use App\Social\Publisher;
use App\Social\VideoAttachment;
use Crab\Queue\Consumer;
use Crab\Testing\Api;

// An assertion evaluates a boolean condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
/**
 * A dummy MP4 with only the boxes the core reads: ftyp, mvhd, and a video track's tkhd and hdlr.
 */
function api_mp4(int $width, int $height, int $milliseconds): string
{
    $box = static fn(string $type, string $payload): string => pack('N', 8 + strlen($payload)) . $type . $payload;
    $matrix = pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
    $mvhd = $box('mvhd', "\0\0\0\0" . pack('N4', 0, 0, 1000, $milliseconds) . str_repeat("\0", 80));
    $tkhd = $box('tkhd', "\0\0\0\x03" . pack('N5', 0, 0, 1, 0, $milliseconds) . str_repeat("\0", 16) . $matrix
        . pack('N2', $width << 16, $height << 16));
    $hdlr = $box('hdlr', str_repeat("\0", 8) . 'vide' . str_repeat("\0", 12) . "VideoHandler\0");
    return $box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41')
        . $box('moov', $mvhd . $box('trak', $tkhd . $box('mdia', $hdlr)))
        . $box('mdat', str_repeat("\x07", 256));
}

function api_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/postslop-api-' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
putenv('POSTSLOP_TOKEN=' . $token);
putenv('APP_URL=http://127.0.0.1:8087');
$app = test_app($directory);
$publisher = new class implements Publisher {
    public int $calls = 0;

    public function status(): array
    {
        return ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => true, 'handle' => 'fixture'];
    }

    public function publish(string $requestId, string $text, array $images, ?VideoAttachment $video = null): array
    {
        $this->calls++;
        return ['platform' => 'bluesky', 'status' => 'published', 'url' => 'https://bsky.app/profile/fixture/post/1'];
    }
};
$app->container->singleton(
    Publishing::class,
    static fn(): Publishing => test_publishing($app, ['bluesky' => $publisher], $directory),
);
$guest = new Api($app)->withHeaders(['Host' => '127.0.0.1:8087']);
$api = $guest->withToken($token);
$image = imagecreatetruecolor(8, 8);
ob_start();
imagepng($image);
$png = ob_get_clean();
try {
    $guest->get('/api/v1/posts')->assertStatus(401);
    $guest->withToken('wrong-token-of-enough-length')->get('/api/v1/posts')->assertStatus(401);
    // Postslop may run on another machine, so other hosts are let in with the token.
    new Api($app)->withHeaders(['Host' => 'postslop.example:8087', 'Origin' => 'http://postslop.example:8087'])
        ->withToken($token)
        ->get('/api/v1/posts')
        ->assertStatus(200);
    $api->get('/api/v1/accounts')->assertStatus(200)->assertJson('platforms.0.handle', 'fixture');

    $draft = $api->post('/api/v1/posts', [
        'text' => 'from the web',
        'platforms' => ['bluesky'],
        'mode' => 'draft',
        'images' => [['data' => base64_encode($png), 'mimeType' => 'image/png', 'alt' => 'dot']],
    ])->assertStatus(200)->assertJson('status', 'draft')->json();
    api_check($publisher->calls === 0, 'Saving a draft sent it');
    $id = $draft['id'];
    $media = $api->get('/api/v1/media/' . $draft['images'][0]['id'])->assertStatus(200);
    api_check($media->body() === $png && $media->header('Content-Type') === 'image/png', 'The image was not served back');

    // A video travels as base64 like an image, comes back apart from the images, and is served with its type.
    $clip = api_mp4(1280, 720, 4000);
    $videoDraft = $api->post('/api/v1/posts', [
        'text' => 'a video from the web',
        'platforms' => ['bluesky'],
        'mode' => 'draft',
        'video' => ['data' => base64_encode($clip), 'mimeType' => 'video/mp4', 'alt' => 'A dummy clip'],
    ])->assertStatus(200)->assertJson('video.width', 1280)->assertJson('video.height', 720)->json();
    api_check($videoDraft['images'] === [] && $videoDraft['video']['alt'] === 'A dummy clip', 'The video draft was not presented');
    $served = $api->get('/api/v1/media/' . $videoDraft['video']['id'])->assertStatus(200);
    api_check($served->body() === $clip && $served->header('Content-Type') === 'video/mp4', 'The video was not served back');
    $api->post('/api/v1/posts', [
        'text' => 'x',
        'platforms' => ['bluesky', 'dev-null'],
        'mode' => 'draft',
        'video' => ['data' => base64_encode($clip)],
    ])->assertStatus(422);
    $api->delete('/api/v1/posts/' . $videoDraft['id'])->assertStatus(204);
    $accountList = $api->get('/api/v1/accounts')->assertStatus(200)->json();
    api_check(
        array_column($accountList['platforms'], 'maxVideoSeconds', 'id')['bluesky'] === 180
            && $accountList['limits']['maxVideoBytes'] === 20_971_520,
        'Accounts did not say which sites take videos',
    );

    $api->put('/api/v1/posts/' . $id, ['text' => 'edited', 'platforms' => ['bluesky'], 'mode' => 'draft'])
        ->assertStatus(200)
        ->assertJson('text', 'edited');
    $later = new DateTimeImmutable('+1 day')->format(DATE_ATOM);
    $api->post('/api/v1/posts/' . $id . '/schedule', ['scheduledAt' => $later])->assertStatus(200)->assertJson('status', 'scheduled');
    $api->get('/api/v1/posts', ['status' => 'scheduled'])->assertStatus(200)->assertJson('posts.0.id', $id);
    // Publishing answers at once, with each destination waiting on its site's queue; that queue's worker sends it.
    $api->post('/api/v1/posts/' . $id . '/publish', [])
        ->assertStatus(200)
        ->assertJson('status', 'publishing')
        ->assertJson('results.0.status', 'queued');
    $api->post('/api/v1/posts/' . $id . '/publish', [])->assertStatus(200)->assertJson('status', 'publishing');
    api_check($publisher->calls === 0, 'Queuing a post sent it before the queue ran');
    $consumer = $app->container->get(Consumer::class);
    api_check(
        $consumer->runNext(['bluesky']) && !$consumer->runNext(['bluesky']) && !$consumer->runNext(),
        'Publishing twice did not queue exactly one job, on the site\'s queue',
    );
    $api->get('/api/v1/posts/' . $id)->assertStatus(200)->assertJson('status', 'published');
    api_check($publisher->calls === 1, 'Publishing a scheduled post did not send it once');
    $api->put('/api/v1/posts/' . $id, ['text' => 'too late', 'platforms' => ['bluesky']])
        ->assertStatus(409)
        ->assertJson('error.code', 'conflict');
    $api->delete('/api/v1/posts/' . $id)->assertStatus(409);

    $api->post('/api/v1/posts', ['text' => '', 'platforms' => ['bluesky']])->assertStatus(422)->assertJson('error.code', 'invalid');
    $api->post('/api/v1/posts', ['text' => 'x', 'platforms' => ['bluesky'], 'images' => [['path' => '/etc/passwd']]])
        ->assertStatus(422);
    $api->get('/api/v1/posts/00000000-0000-4000-8000-000000000000')->assertStatus(404)->assertJson('error.code', 'not_found');
    $api->get('/api/v1/posts', ['status' => 'bogus'])->assertStatus(422);

    $removable = $api->post('/api/v1/posts', ['text' => 'bye', 'platforms' => ['bluesky'], 'mode' => 'draft'])->json();
    $api->delete('/api/v1/posts/' . $removable['id'])->assertStatus(204);
    $api->get('/api/v1/posts/' . $removable['id'])->assertStatus(404);

    $now = $api->post('/api/v1/posts', ['text' => 'now', 'platforms' => ['bluesky']])->assertStatus(200)->json();
    api_check($now['status'] === 'publishing' && $publisher->calls === 1, 'Publishing now did not answer at once');
    $consumer->runNext(['bluesky']);
    $api->get('/api/v1/posts/' . $now['id'])->assertJson('status', 'published');
    api_check($publisher->calls === 2, 'The queue did not publish the post');
    // The calendar range lists posts by when they went out or will, and leaves drafts out.
    $api->post('/api/v1/posts', ['text' => 'a draft', 'platforms' => ['bluesky'], 'mode' => 'draft'])->assertStatus(200);
    $range = $api->get('/api/v1/posts', [
        'from' => new DateTimeImmutable('-1 day')->format(DATE_ATOM),
        'to' => new DateTimeImmutable('+1 day')->format(DATE_ATOM),
    ])->assertStatus(200)->json();
    api_check(array_column($range['posts'], 'id') === [$id, $now['id']], 'The calendar range listed the wrong posts');
    $api->get('/api/v1/posts', ['from' => '2026-01-01T00:00:00Z'])->assertStatus(422);

    // Accounts are added from another machine, turned off and on, and removed.
    $session = ['cookies' => [['name' => 'a', 'value' => 'b', 'domain' => 'bsky.app', 'path' => '/']], 'origins' => []];
    $api->put('/api/v1/accounts/bluesky', ['handle' => '@fixture', 'session' => $session, 'source' => 'firefox'])
        ->assertStatus(200)
        ->assertJson('platforms.0.connected', true)
        ->assertJson('platforms.0.enabled', true)
        ->assertJson('platforms.0.source', 'firefox');
    $api->put('/api/v1/accounts/bluesky', ['handle' => 'bad handle!', 'session' => $session])->assertStatus(422);
    $api->put('/api/v1/accounts/bluesky', ['handle' => 'fixture', 'session' => 'nope'])->assertStatus(422);
    $api->put('/api/v1/accounts/mastodon', ['handle' => 'fixture', 'session' => $session])->assertStatus(404);
    $api->patch('/api/v1/accounts/bluesky', ['enabled' => 'no'])->assertStatus(422);
    $api->patch('/api/v1/accounts/bluesky', ['enabled' => false])
        ->assertStatus(200)
        ->assertJson('platforms.0.enabled', false)
        ->assertJson('platforms.0.available', false);
    $calls = $publisher->calls;
    $off = $api->post('/api/v1/posts', ['text' => 'off', 'platforms' => ['bluesky']])->assertStatus(200)->json();
    $consumer->runNext(['bluesky']);
    $api->get('/api/v1/posts/' . $off['id'])->assertJson('status', 'failed');
    api_check($publisher->calls === $calls, 'A turned-off account was published to');
    $api->patch('/api/v1/accounts/bluesky', ['enabled' => true])->assertStatus(200)->assertJson('platforms.0.available', true);
    // Retrying answers at once too; the site's queue resends the failed destination.
    $api->post('/api/v1/posts/' . $off['id'] . '/retry', [])
        ->assertStatus(200)
        ->assertJson('status', 'publishing')
        ->assertJson('results.0.status', 'queued');
    api_check($publisher->calls === $calls, 'Retrying sent the post before the queue ran');
    api_check($consumer->runNext(['bluesky']) && !$consumer->runNext(), 'Retrying did not queue one job on the site\'s queue');
    $api->get('/api/v1/posts/' . $off['id'])->assertJson('status', 'published');
    $api->post('/api/v1/posts/' . $off['id'] . '/retry', [])->assertStatus(422);
    $api->delete('/api/v1/accounts/bluesky')->assertStatus(200)->assertJson('platforms.0.connected', false);
    $guest->delete('/api/v1/accounts/bluesky')->assertStatus(401);

    // Without a token the API is open.
    putenv('POSTSLOP_TOKEN=');
    new Api(test_app($directory . '/open'))->get('/api/v1/posts')->assertStatus(200);
    echo "API checks passed: token checks, an open API without a token, drafts, images, videos, edits, scheduling, publishing, history, deletion, accounts, and error codes.\n";
} finally {
    test_remove($directory);
}
