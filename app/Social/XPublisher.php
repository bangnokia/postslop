<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\File;
use Crab\Contracts\HttpClient\Response;
use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use Psr\Http\Client\ClientExceptionInterface;

// Keep authenticated identity, current website protocol, and uncertain-write handling together.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final class XPublisher implements Publisher
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private ?array $bundle = null;

    public function __construct(
        private readonly Client $http,
        private readonly SessionStore $sessions,
    ) {}

    public function status(): array
    {
        $status = ['id' => 'x', 'label' => 'X', 'available' => false];
        try {
            $session = $this->connect();
            return [...$status, 'available' => true, 'handle' => '@' . $session['handle']];
        } catch (RemoteSessionFailure $error) {
            return [...$status, 'reason' => $error->getMessage()];
        } catch (ClientExceptionInterface) {
            return [...$status, 'reason' => 'X could not be reached. Check the connection again.'];
        }
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        $submitted = false;
        try {
            if (count($images) > 4) {
                throw new RemoteSessionFailure('X allows up to four images per post.');
            }
            $session = $this->connect();
            $operation = $this->operation($session['html'], $session['state']);
            $headers = [
                ...$session['headers'],
                'Authorization' => $operation['bearer'],
                'x-csrf-token' => $session['csrf'],
                'x-twitter-auth-type' => 'OAuth2Session',
                'x-twitter-active-user' => 'yes',
                'x-twitter-client-language' => 'en',
                'Origin' => $this->url(),
                'Referer' => $this->url() . '/home',
            ];
            $media = [];
            foreach ($images as $image) {
                $media[] = ['media_id' => $this->upload($image, $headers), 'tagged_users' => []];
            }

            // X has no idempotency key here. A lost response must never trigger an automatic resubmission.
            $submitted = true;
            $response = $this->http
                ->timeout(35)
                ->retry(0)
                ->post(
                    $this->url() . '/i/api/graphql/' . $operation['id'] . '/CreateTweet',
                    json: [
                        'variables' => [
                            'tweet_text' => $text,
                            'media' => ['media_entities' => $media, 'possibly_sensitive' => false],
                            'semantic_annotation_ids' => [],
                            'disallowed_reply_options' => null,
                        ],
                        'features' => (object) $operation['features'],
                        'queryId' => $operation['id'],
                    ],
                    headers: $headers,
                );
            $body = $this->json($response);
            $tweet = $body['data']['create_tweet']['tweet_results']['result'] ?? [];
            $id = $tweet['rest_id'] ?? $tweet['legacy']['id_str'] ?? null;
            if ($response->ok() && is_string($id) && preg_match('/^\d+$/', $id)) {
                return [
                    'platform' => 'x',
                    'status' => 'published',
                    'url' => 'https://x.com/' . $session['handle'] . '/status/' . $id,
                ];
            }
            $code = $response->getStatusCode();
            if ($code >= 400 && $code < 500 && $code !== 408 || $response->ok() && ($body['errors'] ?? []) !== []) {
                return [
                    'platform' => 'x',
                    'status' => 'failed',
                    'error' => $this->rejection($response, $body, $headers),
                ];
            }
            return $this->unknown();
        } catch (RemoteSessionFailure $error) {
            return (
                $submitted
                    ? $this->unknown()
                    : ['platform' => 'x', 'status' => 'failed', 'error' => $error->getMessage()]
            );
        } catch (ClientExceptionInterface) {
            return (
                $submitted
                    ? $this->unknown()
                    : [
                        'platform' => 'x',
                        'status' => 'failed',
                        'error' => 'X could not be reached. No post was submitted.',
                    ]
            );
        }
    }

    private function connect(): array
    {
        try {
            $saved = $this->sessions->read('x');
        } catch (\RuntimeException $error) {
            throw new RemoteSessionFailure($error->getMessage());
        }
        $cookies = [];
        foreach ($saved['cookies'] ?? [] as $cookie) {
            $expires = $cookie['expires'] ?? -1;
            if (is_numeric($expires) && (float) $expires >= 0 && (float) $expires <= time()) {
                continue;
            }
            if (ltrim($cookie['domain'] ?? '', '.') !== 'x.com') {
                continue;
            }
            $name = $cookie['name'] ?? '';
            $value = $cookie['value'] ?? '';
            if (
                is_string($name)
                && is_string($value)
                && preg_match('/^[a-zA-Z0-9_]+$/', $name)
                && !preg_match('/[\r\n;]/', $value)
            ) {
                $cookies[$name] = $value;
            }
        }
        if (($cookies['auth_token'] ?? '') === '' || ($cookies['ct0'] ?? '') === '') {
            throw new RemoteSessionFailure('Import a signed-in X session again.');
        }
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Cookie' => implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                array_values($cookies),
            )),
        ];
        $response = $this->http
            ->timeout(20)
            ->retry(0)
            ->get($this->url() . '/home', headers: $headers);
        $html = (string) $response->getBody();
        $marker = '__INITIAL_STATE__=';
        $start = strpos($html, $marker);
        $end = $start === false ? false : strpos($html, ';window.__META_DATA__', $start);
        if (!$response->ok() || $start === false || $end === false) {
            throw new RemoteSessionFailure(
                'X did not accept the saved session. Sign in to X in the browser and import it again.',
            );
        }
        try {
            $state = json_decode(
                substr($html, $start + strlen($marker), $end - $start - strlen($marker)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw new RemoteSessionFailure('X changed its account page. No post was submitted.');
        }
        $userId = $state['session']['user_id'] ?? '';
        $handle = $state['entities']['users']['entities'][$userId]['screen_name'] ?? null;
        if (!is_string($handle) || !preg_match('/^[a-zA-Z0-9_]{1,15}$/', $handle)) {
            throw new RemoteSessionFailure('The saved X session is signed out. Import it again.');
        }
        $expected = $this->sessions->expectedHandle('x');
        if ($expected === null || strcasecmp(ltrim($expected, '@'), $handle) !== 0) {
            throw new RemoteSessionFailure(
                'The saved X session does not match the intended account. Import the correct account again.',
            );
        }
        return [
            'handle' => $handle,
            'csrf' => $cookies['ct0'],
            'headers' => $headers,
            'html' => $html,
            'state' => $state,
        ];
    }

    private function operation(string $html, array $state): array
    {
        $bundleUrl = config('postslap.x_bundle_url');
        if (!$bundleUrl) {
            if (!preg_match(
                '~https://abs\.twimg\.com/responsive-web/client-web/main\.[a-zA-Z0-9]+\.js~',
                $html,
                $match,
            )) {
                throw new RemoteSessionFailure('X changed its web app bundle. No post was submitted.');
            }
            $bundleUrl = $match[0];
        }
        if (($this->bundle['url'] ?? null) !== $bundleUrl) {
            // Public scripts receive no account cookies or authorization headers.
            $response = $this->http
                ->timeout(25)
                ->retry(0)
                ->get($bundleUrl, headers: ['User-Agent' => self::USER_AGENT]);
            $body = (string) $response->getBody();
            if (
                !$response->ok()
                || !preg_match('/Bearer AAAAA[^"\s]+/', $body, $bearer)
                || !preg_match(
                    '/queryId:"([a-zA-Z0-9_-]+)",operationName:"CreateTweet",operationType:"mutation",metadata:\{featureSwitches:(\[[^\]]*\])/',
                    $body,
                    $operation,
                )
            ) {
                throw new RemoteSessionFailure('X changed its publishing protocol. No post was submitted.');
            }
            $this->bundle = [
                'url' => $bundleUrl,
                'bearer' => $bearer[0],
                'id' => $operation[1],
                'switches' => json_decode($operation[2], true),
            ];
        }
        $features = [];
        foreach ($this->bundle['switches'] as $name) {
            $value =
                $state['featureSwitch']['user']['config'][$name]['value']
                ?? $state['featureSwitch']['defaultConfig'][$name]['value']
                ?? null;
            // X's GraphQL client sends true only for a switch whose resolved value is exactly true.
            $features[$name] = $value === true;
        }
        return [...$this->bundle, 'features' => $features];
    }

    private function upload(ImageAttachment $image, array $headers): string
    {
        $url = rtrim(config('postslap.x_upload_url', 'https://upload.x.com'), '/') . '/i/media/upload.json';
        $client = $this->http->timeout(35)->retry(0);
        $response = $client->request(
            'POST',
            $url,
            query: [
                'command' => 'INIT',
                'total_bytes' => strlen($image->bytes),
                'media_type' => $image->mimeType,
                'media_category' => 'tweet_image',
            ],
            headers: $headers,
        );
        $body = $this->json($response);
        $id = $body['media_id_string'] ?? null;
        if (!$response->ok() || !is_string($id) || !preg_match('/^\d+$/', $id)) {
            throw new RemoteSessionFailure('X refused the image upload. No post was submitted.');
        }
        $response = $client->request(
            'POST',
            $url,
            query: [
                'command' => 'APPEND',
                'media_id' => $id,
                'segment_index' => 0,
            ],
            headers: $headers,
            files: ['media' => File::fromContents($image->bytes, $image->filename, $image->mimeType)],
        );
        if (!$response->ok()) {
            throw new RemoteSessionFailure('X could not receive an image. No post was submitted.');
        }
        $response = $client->request(
            'POST',
            $url,
            query: [
                'command' => 'FINALIZE',
                'media_id' => $id,
                'original_md5' => md5($image->bytes),
            ],
            headers: $headers,
        );
        $body = $this->json($response);
        for (
            $attempt = 0;
            $attempt < 5 && in_array($body['processing_info']['state'] ?? '', ['pending', 'in_progress'], true);
            $attempt++
        ) {
            Async::sleep(EventLoop::current(), min(3, max(
                1,
                (int) ($body['processing_info']['check_after_secs'] ?? 1),
            )));
            $response = $client->get($url, query: ['command' => 'STATUS', 'media_id' => $id], headers: $headers);
            $body = $this->json($response);
        }
        if (
            !$response->ok()
            || ($body['media_id_string'] ?? null) !== $id
            || ($body['processing_info']['state'] ?? 'succeeded') !== 'succeeded'
        ) {
            throw new RemoteSessionFailure('X has not finished processing the image. No post was submitted.');
        }
        if ($image->alt !== '') {
            $response = $client->post(
                $this->url() . '/i/api/1.1/media/metadata/create.json',
                json: [
                    'media_id' => $id,
                    'alt_text' => ['text' => $image->alt],
                ],
                headers: $headers,
            );
            if (!$response->ok()) {
                throw new RemoteSessionFailure('X could not save the image description. No post was submitted.');
            }
        }
        return $id;
    }

    private function rejection(Response $response, array $body, array $headers): string
    {
        $code = $body['errors'][0]['code'] ?? null;
        if ($code === 187) {
            return 'X rejected this as a duplicate post. Check your profile before trying again.';
        }
        if ($code === 186) {
            return 'X rejected this post because the text is too long.';
        }
        if ($response->getStatusCode() === 429 || $code === 88) {
            return 'X is rate limiting this account. Try again later.';
        }
        if (in_array($code, [32, 89, 215, 326, 353], true) || in_array($response->getStatusCode(), [401, 403], true)) {
            return 'X requires a fresh browser session or an account check. Open X, complete its prompt, then import the session again.';
        }
        $reason =
            'X rejected the post (HTTP '
            . $response->getStatusCode()
            . ($code === null ? '' : ', code ' . (int) $code)
            . ').';
        $message = $body['errors'][0]['message'] ?? null;
        if (!is_string($message) || !mb_check_encoding($message, 'UTF-8')) {
            return $reason;
        }
        // X's error explains restrictions that a numeric code alone cannot. Never echo session material.
        $redactions = [];
        foreach (explode(';', $headers['Cookie'] ?? '') as $cookie) {
            [$name, $value] = array_pad(explode('=', trim($cookie), 2), 2, '');
            if ($value !== '' && (strlen($value) >= 8 || in_array($name, ['auth_token', 'ct0', 'kdt', 'att'], true))) {
                $redactions[$value] = '[redacted]';
            }
        }
        foreach ([
            $headers['Authorization'] ?? '',
            substr($headers['Authorization'] ?? '', 7),
            $headers['x-csrf-token'] ?? '',
        ] as $value) {
            if ($value === '') {
                continue;
            }
            $redactions[$value] = '[redacted]';
        }
        $message = strtr($message, $redactions);
        $message = preg_replace('/[\p{C}\s]+/u', ' ', strip_tags($message)) ?? '';
        $message = trim($message);
        if ($message === '') {
            return $reason;
        }
        return $reason . ' ' . mb_substr($message, 0, 500) . (mb_strlen($message) > 500 ? '…' : '');
    }

    private function json(Response $response): array
    {
        try {
            $body = $response->json();
            return is_array($body) ? $body : [];
        } catch (\JsonException|\LengthException) {
            return [];
        }
    }

    private function unknown(): array
    {
        return [
            'platform' => 'x',
            'status' => 'unknown',
            'error' => 'X did not confirm the result. Check your X profile before posting again; Postslap will not retry automatically.',
        ];
    }

    private function url(): string
    {
        return rtrim(config('postslap.x_url', 'https://x.com'), '/');
    }
}
