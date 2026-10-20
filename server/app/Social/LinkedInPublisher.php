<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\Response;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Posts to LinkedIn with the imported browser session, through the same web API (Voyager) linkedin.com's own
 * pages call: li_at signs in, and JSESSIONID's value doubles as the CSRF token.
 */
// Keep the remote protocol checks together so every transition and ambiguous-write outcome is visible.
// @mago-expect lint:too-many-methods,cyclomatic-complexity,kan-defect
final readonly class LinkedInPublisher implements Publisher
{
    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public function __construct(
        private Client $http,
        private SessionStore $sessions,
    ) {}

    public function status(): array
    {
        $status = ['id' => 'linkedin', 'label' => 'LinkedIn', 'available' => false];
        $handle = $this->sessions->expectedHandle('linkedin');
        if ($handle !== null) {
            $status['handle'] = $handle;
        }
        try {
            $session = $this->connect();
            return [...$status, 'available' => true, 'handle' => $session['handle']];
        } catch (RemoteSessionFailure $error) {
            return [...$status, 'reason' => $error->getMessage()];
        } catch (ClientExceptionInterface) {
            return [...$status, 'reason' => 'LinkedIn could not be reached. Try checking the connection again.'];
        } catch (\RuntimeException) {
            return [...$status, 'reason' => 'The saved session could not be read. Import your browser session again.'];
        }
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        $submitted = false;
        try {
            if (mb_strlen($text) > 3000) {
                throw new RemoteSessionFailure('LinkedIn allows up to 3,000 characters.');
            }
            if ($images === [] && trim($text) === '') {
                throw new RemoteSessionFailure('LinkedIn needs text or an image.');
            }
            $session = $this->connect();
            $media = [];
            foreach ($images as $image) {
                $media[] = [
                    'category' => 'IMAGE',
                    'mediaUrn' => $this->upload($session, $image),
                    'tapTargets' => [],
                    'altText' => $image->alt,
                ];
            }
            $submitted = true;
            $response = $session['client']->post(
                $session['url'] . '/voyager/api/contentcreation/normShares',
                json: [
                    'visibleToConnectionsOnly' => false,
                    'externalAudienceProviders' => [],
                    'commentaryV2' => ['text' => $text, 'attributes' => []],
                    'origin' => 'FEED',
                    'allowedCommentersScope' => 'ALL',
                    'postState' => 'PUBLISHED',
                    'media' => $media,
                ],
                headers: $this->headers($session),
            );
            $code = $response->getStatusCode();
            if ($code >= 400 && $code < 500 && $code !== 408) {
                return $this->result('failed', "LinkedIn rejected the post (HTTP {$code}).");
            }
            if (!$response->ok()) {
                return $this->result(
                    'unknown',
                    'LinkedIn did not confirm the post. Check your profile before posting again.',
                );
            }
            // The share's URN is all a post's address needs; it may be a share, an activity or a UGC post.
            if (preg_match('/urn:li:(?:share|activity|ugcPost):\d+/', (string) $response->getBody(), $urn) !== 1) {
                return $this->result(
                    'unknown',
                    'LinkedIn did not return a post ID. Check your profile before posting again.',
                );
            }
            return [
                'platform' => 'linkedin',
                'status' => 'published',
                'url' => 'https://www.linkedin.com/feed/update/' . $urn[0] . '/',
            ];
        } catch (RemoteSessionFailure $error) {
            return $this->result($submitted ? 'unknown' : 'failed', $error->getMessage());
        } catch (ClientExceptionInterface) {
            return $this->result(
                $submitted ? 'unknown' : 'failed',
                $submitted
                    ? 'The connection ended before LinkedIn confirmed the post. Check your profile before posting again.'
                    : 'LinkedIn could not be reached. No post was submitted.',
            );
        }
    }

    /**
     * Registers an image with LinkedIn's media service and uploads its bytes; the post then refers to it by URN.
     *
     * @param array{url: string, client: Client, cookie: string, csrf: string, handle: string} $session
     */
    private function upload(array $session, ImageAttachment $image): string
    {
        $response = $session['client']->post(
            $session['url'] . '/voyager/api/voyagerVideoDashMediaUploadMetadata?action=upload',
            json: [
                'mediaUploadType' => 'IMAGE_SHARING',
                'fileSize' => strlen($image->bytes),
                'filename' => $image->filename,
            ],
            headers: $this->headers($session),
        );
        $data = self::json($response);
        $value = $data['data']['value'] ?? $data['value'] ?? null;
        $target = is_array($value) ? $value['singleUploadUrl'] ?? null : null;
        $urn = is_array($value) ? $value['urn'] ?? null : null;
        if (!$response->ok() || !is_string($target) || !is_string($urn) || !$this->ownHost($session['url'], $target)) {
            throw new RemoteSessionFailure('LinkedIn did not accept the image upload. No post was submitted.');
        }
        $extra = is_array($value['singleUploadHeaders'] ?? null) ? $value['singleUploadHeaders'] : [];
        $uploaded = $session['client']->request(
            'PUT',
            $target,
            headers: [
                ...array_map(strval(...), $extra),
                'Content-Type' => $image->mimeType,
                'Cookie' => $session['cookie'],
                'csrf-token' => $session['csrf'],
                'User-Agent' => self::USER_AGENT,
            ],
            body: $image->bytes,
        );
        if (!$uploaded->ok()) {
            throw new RemoteSessionFailure('LinkedIn did not store the image. No post was submitted.');
        }
        return $urn;
    }

    /** @return array{url: string, client: Client, cookie: string, csrf: string, handle: string} */
    private function connect(): array
    {
        $state = $this->sessions->read('linkedin');
        $cookies = [];
        foreach ($state['cookies'] ?? [] as $cookie) {
            if (!is_string($cookie['name'] ?? null) || !is_string($cookie['value'] ?? null)) {
                continue;
            }
            $cookies[$cookie['name']] = $cookie['value'];
        }
        if (($cookies['li_at'] ?? '') === '' || ($cookies['JSESSIONID'] ?? '') === '') {
            throw new RemoteSessionFailure('Import your signed-in LinkedIn browser session to connect.');
        }
        $session = [
            'url' => $this->baseUrl(),
            'client' => $this->http->timeout(35)->retry(0),
            'cookie' => implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                $cookies,
            )),
            'csrf' => trim($cookies['JSESSIONID'], '"'),
            'handle' => '',
        ];
        $response = $session['client']->get($session['url'] . '/voyager/api/me', headers: $this->headers($session));
        $handle = self::publicIdentifier(self::json($response));
        if (!$response->ok() || $handle === null) {
            throw new RemoteSessionFailure(
                'LinkedIn could not verify this sign-in. Import your browser session again.',
            );
        }
        $expected = ltrim($this->sessions->expectedHandle('linkedin') ?? '', '@');
        if ($expected === '' || strtolower($handle) !== strtolower($expected)) {
            throw new RemoteSessionFailure(
                'The LinkedIn session does not match the intended account. Import it again.',
            );
        }
        return [...$session, 'handle' => $handle];
    }

    /**
     * The profile's public identifier, the name in its linkedin.com/in/ address, from /voyager/api/me in either
     * of the shapes LinkedIn answers with.
     */
    private static function publicIdentifier(array $me): ?string
    {
        $direct = $me['miniProfile']['publicIdentifier'] ?? $me['data']['miniProfile']['publicIdentifier'] ?? null;
        if (is_string($direct)) {
            return $direct;
        }
        $urn = $me['data']['*miniProfile'] ?? null;
        foreach ($me['included'] ?? [] as $item) {
            if (
                is_string($item['publicIdentifier'] ?? null)
                && ($urn === null || ($item['entityUrn'] ?? null) === $urn)
            ) {
                return $item['publicIdentifier'];
            }
        }
        return null;
    }

    /** @param array{url: string, cookie: string, csrf: string} $session */
    private function headers(array $session): array
    {
        return [
            'Accept' => 'application/vnd.linkedin.normalized+json+2.1',
            'Cookie' => $session['cookie'],
            'csrf-token' => $session['csrf'],
            'x-restli-protocol-version' => '2.0.0',
            'x-li-lang' => 'en_US',
            'User-Agent' => self::USER_AGENT,
            'Origin' => $session['url'],
            'Referer' => $session['url'] . '/feed/',
        ];
    }

    private static function json(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : [];
    }

    /** An upload address must stay on LinkedIn, or on the fake site when testing, so cookies never leave it. */
    private function ownHost(string $baseUrl, string $target): bool
    {
        $host = strtolower((string) parse_url($target, PHP_URL_HOST));
        $scheme = parse_url($target, PHP_URL_SCHEME);
        if ($host === strtolower((string) parse_url($baseUrl, PHP_URL_HOST))) {
            return $scheme === parse_url($baseUrl, PHP_URL_SCHEME);
        }
        return $scheme === 'https' && ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com'));
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('postslop.linkedin_url', 'https://www.linkedin.com'), '/');
        $parts = parse_url($url);
        $loopback =
            is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        $path = is_array($parts) ? $parts['path'] ?? '' : '';
        $valid =
            is_array($parts)
            && !array_key_exists('user', $parts)
            && !array_key_exists('query', $parts)
            && !array_key_exists('fragment', $parts)
            && (
                $path === ''
                && ($parts['scheme'] ?? '') === 'https'
                && ($parts['host'] ?? '') === 'www.linkedin.com'
                || $loopback
                && ($path === '' || preg_match('~^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~iD', $path) === 1)
            );
        if (!$valid) {
            throw new RemoteSessionFailure('The LinkedIn server URL is invalid.');
        }
        return $url;
    }

    private function result(string $status, string $error): array
    {
        return ['platform' => 'linkedin', 'status' => $status, 'error' => $error];
    }
}
