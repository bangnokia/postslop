# Testing against fake sites

`server/tests/fakes/` holds stand-ins for X, Bluesky, dev-null.sh and LinkedIn with fake accounts and no real cookies. They were written from the sites' observed web requests: X's web bundles, Bluesky's session and PDS responses, dev-null.sh's Inertia forms, and LinkedIn's Voyager media upload and share requests. They prove wiring and error handling, not that a real site will accept a post, and they cannot predict a site's bot checks. Never deploy them.

Every check below uses `storage/test-*` paths and its own database, never your sessions or history. Run the commands from `server/`.

## Fake sessions and fake sites

```sh
python3 scripts/prepare-simulation.py
node tests/fakes/server.mjs --port=4900
```

The sites answer under `/x`, `/bluesky` and `/dev-null`, each with `/__reset` and `/__stats`. `--browser` serves X's website composer for the background browser worker instead of its HTTP API.

## The HTTP API

In another terminal, start an isolated server:

```sh
DB_DSN="sqlite:$PWD/storage/test-database.sqlite" \
POSTSLOP_SESSIONS_FILE="$PWD/storage/test-sessions.json" \
POSTSLOP_LOCK_FILE="$PWD/storage/test-publishing.lock" \
POSTSLOP_MEDIA_PATH="$PWD/storage/test-media" \
POSTSLOP_TOKEN=test-token \
POSTSLOP_X_TRANSPORT=http \
POSTSLOP_X_URL=http://127.0.0.1:4900/x \
POSTSLOP_X_UPLOAD_URL=http://127.0.0.1:4900/x \
POSTSLOP_X_BUNDLE_URL=http://127.0.0.1:4900/x/main.js \
POSTSLOP_BLUESKY_URL=http://127.0.0.1:4900/bluesky \
POSTSLOP_DEV_NULL_URL=http://127.0.0.1:4900/dev-null \
POSTSLOP_LINKEDIN_URL=http://127.0.0.1:4900/linkedin \
CACHE_STORE=array APP_URL=http://127.0.0.1:8091 SERVER_ADDRESS=tcp://127.0.0.1:8091 \
sh -c 'php cua migrate && php cua serve --single --no-watch'
```

Then `python3 scripts/verify-publishing.py` checks identity, multipart image uploads, alt text, a three-destination post, a repeated request ID, conflicting content, invalid image bytes, and an X server error after it accepted a post. That last one must stay `unknown`, and repeating it must not post again.

The same environment variables point `bin/postslop` at the fakes, so the command line can be checked the same way without a server.

## The background browser

Start the fakes with `--browser --port=4902`, and the server with the environment above using port 4902, plus:

```sh
POSTSLOP_X_TRANSPORT=browser
POSTSLOP_TEST_MODE=1
POSTSLOP_X_BROWSER_PROFILE="$PWD/storage/test-browser/profile"
```

Then `python3 scripts/verify-browser-publishing.py` runs the PHP process bridge, the real headless browser, text, image and alt text, and checks that a recorded request ID makes no new click. The worker's own `npm test` covers rejected and lost responses, account mismatch, challenges and its retry ceiling.

## Raycast

With the fakes on port 4900, from `clients/raycast/`:

```sh
node scripts/verify-client.mjs
node scripts/verify-background.mjs
```

These run the extension's real client, which calls `bin/postslop`, with isolated storage.
