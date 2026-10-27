<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

use App\Core\PostRequest;
use App\Core\Publishing;
use App\Social\Publisher;
use Crab\Http\Request;
use Crab\Queue\Consumer;
use Crab\Testing\Api;
use Crab\Testing\ApiResponse;

function mcp_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mcp_call(Api $api, string $name, array $arguments = []): ApiResponse
{
    return $api
        ->post('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'tool-request',
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ])
        ->assertStatus(200)
        ->assertJson('id', 'tool-request');
}

$directory = sys_get_temp_dir() . '/postslop-mcp-' . bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(32));
putenv('POSTSLOP_TOKEN=' . $token);
putenv('APP_URL=https://postslop.example');
$app = test_app($directory);
$publisher = new class implements Publisher {
    public int $calls = 0;
    public int $checks = 0;
    public string $outcome = 'published';

    public function status(): array
    {
        $this->checks++;
        return ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => true, 'handle' => 'fixture'];
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        $this->calls++;
        return [
            'platform' => 'bluesky',
            'status' => $this->outcome,
            'url' => 'https://bsky.app/profile/fixture/post/1',
        ];
    }
};
$app->container->singleton(Publishing::class, static fn(): Publishing => test_publishing(
    $app,
    ['bluesky' => $publisher],
    $directory,
));
$guest = new Api($app)->withHeaders([
    'Accept' => 'application/json, text/event-stream',
    'MCP-Protocol-Version' => '2025-11-25',
]);
$api = $guest->withToken($token);
$ping = ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'ping'];
$initialize = [
    'jsonrpc' => '2.0',
    'id' => 'init',
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'test', 'version' => '1.0'],
    ],
];

