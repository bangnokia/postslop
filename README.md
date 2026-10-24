# Postslop

Write once and publish text and images to **X, Bluesky, dev-null.sh, LinkedIn and Threads** using the sessions you are already signed in with. No developer API keys. Postslop runs on your Mac, on [CrabPHP](https://github.com/crabphp/framework). [Threads support is experimental](docs/threads.md); automated checks use local fakes and live publishing remains unverified.

```
 Raycast ──────┐
 AI / scripts ─┴─▶ bin/postslop (CLI) ─┐
                                       ▼
                               Core (server/app/Core) ── SQLite, sessions, images
                                       ▲
 web client ──▶ /api/v1 (HTTP) ────────┤
 schedule ──▶ queue (postslop serve) ──┘
```

One core holds every rule: drafts, scheduling, history, and never posting the same thing twice. It has two front doors. The **command line** runs the core directly, so Raycast and AI agents need no server running. The **HTTP API** serves the web client, and Raycast when Postslop runs on another machine.

| Folder | What |
| --- | --- |
| `server/` | The core, the CLI, the HTTP API, the X browser worker, private storage |
| `clients/web/` | The web client: Vite, React, TypeScript |
| `clients/raycast/` | The Raycast extension, which calls the CLI, or the API of a Postslop on a server |
| `docs/` | [HTTP API](docs/api.md) and [testing against fake sites](docs/testing.md) |

## Install

Requires PHP 8.4+ with `fileinfo` and `gd`, Composer, Node 22+ and Python 3. Composer installs CrabPHP from GitHub at the exact commit in `composer.lock`.

```sh
composer deps                 # locked server, web and browser-worker dependencies
composer setup                # create server/.env if missing; private storage and database
# Set POSTSLOP_TOKEN in server/.env before exposing the server.
composer browser:setup        # X's private headless browser
composer build                # web client into server/public
composer accounts:import      # import your signed-in sessions from Chrome
```

Run these commands from the repository root. The root Composer file provides shortcuts; PHP dependencies and their lockfile remain in `server/`. Use `composer deps` to install them. Raycast is optional: `composer raycast:install` installs its dependencies, and `composer raycast:build` builds it on macOS.

To call it as `postslop` from anywhere, link it onto your path from the repository root: `ln -s "$PWD/server/bin/postslop" /opt/homebrew/bin/postslop`.

## Root commands

```sh
composer start                # start in the background
composer server:status        # inspect the background server
composer reload               # load updated code while keeping the listening socket
composer restart              # stop and start again
composer stop                 # gracefully stop the background server
composer serve                # foreground development server; Ctrl+C stops it
```

These use `server/.env` and the same CrabPHP server commands as before. The schedule and queues start when `SERVER_SCHEDULE` and `SERVER_QUEUE` are enabled. Background servers do not watch files; use `composer reload` after changes. `stop`, `reload`, `restart` and `server:status` manage the background server started by `composer start`; stop a foreground `composer serve` with Ctrl+C. Composer's built-in `status` command is unrelated, hence `server:status`.

| Command | Purpose |
| --- | --- |
| `composer cli -- COMMAND [ARGS]` | Any Postslop CLI command, including drafts, history and scheduling |
| `composer accounts` | Check social accounts |
| `composer accounts:import -- [SITES] [OPTIONS]` | Import local browser sessions or push them with `--to=URL` |
| `composer cua -- COMMAND [ARGS]` | Framework commands, including migrations, schedule and queue inspection |
| `composer build` / `composer web:dev` | Build the web client / run Vite |
| `composer raycast:dev` / `composer raycast:build` | Develop / build Raycast on macOS |
| `composer format` / `composer lint` | Format / lint PHP |
| `composer test` | Server and browser checks against fake sites |
| `composer check` | PHP formatting, lint, all tests and the web build |
| `composer raycast:check` | Raycast TypeScript and lint checks |
| `composer run --list` | List every shortcut and its description |

Pass arguments after `--`, for example:

```sh
composer cli -- post "A saved draft" --to=threads
composer cli -- posts --status=scheduled
composer accounts:import -- threads --threads=YOUR_HANDLE --to=https://YOUR_SERVER
composer cua -- schedule:list
composer cua -- queue:failed
composer start -- --address=tcp://0.0.0.0:8123
```

Remote account imports use `POSTSLOP_REMOTE_TOKEN` or an explicit `--token=TOKEN`. `composer cli -- accounts --json` keeps JSON on standard output; avoid Composer's `--quiet` option, which also suppresses command output.

## Command line

```sh
postslop accounts                          # which accounts are ready
postslop accounts disable bluesky          # stop using one (enable turns it back on, remove forgets it)
postslop post "Hello"                      # save a draft
postslop post "Hello" --now                # publish now
postslop post "Hello" --at="tomorrow 9am"  # schedule (local time)
postslop post "Look" --image=shot.png --to=x,bluesky --now
postslop posts --status=scheduled          # history, newest first
postslop show ID | publish ID | retry ID | schedule ID --at=… | delete ID
postslop serve                             # web client, API, schedule and queue
```

**A post is a draft unless you say `--now` or give a time.** So an AI agent can prepare posts safely, and nothing goes public without an explicit flag. `postslop help post` lists every option.

For scripts and agents, every command takes `--json` and prints exactly one JSON document, errors included. `--input=-` reads a whole post as JSON from standard input, with images by path and their descriptions. Exit codes are stable: `0` ok, `2` invalid, `3` partly published, `4` failed, `5` unknown outcome, `6` busy, `7` conflict, `8` not found. `--request-id=UUID` makes a command safe to repeat, and `--dry-run` checks a post and the accounts without saving anything. [docs/agents.md](docs/agents.md) is a guide to hand an AI agent.

### Scheduling

`postslop serve` runs CrabPHP's schedule and queue beside the web server when `SERVER_SCHEDULE` and `SERVER_QUEUE` are on in `.env`; no OS cron or launchd job is involved. Every minute the schedule pushes a `PublishDuePosts` job onto the queue (the `jobs` table), which hands each due post's destinations to the sites' own queues. Each site, `x`, `bluesky`, `dev-null`, `linkedin` and `threads`, has its own queue and worker (`config/queue.php`) and its own lock (`storage/publishing-x.lock` and so on), so one post goes to every site at the same time while a site never gets two posts at once. A repeated or retried job is harmless because the core never publishes a delivery twice. `php cua schedule:list` shows the task and `php cua queue:failed` shows jobs that gave up. `postslop run-due` still publishes due posts by hand.

## Web client

`postslop serve` (or `php cua serve --daemon` in `server/`) serves the built client at `SERVER_ADDRESS` (<http://127.0.0.1:8087> by default) and asks for the access token once:

- **Sidebar**: new post (or press <kbd>C</kbd>), the calendar, posts by status with counts, each account's state, and light, dark or system theme.
- **Calendar**: a month grid and a week by the hour. Click a free day or hour to write a post scheduled for then; click a post to open it.
- **Posts**: a grid of up to four columns, filtered by scheduled, drafts, needs attention or published, with search.
- **Compose**: as a page or a modal over any page. Per-site character counters and previews, paste, drop or pick images with descriptions, publish now or schedule, and save drafts. Publishing hands the post to the queue at once; a toast reports the result. The unsent draft, images included, is kept in this browser.
- **Post**: opens in a drawer. Edit, publish, reschedule or delete a draft; retry only the failed destinations of a sent post.
- **Accounts**: which sessions are ready, why the others are not, and the technical details.

Build it once, and again after changing it:

```sh
composer build    # writes server/public
composer web:dev  # work at http://127.0.0.1:5173 against the running server
```

## Raycast

The **Postslop** extension composes inside Raycast. It runs the local CLI, so it needs no server; or set its **Server URL** and **Access Token** preferences to post through a Postslop on another machine. Press ⌃⇧V to paste an image, and ⌘Enter to hand the post to a background command and close Raycast. A HUD reports the result. See [its guide](clients/raycast/README.md).

## Browser sessions

`postslop setup` reads Chrome's last used profile, asks which account is signed in on each site, and saves only the selected sites' sessions in one file, `server/storage/sessions.json`. Check the accounts in the browser first. Postslop refuses a session that belongs to another account than the handle you give. Chrome encrypts its cookies, so macOS asks once to let Postslop use "Chrome Safe Storage": choose Allow. `--browser=firefox` imports from Firefox's default profile instead, `--profile=DIR` picks another profile, and `--check-only` reads without saving. Name sites, such as `postslop setup x`, to import only those.

Postslop holds one account per site. `postslop accounts disable SITE` turns one off without forgetting it: the composer stops offering it and nothing is published to it. `enable` turns it back on and `remove` deletes its session; its posts stay in the history. The web client's Accounts page does the same.

When Postslop runs on another machine, import on the computer with the browser and send the sessions there: `bin/postslop setup x --to=https://postslop.example.com --token=TOKEN`, where the token is that server's `POSTSLOP_TOKEN` (or set `POSTSLOP_REMOTE_TOKEN`). Nothing is saved locally. Sessions sign in as you, so `--to` needs https, except for localhost, a private network or Tailscale.

Under the hood, `scripts/import_chrome_sessions.py` and `scripts/import_firefox_sessions.py` read the browser's files without closing it or changing anything, and make no network requests. They skip partitioned and container cookies and X's multiple-account cookie, and keep only the active Bluesky account. Chrome's Bluesky session is read from its localStorage LevelDB files.

X runs in the app-owned headless browser. Postslop hands it the X cookies from `sessions.json` with each request; it loads them into its own profile in `server/storage/browser/x/` and checks the signed-in account before posting. dev-null.sh uses cookies and CSRF headers through Crab's HTTP client. LinkedIn does too, calling the same web API (Voyager) linkedin.com's own pages use: `li_at` signs in, and the `JSESSIONID` value is the CSRF token. Its handle is the name in your profile address, `linkedin.com/in/NAME`. LinkedIn's web API is undocumented and LinkedIn discourages automation, so treat it as the most fragile destination. Bluesky keeps its session tokens from localStorage and refreshes them when needed. Sessions expire and sites change: an unavailable account shows a reason, so import it again when needed.

X can accept a session for account checks while rejecting a post. Error 226 is an explicit automation rejection. Error 344 is reported as an action limit, and is not by itself proof that a daily quota ran out. Postslop keeps the draft and the failed result, and never retries automatically. The former HTTP-only X adapter remains available with `POSTSLOP_X_TRANSPORT=http` for diagnostics; it is never an automatic fallback.

For Threads, sign in at <https://www.threads.com/> and run `postslop setup threads --threads=YOUR_HANDLE`. Threads uses its own imported cookies through Crab's HTTP client, checks the signed-in identity, and supports text up to 500 characters and Postslop's usual maximum of four images. It appears in the web and Raycast composers and supports drafts and scheduling through the same core. See [Threads setup and limitations](docs/threads.md).


## Never twice

Each post's ID is its request ID, with a fingerprint of its content. Before contacting each site, Postslop records that destination as unknown. Repeating an ID returns the recorded result without sending again, and different content under the same ID is refused. A lost or ambiguous response stays **unknown** and is never retried: check the account. **Retry** resends only the destinations that definitely failed. A dev-null.sh post that is saved counts as published, even while it awaits moderation.

## Private local data

`server/storage/`, `.env`, dependencies, the built web client and compiled helpers stay out of Git. The database, images and sessions have private permissions, in folders only you can read. Postslop can run on another machine and be reached from anywhere it listens (`SERVER_ADDRESS`). The HTTP API requires the access token, `POSTSLOP_TOKEN` in `server/.env`, as a bearer token and never serves private storage. The web client asks for it once and keeps it in the browser. Leave it empty and the web client and API are open to anyone who can reach the server, which is only safe on a private network. Put HTTPS in front of it when it is reached over a network, since the token travels with every request. Raycast uses the CLI and needs no token, unless it is set to a server's URL.

## Checks

```sh
composer check
composer raycast:check
composer raycast:build    # macOS, including the native image helper
```

The core checks cover repeated and interrupted submissions, locking, drafts, scheduling, retries, validation and private media. [Testing against fake sites](docs/testing.md) runs the real server, CLI, browser worker and Raycast client end to end. A pass against fakes is not a claim that a real site accepted a post.
