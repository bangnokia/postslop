# Running and developing Postslop

How Postslop fits together, how to run and inspect its server, and the rules its core keeps. The [README](../README.md) has the short install.

## How it fits together

```
 Raycast ──────┐
 AI / scripts ─┴─▶ server/bin/postslop ─┐
                                       ▼
                               Core (server/app/Core) ── SQLite, sessions, images
                                       ▲
 web client ──▶ /api/v1 (HTTP) ────────┤
 AI agents ───▶ /api/v1/mcp (HTTP) ───┤
 schedule ──▶ queue (postslop start) ──┘
```

One core holds every rule: drafts, scheduling, history, and never posting the same thing twice. It has two front doors. The **command line** runs the core directly, so Raycast and AI agents need no server running. The **HTTP API** serves the web client, Raycast when Postslop runs on another machine, and remote agents through [MCP](mcp.md).

| Folder | What |
| --- | --- |
| `server/` | The core, the CLI, the HTTP API, the X browser worker, private storage |
| `clients/web/` | The web client: Vite, React, TypeScript |
| `clients/raycast/` | The Raycast extension, which calls the CLI, or the API of a Postslop on a server |
| `clients/cli/` | The lightweight remote account client distributed as `bangnokia/postslop` through Composer/cpx |
| `docs/` | Guides and references, such as the [HTTP API](api.md), [MCP](mcp.md) and [testing against fake sites](testing.md) |

## Requirements and dependencies

The server and full local CLI require PHP 8.4+ with `fileinfo` and `gd`, Composer and Node 22+ (for the web build and X browser worker). Postslop runs on [CrabPHP](https://github.com/crabphp/framework). Composer installs CrabPHP's stable `0.1.x` releases through the GitHub VCS repository, pinned by `server/composer.lock`; CrabPHP is not on Packagist.

Run the commands from the repository root. The root Composer file provides shortcuts; PHP dependencies and their lockfile remain in `server/`. `composer deps` installs the locked server, web and browser-worker dependencies. `composer setup` creates `server/.env` if it is missing, and prepares private storage and the database. Set `POSTSLOP_TOKEN` in `server/.env` before exposing the server. `composer browser:setup` installs X's private headless browser, `composer build` builds the web client into `server/public`, and `composer accounts:import` imports your signed-in sessions from Chrome. Raycast is optional: `composer raycast:install` installs its dependencies, and `composer raycast:build` builds it on macOS.

## The server

```sh
composer start                # production server in the background; never watches
composer server:status        # all running servers and per-worker activity
composer server:status -- --json
composer reload               # load updated code while keeping the listening socket
composer restart              # stop and start again
composer stop                 # gracefully stop the background server
composer dev                  # restart on file changes; Ctrl+C stops it
```

These use `server/.env`. The schedule and queues start when `SERVER_SCHEDULE` and `SERVER_QUEUE` are enabled. `dev` watches files and restarts automatically; `start` never watches, including in the foreground, and refuses `--watch` and `--no-watch`. Use `composer reload` to load production changes. `stop`, `reload` and `restart` manage the background server; stop a foreground server with Ctrl+C. `server:status` lists foreground and background servers, queue workers and schedulers, with per-worker requests, jobs and runs. It exits with 0 when any server is running, or 1 when none is. Composer's built-in `status` command is unrelated, hence `server:status`.

`reload` and `restart` preserve the `--address` and `--workers` overrides supplied at startup. Pass new values after `--` when you want to change them. Settings without explicit overrides continue to follow `server/.env` and configuration.

## Settings and database

Run `composer cua -- describe` to inspect the application, or `composer cua -- describe app` to check settings after editing configuration. Invalid settings, including unknown keys in framework sections, prevent startup, reload and queue workers. The SQLite database defaults to `server/storage/database.sqlite`; `DB_DATABASE` changes its path, and an existing `DB_DSN` still overrides the connection. The default connection's migrations live in `server/database/migrations`.

## Composer shortcuts

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

Remote account imports use `POSTSLOP_REMOTE_TOKEN` or an explicit `--token=TOKEN`. `composer cli -- accounts --json` keeps JSON on standard output; avoid Composer's `--quiet` option, which also suppresses command output. The [command line](cli.md) lists every Postslop command.

## Scheduling

`postslop start` runs CrabPHP's schedule and queue beside the web server when `SERVER_SCHEDULE` and `SERVER_QUEUE` are on in `.env`; no OS cron or launchd job is involved. Every minute the schedule pushes a `PublishDuePosts` job onto the queue (the `jobs` table), which hands each due post's destinations to the sites' own queues. Each site, `x`, `bluesky`, `dev-null`, `linkedin` and `threads`, has its own queue and worker (`config/queue.php`) and its own lock (`storage/publishing-x.lock` and so on), so one post goes to every site at the same time while a site never gets two posts at once. A repeated or retried job is harmless because the core never publishes a delivery twice. `php cua schedule:list` shows the task and `php cua queue:failed` shows jobs that gave up. `postslop run-due` still publishes due posts by hand.

## Never twice

Each post's ID is its request ID, with a fingerprint of its content. Before contacting each site, Postslop records that destination as unknown. Repeating an ID returns the recorded result without sending again, and different content under the same ID is refused. A lost or ambiguous response stays **unknown** and is never retried: check the account. **Retry** resends only the destinations that definitely failed. A dev-null.sh post that is saved counts as published, even while it awaits moderation.

## Private data and hosting

`server/storage/`, `.env`, dependencies, the built web client and compiled helpers stay out of Git. The database, images and sessions have private permissions, in folders only you can read. Postslop can run on another machine and be reached from anywhere it listens (`SERVER_ADDRESS`). The HTTP API requires the access token, `POSTSLOP_TOKEN` in `server/.env`, as a bearer token and never serves private storage. The web client asks for it once and keeps it in the browser. Leave it empty and the web client and API are open to anyone who can reach the server, which is only safe on a private network. Put HTTPS in front of it when it is reached over a network, since the token travels with every request. Raycast uses the CLI and needs no token, unless it is set to a server's URL.

## Checks

```sh
composer check
composer raycast:check
composer raycast:build    # macOS, including the native image helper
```

The core checks cover repeated and interrupted submissions, locking, drafts, scheduling, retries, validation and private media. [Testing against fake sites](testing.md) runs the real server, CLI, browser worker and Raycast client end to end. A pass against fakes is not a claim that a real site accepted a post.
