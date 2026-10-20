<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\Publishing;
use App\Social\Publisher;
use Crab\Testing\Api;

// An assertion evaluates a boolean condition rather than selecting a behavior.
// @mago-expect lint:no-boolean-flag-parameter
function api_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/postslop-api-' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
putenv('POSTSLOP_TOKEN_PATH=' . $directory . '/client-token');
putenv('APP_URL=http://127.0.0.1:8087');
$app = test_app($directory);
file_put_contents($directory . '/client-token', $token . "\n");
$publisher = new class implements Publisher {
    public int $calls = 0;

    public function status(): array
    {
        return ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => true, 'handle' => 'fixture'];
    }

    public function publish(string $requestId, string $text, array $images): array
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
    new Api($app)->withHeaders(['Host' => 'evil.example'])->withToken($token)->get('/api/v1/posts')->assertStatus(403);
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

    $api->put('/api/v1/posts/' . $id, ['text' => 'edited', 'platforms' => ['bluesky'], 'mode' => 'draft'])
        ->assertStatus(200)
        ->assertJson('text', 'edited');
    $later = new DateTimeImmutable('+1 day')->format(DATE_ATOM);
    $api->post('/api/v1/posts/' . $id . '/schedule', ['scheduledAt' => $later])->assertStatus(200)->assertJson('status', 'scheduled');
    $api->get('/api/v1/posts', ['status' => 'scheduled'])->assertStatus(200)->assertJson('posts.0.id', $id);
    $api->post('/api/v1/posts/' . $id . '/publish', [])->assertStatus(200)->assertJson('status', 'published');
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
    api_check($now['status'] === 'published' && $publisher->calls === 2, 'Publishing now did not send');
    echo "API checks passed: token and host checks, drafts, images, edits, scheduling, publishing, history, deletion, and error codes.\n";
} finally {
    test_remove($directory);
}
