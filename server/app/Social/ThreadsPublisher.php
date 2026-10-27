<?php

declare(strict_types=1);

namespace App\Social;

use App\Core\Platforms;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\Response;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Threads' website protocol, using only the imported threads.com cookies. The persisted account queries can
 * change upstream; an unrecognized response stops publication. See docs/threads.md for the protocol sources.
 */
// Keep account verification, uploads, and the uncertain-write boundary visible together.
// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods,halstead
final readonly class ThreadsPublisher implements Publisher
{
    private const string USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
    private const string APP_ID = '238260118697367';
    private const string VIEWER_QUERY = '37508608312071323';
    private const string USER_QUERY = '27371348649156974';
    // Relay requires the provider variables declared by this persisted viewer query.
    private const array VIEWER_FLAGS = [
        'BarcelonaIsLoggedIn' => true,
        'BarcelonaHasDearAlgoConsumption' => true,
        'BarcelonaHasEventBadge' => false,
        'BarcelonaGenAIRepliesEnabled' => false,
        'BarcelonaIsSearchDiscoveryEnabled' => false,
        'BarcelonaHasCommunities' => true,
        'BarcelonaHasGameScoreShare' => true,
        'BarcelonaHasPublicViewCountCard' => true,
        'BarcelonaHasCommunityEntityCard' => true,
        'BarcelonaHasScorecardCommunity' => true,
        'BarcelonaHasSportTeamAllegianceCard' => false,
        'BarcelonaHasMusic' => true,
        'BarcelonaHasNewspaperLinkStyle' => false,
        'BarcelonaMessagingHasGroupChats' => false,
        'BarcelonaHasPodcastGuestCard' => false,
        'BarcelonaShouldFulfillLightboxQuery' => false,
        'BarcelonaHasMessaging' => true,
        'BarcelonaHasViewerReplied' => true,
        'BarcelonaHasPrivateRepliesDeprecation' => false,
        'BarcelonaHasGhostPostEmojiActivation' => false,
        'BarcelonaOptionalCookiesEnabled' => true,
        'BarcelonaHasDearAlgoWebProduction' => false,
        'BarcelonaHasWebFavicons' => false,
        'BarcelonaIsCrawler' => false,
        'BarcelonaHasCommunityTopContributors' => false,
        'BarcelonaCanSeeSponsoredContent' => false,
        'BarcelonaShouldShowFediverseM075Features' => true,
        'BarcelonaShouldShowFediverseM1Features' => true,
        'BarcelonaIsInternalUser' => false,
        'BarcelonaHasProfileSelfReplyContext' => true,
        'BarcelonaHasPermalinkIndentation' => false,
        'BarcelonaHasPostAuthorNotifControls' => true,
        'BarcelonaHasInlineReplyComposer' => true,
    ];

    public function __construct(
        private Client $http,
        private SessionStore $sessions,
    ) {}

    public function status(): array
    {
        $status = ['id' => 'threads', 'label' => 'Threads', 'available' => false];
        try {
            $session = $this->connect();
            return [...$status, 'available' => true, 'handle' => $session['handle']];
        } catch (RemoteSessionFailure $error) {
            return [...$status, 'reason' => $error->getMessage(), 'detail' => $error->detail];
        } catch (ClientExceptionInterface) {
            return [...$status, 'reason' => 'Threads could not be reached. Try checking the connection again.'];
        } catch (\RuntimeException) {
            return [...$status, 'reason' => 'Import your signed-in Threads browser session again.'];
        }
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        $submitted = false;
        try {
            if (preg_match_all('/\X/u', $text) > Platforms::TEXT_LIMITS['threads']) {
                throw new RemoteSessionFailure('Threads allows up to 500 characters.');
            }
            if (count($images) > Platforms::maxImages(['threads'])) {
                throw new RemoteSessionFailure('Postslop supports up to four images per Threads post.');
            }
            if (trim($text) === '' && $images === []) {
                throw new RemoteSessionFailure('Threads needs text or an image.');
            }
            $session = $this->connect();
            $children = [];
            foreach ($images as $image) {
                $children[] = [
                    'upload_id' => $this->upload($session, $image, count($images)),
                    'custom_accessibility_caption' => $image->alt,
                ];
            }
            $info = [
                'entry_point' => 'main_tab_bar',
                'reply_control' => 0,
                'text_with_entities' => ['entities' => [], 'text' => $text],
                'self_thread_context_id' => $requestId,
                'excluded_inline_media_ids' => '[]',
                'fediverse_composer_enabled' => true,
            ];
            $body = [
                'audience' => 'default',
                'caption' => $text,
                'creator_geo_gating_info' => '{"whitelist_country_codes":[]}',
                'is_upload_type_override_allowed' => '1',
                'should_include_permalink' => count($images) > 1 ? true : 'true',
                'text_post_app_info' => self::encode($info),
                'web_session_id' => $session['webSession'],
            ];
            $endpoint = match (count($children)) {
                0 => '/api/v1/media/configure_text_only_post/',
                1 => '/api/v1/media/configure_text_post_app_feed/',
                default => '/api/v1/media/configure_text_post_app_sidecar/',
            };
            $body += match (count($children)) {
                0 => ['publish_mode' => 'text_post', 'upload_id' => self::uploadId()],
                1 => ['is_threads' => 'true', ...$children[0]],
                default => [
                    'is_threads' => true,
                    'client_sidecar_id' => self::uploadId(),
                    'children_metadata' => $children,
                ],
            };
            // Uploads do not publish. From this point onward a lost answer must never trigger a resend.
            $submitted = true;
            $response = count($children) > 1
                ? $session['client']->request(
                    'POST',
                    $session['url'] . $endpoint,
                    headers: [
                        ...$session['headers'],
                        'Content-Type' => 'text/plain;charset=UTF-8',
                    ],
                    body: self::encode($body),
                )
                : $session['client']->post(
                    $session['url'] . $endpoint,
                    form: [
                        ...$body,
                        'jazoest' => $session['jazoest'],
                    ],
                    headers: $session['headers'],
                );
            $code = $response->getStatusCode();
            $data = self::json($response);
            if ($code >= 500 || $code === 408) {
                return $this->unknown();
            }
            if ($code >= 400 && $code < 500) {
                return $this->result('failed', 'Threads rejected the post (HTTP ' . $code . ').');
            }
            // Never echo upstream messages: they may contain session values or private page content.
            if (
                $response->ok()
                && ($data['status'] ?? null) === 'fail'
                && ($data['media'] ?? null) === null
                && ($data['feedpost'] ?? null) === null
            ) {
                return $this->result('failed', 'Threads rejected the post. Check the account before retrying.');
            }
            $media = $data['media'] ?? $data['feedpost']['media'] ?? null;
            if (
                !$response->ok()
                || ($data['status'] ?? null) !== 'ok'
                || !is_array($media)
                || !$this->confirmed($media, $session, $text, $images)
            ) {
                return $this->unknown();
            }
            return [
                'platform' => 'threads',
                'status' => 'published',
                'url' => 'https://www.threads.com/@' . $session['handle'] . '/post/' . $media['code'],
            ];
        } catch (RemoteSessionFailure $error) {
            return $submitted ? $this->unknown() : $this->result('failed', $error->getMessage());
        } catch (ClientExceptionInterface|\RuntimeException) {
            return $submitted
                ? $this->unknown()
                : $this->result(
                    'failed',
                    'Threads could not prepare this post. Check the session and connection. No post was submitted.',
                );
        }
    }

    private function connect(): array
    {
        $state = $this->sessions->read('threads');
        $cookies = $this->cookies($state);
        foreach (['sessionid', 'csrftoken', 'ds_user_id'] as $required) {
            if (($cookies[$required] ?? '') === '') {
                throw new RemoteSessionFailure('Import your signed-in threads.com browser session to connect.');
            }
        }
        $expected = ltrim($this->sessions->expectedHandle('threads') ?? '', '@');
        if (preg_match('/^[A-Za-z0-9._]{1,30}$/D', $expected) !== 1 || !ctype_digit($cookies['ds_user_id'])) {
            throw new RemoteSessionFailure('Import the intended Threads account and its handle again.');
        }
        $url = $this->baseUrl();
        $agent = $state['userAgent'] ?? '';
        $webSession = bin2hex(random_bytes(6)) . ':' . bin2hex(random_bytes(6)) . ':' . bin2hex(random_bytes(6));
        $headers = [
            'Accept' => '*/*',
            'User-Agent' => is_string($agent) && preg_match('/^[\x20-\x7E]{1,300}$/D', $agent) === 1
                ? $agent
                : self::USER_AGENT,
            'Cookie' => implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                $cookies,
            )),
            'X-CSRFToken' => $cookies['csrftoken'],
            'X-IG-App-ID' => self::APP_ID,
            'X-ASBD-ID' => '359341',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Instagram-AJAX' => '0',
            'X-Web-Session-ID' => $webSession,
            'X-Bloks-Version-ID' => 'adf1cac17d2514bcaed74eff9cc2398184569a6dae69c5d1efab27ce50e6b83e',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
            'Origin' => $url,
            'Referer' => $url . '/',
        ];
        $client = $this->http->timeout(35)->retry(0);
        // The application HTTP client has redirects disabled, so no cookie can follow a login redirect elsewhere.
        $home = $client->get($url . '/', headers: $headers);
        $code = $home->getStatusCode();
        if ($this->sessionCleared($home)) {
            throw new RemoteSessionFailure(
                'Threads rejected the saved session on this server. Sign in to Threads in your browser, then import it again.',
                'Sign-in check: GET / returned HTTP '
                . $code
                . ' and instructed the browser to expire its session cookie. The saved cookie expiry date does not mean the session is still accepted.',
            );
        }
        if ($code >= 300 && $code < 400) {
            throw new RemoteSessionFailure(
                'Threads redirected the sign-in check before confirming the account. Check your browser sign-in, then import it again.',
                'Sign-in check: GET / returned HTTP '
                . $code
                . '. Postslop did not forward your session through the redirect; the account identity remains unverified.',
            );
        }
        if (!$home->ok()) {
            throw new RemoteSessionFailure(
                'Threads did not accept the account check. Try checking again later.',
                'Sign-in check: GET / returned HTTP '
                . $code
                . '. This response alone does not establish that the session has expired.',
            );
        }
        $html = (string) $home->getBody();
        if (
            preg_match('/"LSD",\[\],\{"token":"([^"\\\\]+)"/', $html, $lsd) !== 1
            || preg_match('/"DTSGInitData",\[\],\{"token":"([^"\\\\]+)"/', $html, $dtsg) !== 1
        ) {
            throw new RemoteSessionFailure(
                'Threads returned an unrecognized sign-in page. Check your browser sign-in or check for a Postslop update.',
                'Sign-in check: HTTP '
                . $code
                . ', but the page is missing the tokens needed for account verification. The page may be a login, challenge, or changed website response.',
            );
        }
        $session = [
            'url' => $url,
            'client' => $client,
            'headers' => $headers,
            'lsd' => $lsd[1],
            'dtsg' => $dtsg[1],
            'userId' => $cookies['ds_user_id'],
            'actorId' => preg_match('/"(?:actorID|USER_ID|viewerId)":"([1-9][0-9]*)"/', $html, $actor) === 1
                ? $actor[1]
                : $cookies['ds_user_id'],
            'jazoest' => '2' . array_sum(array_map(ord(...), str_split($dtsg[1]))),
            'webSession' => $webSession,
        ];
        $viewer = $this->query(
            $session,
            'BarcelonaFeedTimelineViewerQuery',
            self::VIEWER_QUERY,
            array_combine(
                array_map(
                    static fn(string $name): string => '__relay_internal__pv__' . $name . 'relayprovider',
                    array_keys(self::VIEWER_FLAGS),
                ),
                array_values(self::VIEWER_FLAGS),
            ),
        );
        // The root-field headers name backend fields; the response aliases are viewer and user.
        $user = $viewer['viewer']['user'] ?? [];
        if ((string) ($user['pk'] ?? $user['id'] ?? '') !== $session['userId']) {
            throw new RemoteSessionFailure(
                'Threads could not verify the signed-in account. Import the correct session again.',
            );
        }
        $profile = $this->query($session, 'BarcelonaUserDialogQuery', self::USER_QUERY, [
            'userID' => $session['userId'],
        ]);
        $user = $profile['user'] ?? [];
        $handle = $user['username'] ?? null;
        if (
            (string) ($user['pk'] ?? $user['id'] ?? '') !== $session['userId']
            || !is_string($handle)
            || strcasecmp($handle, $expected) !== 0
        ) {
            throw new RemoteSessionFailure('The Threads session does not match the intended account. Import it again.');
        }
        return [...$session, 'handle' => $handle];
    }

    private function query(array $session, string $name, string $id, array $variables): array
    {
        $response = $session['client']->post(
            $session['url'] . '/graphql/query',
            form: [
                'av' => $session['actorId'],
                '__a' => '1',
                'fb_dtsg' => $session['dtsg'],
                'jazoest' => $session['jazoest'],
                'lsd' => $session['lsd'],
                'fb_api_caller_class' => 'RelayModern',
                'fb_api_req_friendly_name' => $name,
                'doc_id' => $id,
                'server_timestamps' => 'true',
                'variables' => self::encode([
                    ...$variables,
                    '__relay_internal__pv__BarcelonaIsLoggedInrelayprovider' => true,
                    '__relay_internal__pv__BarcelonaShouldShowFediverseM1Featuresrelayprovider' => true,
                ]),
            ],
            headers: [
                ...$session['headers'],
                'X-FB-LSD' => $session['lsd'],
                'X-FB-Friendly-Name' => $name,
                'X-Root-Field-Name' => $id === self::VIEWER_QUERY ? 'xdt_text_app_viewer' : 'xdt_text_app_user',
            ],
        );
        $body = self::json($response);
        if (!$response->ok() || !is_array($body['data'] ?? null) || ($body['errors'] ?? []) !== []) {
            throw new RemoteSessionFailure(
                'Threads could not verify this account. Its web API may have changed; import the session again or check for an update.',
            );
        }
        return $body['data'];
    }

    private function upload(array $session, ImageAttachment $image, int $count): string
    {
        $id = self::uploadId();
        $name = 'fb_uploader_' . $id;
        $response = $session['client']->request(
            'POST',
            $session['url'] . '/rupload_igphoto/' . $name,
            headers: [
                ...$session['headers'],
                'Content-Type' => $image->mimeType,
                'X-Entity-Type' => $image->mimeType,
                'X-Entity-Name' => $name,
                'X-Entity-Length' => (string) strlen($image->bytes),
                'Offset' => '0',
                'X-Instagram-Rupload-Params' => self::encode([
                    'is_sidecar' => $count > 1 ? '1' : '0',
                    'is_threads' => '1',
                    'media_type' => 1,
                    'upload_id' => $id,
                    'upload_media_height' => $image->height,
                    'upload_media_width' => $image->width,
                ]),
            ],
            body: $image->bytes,
        );
        $data = self::json($response);
        if (!$response->ok() || ($data['status'] ?? null) !== 'ok' || (string) ($data['upload_id'] ?? '') !== $id) {
            throw new RemoteSessionFailure('Threads did not confirm the image upload. No post was submitted.');
        }
        return $id;
    }

    private function confirmed(array $media, array $session, string $text, array $images): bool
    {
        if (
            !is_string($media['code'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]+$/D', $media['code']) !== 1
            || (string) ($media['user']['pk'] ?? $media['user']['id'] ?? '') !== $session['userId']
            || strcasecmp((string) ($media['user']['username'] ?? ''), $session['handle']) !== 0
            || ($media['caption']['text'] ?? '') !== $text
        ) {
            return false;
        }
        $attached = match ($media['media_type'] ?? null) {
            8 => $media['carousel_media'] ?? [],
            1 => [$media],
            default => [],
        };
        if (!is_array($attached) || count($attached) !== count($images)) {
            return false;
        }
        foreach ($images as $index => $image) {
            if (
                $image->alt !== ''
                && (
                    $attached[$index]['accessibility_caption']
                    ?? $attached[$index]['custom_accessibility_caption']
                    ?? null
                ) !== $image->alt
            ) {
                return false;
            }
        }
        return true;
    }

    private function cookies(array $state): array
    {
        $cookies = [];
        foreach ($state['cookies'] ?? [] as $cookie) {
            if (
                !in_array(
                    $cookie['domain'] ?? '',
                    ['threads.com', '.threads.com', 'www.threads.com', '.www.threads.com'],
                    true,
                )
                || ($cookie['path'] ?? '/') !== '/'
                || !is_string($cookie['name'] ?? null)
                || preg_match('/^[A-Za-z0-9_-]+$/D', $cookie['name']) !== 1
                || !is_string($cookie['value'] ?? null)
                || preg_match('/^[\x21-\x3A\x3C-\x7E]*$/D', $cookie['value']) !== 1
                || ($cookie['expires'] ?? 0) !== -1
                && (!is_numeric($cookie['expires'] ?? null) || $cookie['expires'] <= time())
            ) {
                continue;
            }
            if (array_key_exists($cookie['name'], $cookies) && $cookies[$cookie['name']] !== $cookie['value']) {
                throw new RemoteSessionFailure(
                    'The Threads session contains conflicting cookies. Import a profile with one signed-in account.',
                );
            }
            $cookies[$cookie['name']] = $cookie['value'];
        }
        return $cookies;
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('postslop.threads_url', 'https://www.threads.com'), '/');
        if (
            $url !== 'https://www.threads.com'
            && preg_match('~^http://(?:127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?(?:/[a-z0-9_-]+)*$~iD', $url) !== 1
        ) {
            throw new RemoteSessionFailure('The Threads server URL is invalid.');
        }
        return $url;
    }

    private function sessionCleared(Response $response): bool
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^sessionid=([^;]*)/i', $cookie, $value) !== 1) {
                continue;
            }
            if ($value[1] === '') {
                return true;
            }
            // Max-Age takes precedence over Expires when both are present.
            if (preg_match('/;\s*max-age=(-?[0-9]+)(?:;|$)/i', $cookie, $age) === 1) {
                if ((int) $age[1] <= 0) {
                    return true;
                }
                continue;
            }
            if (preg_match('/;\s*expires=([^;]+)/i', $cookie, $expiry) === 1) {
                $timestamp = strtotime($expiry[1]);
                if ($timestamp !== false && $timestamp <= time()) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function uploadId(): string
    {
        return (string) (int) (microtime(true) * 1000) . random_int(100, 999);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function json(Response $response): array
    {
        $raw = (string) $response->getBody();
        $body = json_decode(str_starts_with($raw, 'for (;;);') ? substr($raw, 9) : $raw, true);
        return is_array($body) ? $body : [];
    }

    private function unknown(): array
    {
        return $this->result(
            'unknown',
            'Threads did not confirm the post and its attachments. Check your profile before posting again; Postslop will not retry automatically.',
        );
    }

    private function result(string $status, string $error): array
    {
        return ['platform' => 'threads', 'status' => $status, 'error' => $error];
    }
}
