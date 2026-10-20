# Postslop

Write once and publish text and images to **X, Bluesky, and dev-null.sh** using your existing browser sessions. Postslop runs locally on [CrabPHP](https://github.com/crabphp/framework); it does not need developer API keys.

## Raycast

The **Postslop** extension keeps composing, attachments, publishing, and results inside Raycast:

- Write in the multiline textarea and choose destinations.
- **⌘⇧V** attaches an image from the clipboard; the Images field also accepts files.
- Attachments appear in a list below the textarea, with removal and optional descriptions.
- **⌘Enter** hands off publishing to a background command and closes Raycast. A brief completion toast/HUD reports the result; full success clears the composer and failures retain the draft.
- Drafts survive leaving the command. The local API server starts automatically if needed.

See [the extension's local installation and usage guide](raycast/README.md). A background helper processes images without opening a separate app window. The web composer remains available alongside Raycast; both use the same Postslop API.

## Web composer

Open <http://127.0.0.1:8087>. It shows verified account handles, supports pasted or selected images, and saves the draft including attachments in this browser. Use the Publish button or **⌘Enter**; ordinary Enter adds a line in the web textarea.

When all selected destinations publish successfully, the composer clears its text, attachments, and saved draft automatically. Failed or uncertain results keep the draft for recovery.

Both composers support up to four images, or three when dev-null.sh is selected. Images are resized/compressed locally to JPEG of at most 1 MiB each. Transparent backgrounds become white. Platform text limits are checked again by each publishing adapter.

## Run locally

Requires PHP 8.4+ and Composer. Composer installs CrabPHP from GitHub at the exact commit recorded in `composer.lock`; the dependency uses `dev-main` until a tagged release is available. Image inspection needs PHP `fileinfo` and `gd`; the Raycast composer additionally needs Raycast, Node, and the Swift command-line compiler on macOS.

```sh
set -e
composer install
# Only on a new checkout; preserve an existing .env.
cp -n .env.example .env
php scripts/setup.php
php cua serve
```

The server binds to `127.0.0.1:8087`. Use `php cua serve --daemon` to run it in the background, `php cua reload` after backend changes, and `php cua stop` to stop it. Logs are in `storage/logs/server.log`. The health endpoint is `/up`.

X publishing uses Postslop's background browser worker. Install its pinned dependencies and private browser runtime once:

```sh
cd browser-worker
npm ci
npm run setup
```

Normal publishing opens no browser window. The worker uses its own private profile, verifies the intended account, and operates X's website controls. [Worker details and checks](browser-worker/README.md).

## Browser sessions

Verify the active account on each site, then import the Firefox profile explicitly:

```sh
python3 scripts/import_firefox_sessions.py \
  --profile '/absolute/path/to/firefox/profile' \
  --x-handle YOUR_X_HANDLE \
  --bluesky-handle YOUR_BLUESKY_HANDLE \
  --dev-null-handle YOUR_DEV_NULL_HANDLE
```

`--check-only` checks capture without exporting. The importer reads stable private SQLite snapshots, including committed WAL data, without closing Firefox. It imports only the three sites, skips partitioned/container cookies and X's multiple-account cookie, and keeps only the active Bluesky account.

X imports cookies into the app-owned headless browser. Dev-null.sh uses cookies and CSRF headers through Crab's HTTP client. Bluesky keeps its active session tokens in browser localStorage; Postslop refreshes those tokens when necessary. The adapters verify the account against the imported handle before posting. X's website constructs its posting request; dev-null.sh uses its current Inertia form and image-upload behavior.

Sessions expire and websites change. An unavailable account shows a reason; reimport the session when necessary. The UI never treats a cookie file's existence as proof of a working sign-in.

X can accept a session for account checks while rejecting a publishing request. Error 226 is an explicit automation rejection; error 344 is reported by X as an action limit and is not, by itself, proof that an account-wide daily quota was exhausted. Postslop preserves the draft and the failed result, and does not retry automatically or switch to manual browser posting. The former HTTP-only X adapter remains available with `POSTSLOP_X_TRANSPORT=http` for explicit diagnostics and its existing fixtures; it is never an automatic fallback.

For a separately verified Chrome Default session on this Mac, `scripts/import_chrome_x.py --profile '/absolute/path/to/Chrome/Default' --expected-handle YOUR_X_HANDLE` writes only a private `storage/sessions/x-chrome.json` candidate. It reads the narrowly scoped X cookies using Chrome's existing Keychain entry. The candidate must be checked against the intended account over HTTP before it replaces the active session. Importing a session does not establish that X will accept writes.

## Results and duplicates

Each submission has a request ID, payload fingerprint, and private receipt. Before every remote side effect, the destination is recorded durably. Repeating an unchanged request ID returns its recorded result without sending it again. Changing a request's content under the same ID is rejected.

A confirmed post includes its clean destination URL. A lost or ambiguous response remains unknown, and no automatic publishing retry occurs. Keep the draft and use Check Result. A saved dev-null.sh post may await moderation; it must not be submitted again while awaiting review.

## Private local data

`storage/`, `.env`, dependencies, compiled native binaries, and local drafts are excluded from Git. Session files use `0600` permissions in a `0700` directory. Social credentials stay in `storage/sessions/`; Raycast reads only `storage/client-token` for the local server. The local API checks the host, origin, and bearer token, and the server does not expose private storage through HTTP.

[Local API contract](docs/api.md). [Reproducible HTTP simulation](postman/README.md).

## Checks

```sh
composer format:check
composer lint
php tests/core.php
php tests/browser-publisher.php
php tests/x-rejections.php
php tests/dev-null-review.php
node --check public/app.js
cd browser-worker
npm test
cd ../raycast
npm run typecheck
npm run lint
npm run build
```

The core checks cover image validation, repeated and interrupted submissions, locking, private writes, and crash recovery. The Postman simulation runs the real Crab HTTP client against local upstream stand-ins. Live acceptance is checked separately; a fixture pass alone is not a claim that a social site accepted a post.
