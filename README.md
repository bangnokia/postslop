# Postslop

Write once and publish text and images to **X, Bluesky and dev-null.sh** using the sessions you are already signed in with. No developer API keys. Postslop runs on your Mac, on [CrabPHP](https://github.com/crabphp/framework).

```
 Raycast ──────┐
 AI / scripts ─┴─▶ bin/postslop (CLI) ─┐
                                       ▼
                               Core (server/app/Core) ── SQLite, sessions, images
                                       ▲
 web client ──▶ /api/v1 (HTTP) ────────┤
 schedule ──▶ queue (postslop serve) ──┘
```

One core holds every rule: drafts, scheduling, history, and never posting the same thing twice. It has two front doors. The **command line** runs the core directly, so Raycast and AI agents need no server running. The **HTTP API** serves the web client.

| Folder | What |
| --- | --- |
| `server/` | The core, the CLI, the HTTP API, the X browser worker, private storage |
| `clients/web/` | The web client: Vite, React, TypeScript |
| `clients/raycast/` | The Raycast extension, which calls the CLI |
| `docs/` | [HTTP API](docs/api.md) and [testing against fake sites](docs/testing.md) |

## Install

Requires PHP 8.4+ with `fileinfo` and `gd`, Composer, Node 22+ and Python 3. Composer installs CrabPHP from GitHub at the exact commit in `composer.lock`.

```sh
cd server
composer install
cp -n .env.example .env        # only on a new checkout
php scripts/setup.php          # private storage, access token in .env, database
cd browser-worker && npm ci && npm run setup && cd ..   # X's private headless browser
bin/postslop setup             # import your signed-in sessions from Chrome
```

To call it as `postslop` from anywhere, link it onto your path: `ln -s "$PWD/bin/postslop" /opt/homebrew/bin/postslop`.

## Command line

```sh
postslop accounts                          # which accounts are ready
postslop post "Hello"                      # save a draft
postslop post "Hello" --now                # publish now
postslop post "Hello" --at="tomorrow 9am"  # schedule (local time)
postslop post "Look" --image=shot.png --to=x,bluesky --now
postslop posts --status=scheduled          # history, newest first
postslop show ID | publish ID | retry ID | schedule ID --at=… | delete ID
postslop serve                             # web client, API, schedule and queue
```

**A post is a draft unless you say `--now` or give a time.** So an AI agent can prepare posts safely, and nothing goes public without an explicit flag. `postslop help post` lists every option.

For scripts and agents, every command takes `--json` and prints exactly one JSON document, errors included. `--input=-` reads a whole post as JSON from standard input, with images by path and their descriptions. Exit codes are stable: `0` ok, `2` invalid, `3` partly published, `4` failed, `5` unknown outcome, `6` busy, `7` conflict, `8` not found. `--request-id=UUID` makes a command safe to repeat, and `--dry-run` checks a post and the accounts without saving anything.

### Scheduling

`postslop serve` runs CrabPHP's schedule and queue beside the web server when `SERVER_SCHEDULE` and `SERVER_QUEUE` are on in `.env`; no OS cron or launchd job is involved. Every minute the schedule pushes a `PublishDuePosts` job onto the queue (the `jobs` table), and a queue worker publishes the posts that are due. A repeated or retried job is harmless because the core never publishes a post twice. `php cua schedule:list` shows the task and `php cua queue:failed` shows jobs that gave up. `postslop run-due` still publishes due posts by hand.

## Web client

`postslop serve` (or `php cua serve --daemon` in `server/`) serves the built client at `SERVER_ADDRESS` (<http://127.0.0.1:8087> by default) and asks for the access token once:

- **Compose**: per-site character counters, paste, drop or pick images with descriptions, publish now or schedule, and save drafts. The unsent draft, images included, is kept in this browser.
- **Posts**: the history, filtered by scheduled, drafts, needs attention or published. Each post shows its result on each site, with links.
- **Post**: edit, publish, reschedule or delete a draft. Retry only the failed destinations of a sent post.
- **Accounts**: which sessions are ready, and why the others are not.

Build it once, and again after changing it:

```sh
cd clients/web
npm ci
npm run build    # writes server/public
npm run dev      # or work on it at http://127.0.0.1:5173 against the running server
```

## Raycast

The **Postslop** extension composes inside Raycast. It runs the CLI, so it needs no server. Press ⌘⇧V to paste an image, and ⌘Enter to hand the post to a background command and close Raycast. A HUD reports the result. See [its guide](clients/raycast/README.md).

## Browser sessions

`postslop setup` reads Chrome's last used profile, asks which account is signed in on each site, and saves only those three sites' sessions in one file, `server/storage/sessions.json`. Check the accounts in the browser first. Postslop refuses a session that belongs to another account than the handle you give. Chrome encrypts its cookies, so macOS asks once to let Postslop use "Chrome Safe Storage": choose Allow. `--browser=firefox` imports from Firefox's default profile instead, `--profile=DIR` picks another profile, and `--check-only` reads without saving.

Under the hood, `scripts/import_chrome_sessions.py` and `scripts/import_firefox_sessions.py` read the browser's files without closing it or changing anything, and make no network requests. They skip partitioned and container cookies and X's multiple-account cookie, and keep only the active Bluesky account. Chrome's Bluesky session is read from its localStorage LevelDB files.

X runs in the app-owned headless browser. Postslop hands it the X cookies from `sessions.json` with each request; it loads them into its own profile in `server/storage/browser/x/` and checks the signed-in account before posting. dev-null.sh uses cookies and CSRF headers through Crab's HTTP client. Bluesky keeps its session tokens from localStorage and refreshes them when needed. Sessions expire and sites change: an unavailable account shows a reason, so import it again when needed.

X can accept a session for account checks while rejecting a post. Error 226 is an explicit automation rejection. Error 344 is reported as an action limit, and is not by itself proof that a daily quota ran out. Postslop keeps the draft and the failed result, and never retries automatically. The former HTTP-only X adapter remains available with `POSTSLOP_X_TRANSPORT=http` for diagnostics; it is never an automatic fallback.


## Never twice

Each post's ID is its request ID, with a fingerprint of its content. Before contacting each site, Postslop records that destination as unknown. Repeating an ID returns the recorded result without sending again, and different content under the same ID is refused. A lost or ambiguous response stays **unknown** and is never retried: check the account. **Retry** resends only the destinations that definitely failed. A dev-null.sh post that is saved counts as published, even while it awaits moderation.

## Private local data

`server/storage/`, `.env`, dependencies, the built web client and compiled helpers stay out of Git. The database, images and sessions have private permissions, in folders only you can read. Postslop can run on another machine and be reached from anywhere it listens (`SERVER_ADDRESS`). The HTTP API requires the access token, `POSTSLOP_TOKEN` in `server/.env`, as a bearer token and never serves private storage. The web client asks for that token once and keeps it in the browser; setup writes a random one, and you may set your own password there. Put HTTPS in front of it when it is reached over a network, since the token travels with every request. Raycast uses the CLI and needs no token.

## Checks

```sh
cd server
composer format:check && composer lint
php tests/core.php && php tests/api.php
php tests/x-rejections.php && php tests/browser-publisher.php
(cd browser-worker && npm test)
(cd ../clients/web && npm run build)
(cd ../clients/raycast && npm run typecheck && npm run lint && npm run build)
```

The core checks cover repeated and interrupted submissions, locking, drafts, scheduling, retries, validation and private media. [Testing against fake sites](docs/testing.md) runs the real server, CLI, browser worker and Raycast client end to end. A pass against fakes is not a claim that a real site accepted a post.
