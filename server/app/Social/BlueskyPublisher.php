<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\Response;
use Psr\Http\Client\ClientExceptionInterface;

// Keep the remote protocol checks together so every transition and ambiguous-write outcome is visible.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final readonly class BlueskyPublisher implements Publisher
{
    public function __construct(
        private Client $http,
        private SessionStore $sessions,
    ) {}

    public function status(): array
    {
        $status = ['id' => 'bluesky', 'label' => 'Bluesky', 'available' => false];
        $handle = $this->sessions->expectedHandle('bluesky');
        if ($handle !== null) {
            $status['handle'] = $handle;
        }
        try {
            $session = $this->connect();
            return [...$status, 'available' => true, 'handle' => '@' . $session['account']['handle']];
        } catch (RemoteSessionFailure $error) {
            return [...$status, 'reason' => $error->getMessage()];
        } catch (ClientExceptionInterface) {
            return [...$status, 'reason' => 'Bluesky could not be reached. Try checking the connection again.'];
        } catch (\RuntimeException) {
            return [
                ...$status,
                'reason' => 'The saved session could not be read or renewed. Import your browser session again.',
            ];
        }
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        $submitted = false;
        try {
            if (preg_match_all('/\X/u', $text) > 300 || strlen($text) > 3000) {
                throw new RemoteSessionFailure('Bluesky allows 300 characters per post (3,000 UTF-8 bytes).');
            }
            if (count($images) > 4) {
                throw new RemoteSessionFailure('Bluesky allows up to four images per post.');
            }
            $session = $this->connect();
            $client = $this->http->timeout(35)->retry(0);
            $headers = ['Authorization' => 'Bearer ' . $session['account']['accessJwt']];
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            if ($images !== []) {
                $uploaded = [];
                foreach ($images as $image) {
                    $response = $client->request(
                        'POST',
                        $session['url'] . '/xrpc/com.atproto.repo.uploadBlob',
                        headers: [...$headers, 'Content-Type' => $image->mimeType],
                        body: $image->bytes,
                    );
                    $blob = $this->json($response)['blob'] ?? null;
                    if (!$response->ok() || !is_array($blob) || !is_string($blob['ref']['$link'] ?? null)) {
                        throw new RemoteSessionFailure('Bluesky could not upload an image. No post was submitted.');
                    }
                    $uploaded[] = [
                        'alt' => $image->alt,
                        'image' => $blob,
                        'aspectRatio' => ['width' => $image->width, 'height' => $image->height],
                    ];
                }
                $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $uploaded];
            }

            // The PDS allocates the TID required by the feed-post lexicon; local request IDs prevent resubmission.
            $submitted = true;
            $response = $client->post(
                $session['url'] . '/xrpc/com.atproto.repo.createRecord',
                json: [
                    'repo' => $session['account']['did'],
                    'collection' => 'app.bsky.feed.post',
                    'record' => $record,
                ],
                headers: $headers,
            );
            $result = $this->json($response);
            $prefix = 'at://' . $session['account']['did'] . '/app.bsky.feed.post/';
            $uri = (string) ($result['uri'] ?? '');
            $key = str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : '';
            if (
                $response->ok()
                && preg_match('/^[234567abcdefghijklmnopqrstuvwxyz]{13}$/D', $key) === 1
                && is_string($result['cid'] ?? null)
                && $result['cid'] !== ''
            ) {
                return [
                    'platform' => 'bluesky',
                    'status' => 'published',
                    'url' =>
                        'https://bsky.app/profile/' . rawurlencode($session['account']['handle']) . '/post/' . $key,
                ];
            }
            $code = $response->getStatusCode();
            return [
                'platform' => 'bluesky',
                'status' => $code >= 400 && $code < 500 && $code !== 408 ? 'failed' : 'unknown',
                'error' =>
                    $code >= 400 && $code < 500
                        ? "Bluesky rejected the post (HTTP {$code})."
                        : 'Bluesky did not confirm the post. Check your profile before posting again.',
            ];
        } catch (RemoteSessionFailure $error) {
            return ['platform' => 'bluesky', 'status' => 'failed', 'error' => $error->getMessage()];
        } catch (ClientExceptionInterface) {
            return [
                'platform' => 'bluesky',
                'status' => $submitted ? 'unknown' : 'failed',
                'error' => $submitted
                    ? 'The connection ended before Bluesky confirmed the post. Check your profile before posting again.'
                    : 'Bluesky could not be reached. No post was submitted.',
            ];
        }
    }

    /** @return array{url: string, account: array<string, mixed>} */
    private function connect(): array
    {
        $state = $this->sessions->read('bluesky');
        foreach ($state['origins'] ?? [] as $originIndex => $origin) {
            foreach ($origin['localStorage'] ?? [] as $storageIndex => $entry) {
                if (($entry['name'] ?? null) !== 'BSKY_STORAGE') {
                    continue;
                }
                $storage = json_decode((string) ($entry['value'] ?? ''), true);
                $did = $storage['session']['currentAccount']['did'] ?? null;
                foreach ($storage['session']['accounts'] ?? [] as $accountIndex => $account) {
                    if (($account['did'] ?? null) !== $did || !is_string($did)) {
                        continue;
                    }
                    $this->assertIdentity($account, $account);
                    $url = $this->serverUrl($account);
                    $client = $this->http->timeout(20)->retry(0);
                    $response = $client->get($url . '/xrpc/com.atproto.server.getSession', headers: [
                        'Authorization' => 'Bearer ' . ($account['accessJwt'] ?? ''),
                    ]);
                    if (in_array($response->getStatusCode(), [400, 401], true)) {
                        if (!is_string($account['refreshJwt'] ?? null) || $account['refreshJwt'] === '') {
                            throw new RemoteSessionFailure(
                                'Bluesky sign-in expired. Import your browser session again.',
                            );
                        }
                        $refreshed = $client->post($url . '/xrpc/com.atproto.server.refreshSession', headers: [
                            'Authorization' => 'Bearer ' . $account['refreshJwt'],
                        ]);
                        $credentials = $this->json($refreshed);
                        if (
                            !$refreshed->ok()
                            || !is_string($credentials['accessJwt'] ?? null)
                            || !is_string($credentials['refreshJwt'] ?? null)
                        ) {
                            throw new RemoteSessionFailure(
                                'Bluesky sign-in expired. Import your browser session again.',
                            );
                        }
                        $this->assertIdentity($credentials, $account);
                        $account = [
                            ...$account,
                            ...array_intersect_key($credentials, array_flip([
                                'accessJwt',
                                'refreshJwt',
                                'handle',
                                'active',
                            ])),
                        ];
                        $storage['session']['accounts'][$accountIndex] = $account;
                        $state['origins'][$originIndex]['localStorage'][$storageIndex]['value'] = json_encode(
                            $storage,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        );
                        $this->sessions->write('bluesky', $state);
                        $response = $client->get($url . '/xrpc/com.atproto.server.getSession', headers: [
                            'Authorization' => 'Bearer ' . $account['accessJwt'],
                        ]);
                    }
                    if (!$response->ok()) {
                        throw new RemoteSessionFailure(
                            'Bluesky could not verify this sign-in. Import your browser session again.',
                        );
                    }
                    $identity = $this->json($response);
                    $this->assertIdentity($identity, $account);
                    return ['url' => $url, 'account' => [...$account, 'handle' => $identity['handle']]];
                }
            }
        }
        throw new RemoteSessionFailure('Import your signed-in Bluesky browser session to connect.');
    }

    private function assertIdentity(array $identity, array $account): void
    {
        $expected = ltrim($this->sessions->expectedHandle('bluesky') ?? (string) ($account['handle'] ?? ''), '@');
        if (
            !is_string($identity['did'] ?? null)
            || !str_starts_with($identity['did'], 'did:')
            || $identity['did'] !== ($account['did'] ?? null)
            || $expected === ''
            || strtolower((string) ($identity['handle'] ?? '')) !== strtolower($expected)
            || ($identity['active'] ?? true) !== true
        ) {
            throw new RemoteSessionFailure(
                'The Bluesky session does not match the intended active account. Import it again.',
            );
        }
    }

    private function serverUrl(array $account): string
    {
        $override = config('postslop.bluesky_url');
        $url = rtrim(
            (string) (
                $override !== '' && $override !== null ? $override : $account['pdsUrl'] ?? $account['service'] ?? ''
            ),
            '/',
        );
        $parts = parse_url($url);
        $loopback = $override && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        if (
            !is_array($parts)
            || ($parts['host'] ?? '') === ''
            || ($parts['scheme'] ?? '') !== 'https' && !($loopback && ($parts['scheme'] ?? '') === 'http')
            || array_key_exists('user', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || $loopback
            && ($parts['path'] ?? '') !== ''
            && preg_match('~^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~iD', $parts['path']) !== 1
        ) {
            throw new RemoteSessionFailure(
                'The imported Bluesky server URL is invalid. Import your browser session again.',
            );
        }
        return $url;
    }

    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : [];
    }
}
