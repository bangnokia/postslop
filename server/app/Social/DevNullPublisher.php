<?php

declare(strict_types=1);

namespace App\Social;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\File;
use Crab\Contracts\HttpClient\Response;
use DOMDocument;
use DOMXPath;
use Psr\Http\Client\ClientExceptionInterface;

// Keep the remote protocol checks together so every transition and ambiguous-write outcome is visible.
// @mago-expect lint:cyclomatic-complexity,kan-defect,halstead
final readonly class DevNullPublisher implements Publisher
{
    public function __construct(
        private Client $http,
        private SessionStore $sessions,
    ) {}

    public function status(): array
    {
        $status = ['id' => 'dev-null', 'label' => 'dev-null.sh', 'available' => false];
        $handle = $this->sessions->expectedHandle('dev-null');
        if ($handle !== null) {
            $status['handle'] = $handle;
        }
        try {
            $session = $this->connect();
            return [...$status, 'available' => true, 'handle' => '@' . $session['handle']];
        } catch (RemoteSessionFailure $error) {
            return [...$status, 'reason' => $error->getMessage(), 'detail' => $error->detail];
        } catch (ClientExceptionInterface) {
            return [...$status, 'reason' => 'dev-null.sh could not be reached. Try checking the connection again.'];
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
        $session = null;
        try {
            if (mb_strlen($text) > 4000 || $images === [] && mb_strlen(trim($text)) < 3) {
                throw new RemoteSessionFailure(
                    'dev-null.sh requires 3–4,000 characters, or an image with up to 4,000 characters.',
                );
            }
            if (count($images) > 3) {
                throw new RemoteSessionFailure('dev-null.sh allows up to three images per post.');
            }
            $files = [];
            $descriptions = [];
            foreach ($images as $index => $image) {
                if ($image->width > 6000 || $image->height > 6000) {
                    throw new RemoteSessionFailure(
                        'dev-null.sh images must be at most 6,000 pixels in either dimension.',
                    );
                }
                if (mb_strlen($image->alt) > 1000) {
                    throw new RemoteSessionFailure(
                        'dev-null.sh image descriptions must be 1,000 characters or shorter.',
                    );
                }
                $files["images[{$index}]"] = File::fromContents($image->bytes, $image->filename, $image->mimeType);
                $descriptions[] = $image->alt;
            }
            $session = $this->connect();
            $csrf = $session['jar']->csrf();
            if ($csrf === '') {
                throw new RemoteSessionFailure(
                    'dev-null.sh did not provide a CSRF token. Import your browser session again.',
                );
            }
            $headers = $this->headers($session['url'], $session['version']) + ['X-XSRF-TOKEN' => $csrf];
            $submitted = true;
            $response = $session['client']->post(
                $session['url'] . '/commits',
                form: ['body' => $text, 'image_descriptions' => $descriptions],
                files: $files,
                headers: $headers,
            );
            $code = $response->getStatusCode();
            if ($code >= 400 && $code < 500 && $code !== 408) {
                return $this->result('failed', "dev-null.sh rejected the post (HTTP {$code}).");
            }
            if (in_array($code, [302, 303], true)) {
                // Laravel redirects after a write; only follow within this origin, and never repeat the POST.
                $location = $response->getHeaderLine('Location');
                $follow = $this->redirectUrl($session['url'], $location);
                $response = $session['client']->get($follow, headers: $this->headers(
                    $session['url'],
                    $session['version'],
                ));
            }
            if (!$response->ok()) {
                return $this->result(
                    'unknown',
                    'dev-null.sh did not confirm the post. Check your profile before posting again.',
                );
            }
            $page = $this->page($response);
            if (($page['props']['errors'] ?? []) !== []) {
                return $this->result('failed', 'dev-null.sh rejected the post. Check the text and image limits.');
            }
            $committed = $page['flash']['committed'] ?? $page['props']['flash']['committed'] ?? null;
            if (!is_array($committed) || preg_match('/^[0-9a-f-]{36}$/iD', (string) ($committed['id'] ?? '')) !== 1) {
                return $this->result(
                    'unknown',
                    'dev-null.sh did not return a post ID. Check your profile before posting again.',
                );
            }
            $path = '/@' . rawurlencode($session['handle']) . '/commits/' . $committed['id'];
            $verified = $session['client']->get($session['url'] . $path, headers: $this->headers(
                $session['url'],
                $session['version'],
            ));
            $commit = $this->page($verified)['props']['commit'] ?? null;
            if (
                !$verified->ok()
                || !is_array($commit)
                || ($commit['id'] ?? null) !== $committed['id']
                || ($commit['user']['handle'] ?? $commit['user']['username'] ?? null) !== $session['handle']
                || ($commit['body'] ?? null) !== ($committed['body'] ?? null)
                || !is_array($commit['images'] ?? null)
                || count($commit['images']) !== count($images)
            ) {
                return $this->result(
                    'unknown',
                    'dev-null.sh accepted the request but the post or images could not be verified. Check your profile before posting again.',
                );
            }
            $url = 'https://dev-null.sh' . $path;
            return $this->moderationResult($url, (string) ($commit['status'] ?? ''));
        } catch (RemoteSessionFailure $error) {
            return $this->result($submitted ? 'unknown' : 'failed', $error->getMessage());
        } catch (ClientExceptionInterface) {
            return $this->result(
                $submitted ? 'unknown' : 'failed',
                $submitted
                    ? 'The connection ended before dev-null.sh confirmed the post. Check your profile before posting again.'
                    : 'dev-null.sh could not be reached. No post was submitted.',
            );
        } finally {
            if ($session !== null) {
                $this->sessions->write('dev-null', $session['jar']->state());
            }
        }
    }

    private function moderationResult(string $url, string $status): array
    {
        // A saved post counts as published, even while awaiting moderation; it is never checked again.
        return (
            $status === 'rejected'
                ? [
                    'platform' => 'dev-null',
                    'url' => $url,
                    'status' => 'failed',
                    'error' => 'dev-null.sh rejected this post during moderation. Do not repost it automatically.',
                ]
                : ['platform' => 'dev-null', 'url' => $url, 'status' => 'published']
        );
    }

    /** @return array{url: string, client: Client, jar: DevNullCookieJar, handle: string, version: ?string} */
    private function connect(): array
    {
        $state = $this->sessions->read('dev-null');
        if (($state['cookies'] ?? []) === []) {
            throw new RemoteSessionFailure('Import your signed-in dev-null.sh browser session to connect.');
        }
        $url = $this->baseUrl();
        $jar = new DevNullCookieJar($state, $url);
        $client = $this->http->timeout(35)->retry(0)->cookies($jar);
        $response = $client->get($url . '/settings/profile', headers: $this->headers($url));
        $this->sessions->write('dev-null', $jar->state());
        if (!$response->ok()) {
            throw new RemoteSessionFailure(
                'dev-null.sh did not accept the profile check. Check the connection and your browser sign-in.',
                'Profile check: GET /settings/profile returned HTTP '
                . $response->getStatusCode()
                . '. The account identity was not verified.',
            );
        }
        $page = $this->page($response);
        $user = $page['props']['auth']['user'] ?? null;
        // The site renamed username to handle; use the same identity for checks and post verification.
        $handle = $user['handle'] ?? $user['username'] ?? null;
        $expected = ltrim($this->sessions->expectedHandle('dev-null') ?? '', '@');
        if (!is_array($user) || !is_string($handle) || $handle === '' || ($user['id'] ?? null) === null) {
            throw new RemoteSessionFailure(
                'dev-null.sh returned an unrecognized account profile. Check for a Postslop update or sign in again.',
                'Profile check: HTTP '
                . $response->getStatusCode()
                . ', but the signed-in user ID or handle is missing. This does not establish an account mismatch.',
            );
        }
        if ($expected === '' || strcasecmp($handle, $expected) !== 0) {
            throw new RemoteSessionFailure(
                'The dev-null.sh session does not match the intended account. Import it again.',
                'Profile check: the signed-in handle differs from the handle saved in Postslop.',
            );
        }
        return [
            'url' => $url,
            'client' => $client,
            'jar' => $jar,
            'handle' => $handle,
            'version' => $page['version'] ?? null,
        ];
    }

    private function headers(string $url, ?string $version = null): array
    {
        return [
            'Accept' => 'text/html, application/xhtml+xml',
            ...($version !== null ? ['X-Inertia' => 'true', 'X-Inertia-Version' => $version] : []),
            'X-Requested-With' => 'XMLHttpRequest',
            'Origin' => $url,
            'Referer' => $url . '/settings/profile',
        ];
    }

    private function page(Response $response): array
    {
        $body = (string) $response->getBody();
        $page = json_decode($body, true);
        if (is_array($page)) {
            return $page;
        }
        if ($body === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $node = new DOMXPath($document)
                ->query('//*[@data-page]')
                ->item(0);
            if ($node === null) {
                return [];
            }
            $encoded = $node->getAttribute('data-page');
            $page = json_decode(str_starts_with(ltrim($encoded), '{') ? $encoded : $node->textContent, true);
            return is_array($page) ? $page : [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('postslop.dev_null_url', 'https://dev-null.sh'), '/');
        $parts = parse_url($url);
        $loopback =
            ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        $path = $parts['path'] ?? '';
        if (
            !is_array($parts)
            || array_key_exists('user', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || $path !== '' && !($loopback && preg_match('~^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~iD', $path) === 1)
            || !(
                ($parts['scheme'] ?? '') === 'https'
                && ($parts['host'] ?? '') === 'dev-null.sh'
                || ($parts['scheme'] ?? '') === 'http'
                && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
            )
        ) {
            throw new RemoteSessionFailure('The dev-null.sh server URL is invalid.');
        }
        return $url;
    }

    private function redirectUrl(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return $baseUrl . $location;
        }
        if (str_starts_with($location, $baseUrl . '/')) {
            return $location;
        }
        throw new RemoteSessionFailure(
            'dev-null.sh returned an unexpected redirect. Check your profile before posting again.',
        );
    }

    private function result(string $status, string $error): array
    {
        return ['platform' => 'dev-null', 'status' => $status, 'error' => $error];
    }
}
