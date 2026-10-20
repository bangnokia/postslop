# Postslop X browser worker

The local PHP publisher sends one JSON request on stdin to `node browser-worker/x-worker.mjs` and receives one JSON result on stdout. Normal Playwright Firefox runs headlessly in Postslop’s private `storage/browser/x/firefox` profile. It does not use or open the user’s regular browser profile.

Install the pinned runtime:

```sh
cd browser-worker
npm ci
npm run setup
```

The `status` operation imports valid X-only cookies from `storage/sessions/x.json` when source authentication changes, loads X, and verifies the actual Profile link matches `expectedHandle`. It never posts. The `publish` operation additionally requires a UUID `requestId`, text, and up to four PNG/JPEG/WebP images of at most 1 MiB each. Images contain `filename`, `mimeType`, base64 `data`, and optional `alt`.

For local diagnosis only, a publish request with `prepareOnly: true` prepares the composer and returns `prepared` without clicking Post or creating a submission journal. A network guard also blocks CreateTweet POST requests during preparation. Its private screenshot is `storage/browser/x/last-prepared.png`. Failures record only a fixed phase, error name, safe code, numeric control counts, and screenshot availability in `last-error.json`; a fresh pre-submit screenshot is captured when possible. The worker never saves raw exception messages, headers, or session values in these diagnostics.

Publishing types into the visible X composer dialog with normal keyboard events and uploads images using that dialog’s controls. The request guard checks the website-generated CreateTweet text and media count against the intended draft before allowing the first request through unchanged. A mismatch is blocked before forwarding. A private journal is written before the single Publish click. Success requires an actual successful CreateTweet response with a post ID, matching account and text, and the expected attached media. A missing response or server error remains `unknown`; that request ID never triggers another click. Explicit X rejections remain failures. The worker stops at sign-in or account checks and includes no challenge bypass or detection overrides.

A CreateTweet request guard blocks additional website-level POST requests after the first one. Firefox is configured with its normal `network.http.request.max-attempts` preference set to `1`, which disables connection retries below browser request interception. This is a transport reliability setting; the worker does not alter browser identity or automation detection. The local zero-header disconnect fixture verifies one upstream POST, an `unknown` result, and no additional request from a later retry of the same ID. This does not establish a universal exactly-once delivery guarantee; unknown outcomes still require checking the account.

The initial Chromium implementation exposed an important failure: it sent three upstream requests from one click when a connection closed before response headers. A Playwright route guard could not intercept those internal retries. Firefox's default also retried; the explicit retry ceiling above removed that behavior in six independent audit trials and in the worker fixture. The browser profile lives in its own engine subdirectory, while request journals and the lock stay at `storage/browser/x` so changing engines does not discard prior outcomes.

Run local browser checks with `npm test`. They exercise multiline text, image bytes and alt text, account mismatch, explicit rejection, server errors, and interrupted responses against a local website fixture. They make no social posts. The fixture is also available as `PORT=4899 X_FIXTURE_PREFIX=/x-web node test/fixture.mjs`; a worker can use its loopback `baseUrl` only with `POSTSLOP_TEST_MODE=1`.

The site can change its controls or reject automated publishing. A verified connection establishes sign-in, not permission to publish. The media alt-text editor is covered by the fixture; its current live UI still needs verification when a post with alt text is requested.
