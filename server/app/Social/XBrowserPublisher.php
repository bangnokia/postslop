<?php

declare(strict_types=1);

namespace App\Social;

// Validate the subprocess's identity and outcomes at one publishing boundary.
// @mago-expect lint:cyclomatic-complexity
final readonly class XBrowserPublisher implements Publisher
{
    private XBrowserProcess $worker;

    // Paths are explicit so the worker can only receive Postslop's imported session and dedicated profile.
    // @mago-expect lint:excessive-parameter-list
    public function __construct(
        private SessionStore $sessions,
        string $nodeBinary,
        string $workerPath,
        private string $profilePath,
        float $timeoutSeconds = 95,
        private string $baseUrl = 'https://x.com',
    ) {
        $this->worker = new XBrowserProcess($nodeBinary, $workerPath, $timeoutSeconds);
    }

    public function status(): array
    {
        $status = ['id' => 'x', 'label' => 'X', 'available' => false];
        try {
            $input = $this->input('status');
            $result = $this->worker->request($input);
            if (($result['id'] ?? null) !== 'x' || !is_bool($result['available'] ?? null)) {
                return [
                    ...$status,
                    'reason' => $this->reason('unavailable'),
                    'detail' => 'The X browser worker answered without the account status.',
                ];
            }
            if (!$result['available']) {
                $code = is_string($result['code'] ?? null) ? $result['code'] : 'none';
                return [...$status, 'reason' => $this->reason($code), 'detail' => $this->workerDetail($code, $result)];
            }
            $handle = $result['handle'] ?? null;
            if (!is_string($handle) || strcasecmp(ltrim($handle, '@'), $input['expectedHandle']) !== 0) {
                return [
                    ...$status,
                    'reason' => $this->reason('account_mismatch'),
                    'detail' => 'Signed in as @' . ltrim((string) $handle, '@') . ', expected @' . $input['expectedHandle'] . '.',
                ];
            }
            return [...$status, 'available' => true, 'handle' => '@' . $input['expectedHandle']];
        } catch (\RuntimeException|\JsonException $error) {
            return [...$status, 'reason' => $this->reason('unavailable'), 'detail' => $error->getMessage()];
        }
    }

    public function publish(string $requestId, string $text, array $images): array
    {
        if (count($images) > 4) {
            return ['platform' => 'x', 'status' => 'failed', 'error' => 'X allows up to four images per post.'];
        }
        try {
            $input = $this->input('publish');
        } catch (\RuntimeException) {
            return ['platform' => 'x', 'status' => 'failed', 'error' => $this->reason('signed_out')];
        }
        try {
            $result = $this->worker->request([
                ...$input,
                'requestId' => $requestId,
                'text' => $text,
                'images' => array_map(static fn(ImageAttachment $image): array => [
                    'filename' => $image->filename,
                    'mimeType' => $image->mimeType,
                    'data' => base64_encode($image->bytes),
                    'alt' => $image->alt,
                ], $images),
            ]);
        } catch (\RuntimeException|\JsonException) {
            return $this->unknown();
        }
        if (($result['platform'] ?? null) !== 'x') {
            return $this->unknown();
        }
        if (($result['status'] ?? null) === 'published') {
            $url = $result['url'] ?? null;
            $pattern = '~^https://x\\.com/' . preg_quote($input['expectedHandle'], '~') . '/status/([0-9]+)$~iD';
            if (!is_string($url) || preg_match($pattern, $url, $match) !== 1) {
                return $this->unknown();
            }
            return [
                'platform' => 'x',
                'status' => 'published',
                'url' => 'https://x.com/' . $input['expectedHandle'] . '/status/' . $match[1],
            ];
        }
        $code = $result['code'] ?? null;
        if (
            ($result['status'] ?? null) === 'failed'
            && in_array(
                $code,
                [
                    'invalid_request',
                    'session_missing',
                    'signed_out',
                    'account_mismatch',
                    'challenge',
                    'busy',
                    'image_failed',
                    'composer_unavailable',
                    'rejected',
                    'automation_rejected',
                    'rate_limited',
                    'unavailable',
                ],
                true,
            )
        ) {
            return ['platform' => 'x', 'status' => 'failed', 'error' => $this->reason($code)];
        }
        return $this->unknown();
    }

    private function input(string $operation): array
    {
        $handle = ltrim($this->sessions->expectedHandle('x') ?? '', '@');
        if (preg_match('/^[a-zA-Z0-9_]{1,15}$/D', $handle) !== 1) {
            throw new \RuntimeException('The intended X account and imported session are required.');
        }
        return [
            'operation' => $operation,
            'session' => $this->sessions->read('x'),
            'profilePath' => $this->profilePath,
            'expectedHandle' => $handle,
            'baseUrl' => $this->baseUrl,
        ];
    }

    private function reason(mixed $code): string
    {
        // Only known messages cross the process boundary; raw browser errors can contain cookies or page content.
        return match ($code) {
            'invalid_request' => 'X could not accept the publishing request. No post was submitted.',
            'session_missing',
            'signed_out',
                => 'The saved X session is signed out or expired. Import your signed-in session again.',
            'account_mismatch'
                => 'The saved X session does not match the intended account. Import the correct account again.',
            'challenge' => 'X requires an account check before Postslop can publish. No post was submitted.',
            'busy' => 'Postslop is already checking or publishing to X. Try again shortly.',
            'image_failed' => 'X could not finish attaching the image. No post was submitted.',
            'composer_unavailable'
                => 'X’s composer could not be prepared. Your draft is saved and no post was submitted.',
            'rejected' => 'X rejected this post. Keep the draft and check the account before trying again.',
            'automation_rejected'
                => 'X rejected this post as suspected automation (code 226). Postslop stopped without retrying.',
            'rate_limited'
                => 'X rejected this request with its posting-limit error (code 344). Keep the draft and try later.',
            default => 'Postslop could not verify its background X session. Check the connection or import the session again.',
        };
    }

    /**
     * The worker's code, its own message (the worker only reports messages it wrote) and the stage it stopped at.
     */
    private function workerDetail(string $code, array $result): string
    {
        $detail = 'The X browser worker reported code "' . $code . '"';
        if (is_string($result['reason'] ?? null)) {
            $detail .= ': ' . $result['reason'];
        }
        $path = $this->profilePath . '/last-error.json';
        $last = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (is_array($last) && is_string($last['stage'] ?? null)) {
            $detail .= ' (stage: ' . $last['stage'] . ', error: ' . ($last['errorName'] ?? 'Error') . ')';
        }
        return $detail;
    }

    private function unknown(): array
    {
        return [
            'platform' => 'x',
            'status' => 'unknown',
            'error' => 'X did not confirm the post. Check your profile before posting again; Postslop will not retry automatically.',
        ];
    }
}