try {
    $guest->post('/api/v1/mcp', $initialize)->assertStatus(401);
    $guest->withToken('wrong')->post('/api/v1/mcp', $ping)->assertStatus(401);
    $api->post('/api/v1/mcp', $ping, ['Content-Type' => 'text/plain'])->assertStatus(415);
    $api->post('/api/v1/mcp', $ping, ['Origin' => 'https://evil.example', 'Host' => 'evil.example'])->assertStatus(403);
    $api->post('/api/v1/mcp', $ping, ['Origin' => 'null'])->assertStatus(403);
    $api->post('/api/v1/mcp', $ping, ['Origin' => ''])->assertStatus(403);
    $api->post('/api/v1/mcp', $ping, ['Origin' => 'https://postslop.example.attacker.example'])->assertStatus(403);
    $api->get('/api/v1/mcp', headers: ['Origin' => 'https://evil.example'])->assertStatus(403);
    $api->get('/api/v1/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    $api->delete('/api/v1/mcp')->assertStatus(405);
    $api->post('/api/v1/mcp', $ping, ['MCP-Protocol-Version' => 'bad'])->assertStatus(400);
    $api->post('/api/v1/mcp', $ping, ['Origin' => 'https://postslop.example'])->assertStatus(200)->assertJson('id', 0);
    $init = $api
        ->post('/api/v1/mcp', $initialize)
        ->assertStatus(200)
        ->assertJson('result.protocolVersion', '2025-11-25');
    mcp_check(
        json_decode($init->body())->result->capabilities->tools instanceof stdClass,
        'Tools capability must be a JSON object',
    );
    mcp_check($init->header('MCP-Session-Id') === '', 'Stateless MCP unexpectedly issued a session');
    $api->post('/api/v1/mcp', [
        ...$initialize,
        'params' => [...$initialize['params'], 'protocolVersion' => 'future'],
    ])->assertJson('result.protocolVersion', '2025-11-25');
    foreach (['2025-03-26', '2025-06-18'] as $version) {
        $api->post('/api/v1/mcp', [
            ...$initialize,
            'params' => [...$initialize['params'], 'protocolVersion' => $version],
        ])->assertJson('result.protocolVersion', $version);
    }
    $api->post('/api/v1/mcp', [...$initialize, 'params' => new stdClass()])->assertJson('error.code', -32602);
    $notification = $api->post('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ])->assertStatus(202);
    mcp_check($notification->body() === '', 'A notification received a response body');
    $api->post('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 12, 'result' => new stdClass()])->assertStatus(202);
    $api->post('/api/v1/mcp', [...$ping, 'method' => 'missing'])->assertJson('error.code', -32601);
    mcp_call($api, 'missing')->assertJson('error.code', -32602);
    $api->post('/api/v1/mcp', [
        ...$ping,
        'method' => 'tools/call',
        'params' => ['name' => 'postslop_accounts', 'arguments' => []],
    ])->assertJson('error.code', -32602);
    foreach ([
        [],
        [$ping],
        null,
        123,
        [...$ping, 'id' => null],
        [...$ping, 'id' => true],
        [...$ping, 'params' => []],
    ] as $invalid) {
        // Api treats null as no body, so set the JSON header explicitly.
        $api->post('/api/v1/mcp', $invalid, ['Content-Type' => 'application/json'])->assertStatus(400);
    }
    $raw = $app->handle(
        new Request(
            'POST',
            '/api/v1/mcp',
            [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            '{"jsonrpc":',
        ),
    );
    new ApiResponse($raw)
        ->assertStatus(400)
        ->assertJson('error.code', -32700);

    $listed = $api->post('/api/v1/mcp', [...$ping, 'method' => 'tools/list'])->assertStatus(200);
    $tools = json_decode($listed->body())->result->tools;
    mcp_check(count($tools) === 8, 'Unexpected tool count');
    foreach ($tools as $tool) {
        mcp_check(
            $tool->inputSchema->type === 'object' && $tool->inputSchema->properties instanceof stdClass,
            'Invalid tool schema',
        );
    }
    mcp_call($api, 'postslop_accounts')->assertJson('result.structuredContent.platforms.0.handle', 'fixture');
    mcp_call($api, 'postslop_check_account', [
        'platform' => 'bluesky',
    ])->assertJson('result.structuredContent.available', true);
    mcp_check($publisher->checks === 2, 'Account check did not refresh');
    $old = mcp_call($api->withHeaders(['MCP-Protocol-Version' => '']), 'postslop_accounts')->json('result');
    mcp_check(
        !array_key_exists('structuredContent', $old)
        && json_decode($old['content'][0]['text'], true)['platforms'][0]['handle'] === 'fixture',
        'Old protocol text result failed',
    );

    $id = PostRequest::uuid();
    $draft = ['requestId' => $id, 'text' => 'Draft from an agent', 'platforms' => ['bluesky']];
    mcp_call($api, 'postslop_create_draft', $draft)
        ->assertJson('result.structuredContent.status', 'draft')
        ->assertJson('result.isError', false);
    $api->get('/api/v1/posts/' . $id)->assertJson('text', $draft['text']);
    mcp_call($api, 'postslop_create_draft', $draft)->assertJson('result.structuredContent.id', $id);
    mcp_call($api, 'postslop_create_draft', [
        ...$draft,
        'text' => 'different',
    ])->assertJson('result.structuredContent.error.code', 'conflict');
    mcp_check($publisher->calls === 0, 'Creating a draft published it');
    mcp_call($api, 'postslop_list_posts', ['statuses' => [
        'draft',
    ]])->assertJson('result.structuredContent.posts.0.id', $id);
    mcp_call($api, 'postslop_get_post', [
        'id' => PostRequest::uuid(),
    ])->assertJson('result.isError', true)->assertJson('result.structuredContent.error.code', 'not_found');

    foreach ([
        ['text' => 'missing id', 'platforms' => ['bluesky']],
        [...$draft, 'requestId' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
        [...$draft, 'mode' => 'now'],
        [...$draft, 'scheduledAt' => 'tomorrow'],
        [...$draft, 'platforms' => 'bluesky'],
        [...$draft, 'platforms' => []],
        [...$draft, 'platforms' => ['bluesky', 'bluesky']],
        [...$draft, 'platforms' => ['missing']],
        [...$draft, 'images' => [['path' => '/etc/passwd', 'alt' => 'x']]],
        [...$draft, 'images' => [['data' => base64_encode('invalid image'), 'alt' => 'x']]],
        [...$draft, 'text' => str_repeat('x', 3001)],
    ] as $invalid) {
        mcp_call($api, 'postslop_create_draft', $invalid)
            ->assertJson('result.isError', true)
            ->assertJson('result.structuredContent.error.code', 'invalid');
    }
    foreach ([
        ['limit' => 0],
        ['limit' => 101],
        ['limit' => '20'],
        ['statuses' => ['bogus']],
        ['before' => 'tomorrow'],
    ] as $invalid) {
        mcp_call($api, 'postslop_list_posts', $invalid)->assertJson('result.isError', true);
    }
    // A tools/call without an RPC id must never mutate anything.
    $api->post('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'tools/call',
        'params' => [
            'name' => 'postslop_publish_post',
            'arguments' => ['id' => $id],
        ],
    ])->assertStatus(400);
    mcp_call($api, 'postslop_get_post', ['id' => $id])->assertJson('result.structuredContent.status', 'draft');

    $later = new DateTimeImmutable('+1 day', new DateTimeZone('Asia/Ho_Chi_Minh'));
    foreach ([
        'tomorrow',
        $later->format('Y-m-d\TH:i:s'),
        '2027-02-30T09:00:00Z',
        '2027-01-01T25:00:00Z',
        '2000-01-01T00:00:00Z',
    ] as $invalid) {
        mcp_call($api, 'postslop_schedule_post', [
            'id' => $id,
            'scheduledAt' => $invalid,
        ])->assertJson('result.isError', true);
    }
    mcp_call($api, 'postslop_schedule_post', ['id' => $id])->assertJson('result.isError', true);
    mcp_call($api, 'postslop_schedule_post', [
        'id' => $id,
        'scheduledAt' => $later->format(DATE_ATOM),
    ])->assertJson('result.structuredContent.status', 'scheduled')->assertJson('result.structuredContent.scheduledAt', $later->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
    mcp_call($api, 'postslop_schedule_post', [
        'id' => $id,
        'scheduledAt' => null,
    ])->assertJson('result.structuredContent.status', 'draft');

    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $edit = [
        'id' => $id,
        'text' => 'Edited draft',
        'platforms' => ['bluesky'],
        'images' => [
            ['data' => base64_encode($png), 'mimeType' => 'image/png', 'alt' => 'A dot'],
        ],
    ];
    mcp_call($api, 'postslop_update_draft', $edit)->assertJson('result.structuredContent.images.0.alt', 'A dot');
    mcp_call($api, 'postslop_update_draft', [
        'id' => $id,
        'text' => 'oops',
        'platforms' => ['bluesky'],
    ])->assertJson('result.isError', true);
    mcp_call($api, 'postslop_schedule_post', [
        'id' => $id,
        'scheduledAt' => $later->format(DATE_ATOM),
    ])->assertJson('result.isError', false);
    mcp_call($api, 'postslop_update_draft', [
        ...$edit,
        'images' => [],
    ])->assertJson('result.structuredContent.status', 'draft')->assertJson('result.structuredContent.scheduledAt', null)->assertJson('result.structuredContent.images', []);

    $consumer = $app->container->get(Consumer::class);
    mcp_check(!$consumer->runNext(['bluesky']), 'A draft or future schedule was queued early');
    mcp_call($api, 'postslop_publish_post', [
        'id' => $id,
    ])->assertJson('result.structuredContent.results.0.status', 'queued');
    mcp_call($api, 'postslop_publish_post', ['id' => $id])->assertJson('result.isError', false);
    mcp_check($publisher->calls === 0, 'Publish waited on the site');
    mcp_check(
        $consumer->runNext(['bluesky']) && !$consumer->runNext(['bluesky']),
        'Publish must queue exactly one delivery',
    );
    mcp_call($api, 'postslop_get_post', ['id' => $id])->assertJson('result.structuredContent.status', 'published');
    mcp_call($api, 'postslop_update_draft', $edit)->assertJson('result.structuredContent.error.code', 'conflict');
    mcp_call($api, 'postslop_schedule_post', [
        'id' => $id,
        'scheduledAt' => $later->format(DATE_ATOM),
    ])->assertJson('result.structuredContent.error.code', 'conflict');

    $scheduled = [...$draft, 'requestId' => PostRequest::uuid()];
    mcp_call($api, 'postslop_create_draft', $scheduled)->assertJson('result.isError', false);
    mcp_call($api, 'postslop_schedule_post', [
        'id' => $scheduled['requestId'],
        'scheduledAt' => $later->format(DATE_ATOM),
    ])->assertJson('result.isError', false);
    $core = $app->container->get(Publishing::class);
    mcp_check($core->queueDue() === [], 'Future post was queued early');
    mcp_check(
        count($core->queueDue(new DateTimeImmutable('+2 days'))) === 1 && $consumer->runNext(['bluesky']),
        'Scheduled post did not use the normal queue',
    );
    mcp_call($api, 'postslop_get_post', [
        'id' => $scheduled['requestId'],
    ])->assertJson('result.structuredContent.status', 'published');
    mcp_call($api, 'postslop_create_draft', $scheduled)->assertJson('result.structuredContent.status', 'published');
    mcp_check($publisher->calls === 2, 'Repeated draft creation republished');

    $publisher->outcome = 'unknown';
    $uncertain = [...$draft, 'requestId' => PostRequest::uuid()];
    mcp_call($api, 'postslop_create_draft', $uncertain);
    mcp_call($api, 'postslop_publish_post', ['id' => $uncertain['requestId']]);
    $consumer->runNext(['bluesky']);
    mcp_call($api, 'postslop_publish_post', [
        'id' => $uncertain['requestId'],
    ])->assertJson('result.structuredContent.status', 'unknown');
    mcp_check(!$consumer->runNext(['bluesky']) && $publisher->calls === 3, 'Unknown delivery was retried');
    $redacted = mcp_call($api, 'postslop_accounts', [$token => $token]);
    mcp_check(!str_contains($redacted->body(), $token), 'Unrecognized arguments leaked a secret');

    // Unexpected database failures must not expose SQL or private paths, even with APP_DEBUG on.
    $app->container->get(Crab\Contracts\Database\Database::class)->execute(
        'ALTER TABLE posts RENAME TO unavailable_posts',
    );
    $failed = mcp_call($api, 'postslop_get_post', ['id' => $id])->assertJson('error.code', -32603);
    mcp_check(
        !str_contains($failed->body(), $directory) && !str_contains($failed->body(), 'SQL'),
        'Internal details escaped into the MCP response',
    );

    putenv('POSTSLOP_TOKEN=');
    new Api(test_app($directory . '/open'))
        ->post('/api/v1/mcp', $ping)
        ->assertStatus(200);
    echo
        "MCP checks passed: protocol, authentication, origin, strict inputs, drafts, images, schedules, queues, idempotency, and unknown deliveries.\n";
} finally {
    test_remove($directory);
}
