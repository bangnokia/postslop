# Accounts

Postslop publishes with the browser sessions you are already signed in with, so no site needs developer API keys. It holds one account per site.

## Import from your browser

`postslop setup` (`composer accounts:import` from the repository root) reads Chrome's last used profile, asks which account is signed in on each site, and saves only the selected sites' sessions in one file, `server/storage/sessions.json`. Check the accounts in the browser first. Postslop refuses a session that belongs to another account than the handle you give. Chrome encrypts its cookies, so macOS asks once to let Postslop use "Chrome Safe Storage": choose Allow. Chrome imports work on macOS; on Linux, use Firefox. `--browser=firefox` imports from Firefox's default profile instead, `--profile=DIR` picks another profile, and `--check-only` reads without saving. Name sites, such as `postslop setup x`, to import only those.

For Threads, sign in at <https://www.threads.com/> and run `postslop setup threads --threads=YOUR_HANDLE`. Threads uses its own imported cookies through Crab's HTTP client, checks the signed-in identity, and supports text up to 500 characters and Postslop's usual maximum of four images. It appears in the web and Raycast composers and supports drafts and scheduling through the same core. See [Threads setup and limitations](threads.md).

## Manage accounts

`postslop accounts disable SITE` turns one off without forgetting it: the composer stops offering it and nothing is published to it. `enable` turns it back on and `remove` deletes its session; its posts stay in the history. The web client's Accounts page does the same.

Each account card has its own **Check again** button, plus the session's import source and time, last check time, and details when verification fails. **Check all** checks every enabled account. A single check is also available through `POST /api/v1/accounts/{platform}/check`; it returns that account and never checks another site. See the [HTTP API](api.md).

## From another computer

When Postslop runs on another machine, import on the computer with the browser and send the sessions there: `bin/postslop setup x --to=https://postslop.example.com --token=TOKEN`, where the token is that server's `POSTSLOP_TOKEN` (or set `POSTSLOP_REMOTE_TOKEN`). Nothing is saved locally. Sessions sign in as you, so `--to` needs https, except for localhost, a private network or Tailscale.

The `bangnokia/postslop` Composer package does the same through [cpx](https://github.com/laravel/cpx), without a repository checkout, Node, or a local Postslop server:

```sh
# Set POSTSLOP_REMOTE_TOKEN to your server's access token in this terminal first.
cpx bangnokia/postslop setup threads --to=https://YOUR_SERVER
cpx bangnokia/postslop accounts check threads --to=https://YOUR_SERVER
```

The first command asks for the handle signed in in your browser. Add `--threads=YOUR_HANDLE` to provide it explicitly, or `--browser=firefox` to use Firefox. Use `setup` without a site to choose several accounts. `POSTSLOP_SERVER_URL` can replace `--to`; `--check-only` reads the browser without sending anything. cpx downloads and caches the client, checks for updates periodically, and `cpx update bangnokia/postslop` updates it immediately. Browser sessions are sent only to the server you name, where the shared core checks the intended account.

The package exposes `setup` and `accounts` (including `check`, `enable`, `disable`, and `remove`); publish through the web client or Raycast. The full local CLI and backend remain in `server/`; the root `bin/postslop` is the standalone remote client. Its requirements, options and exit codes are in the [remote account client guide](../clients/cli/README.md). Packagist must have indexed a release before the public `cpx` command can install it; see [releasing](releasing.md).

## How each site is used

The shared PHP readers in `clients/cli/src/` read the browser's files without closing it or changing anything, and make no network requests. They skip partitioned and container cookies and X's multiple-account cookie, and keep only the active Bluesky account. Chrome's Bluesky session is read from its localStorage LevelDB files.

X runs in the app-owned headless browser. Postslop hands it the X cookies from `sessions.json` with each request; it loads them into its own profile in `server/storage/browser/x/` and checks the signed-in account before posting. dev-null.sh uses cookies and CSRF headers through Crab's HTTP client. LinkedIn does too, calling the same web API (Voyager) linkedin.com's own pages use: `li_at` signs in, and the `JSESSIONID` value is the CSRF token. Its handle is the name in your profile address, `linkedin.com/in/NAME`. LinkedIn's web API is undocumented and LinkedIn discourages automation, so treat it as the most fragile destination. Bluesky keeps its session tokens from localStorage and refreshes them when needed. Sessions expire and sites change: an unavailable account shows a reason, so import it again when needed.

X can accept a session for account checks while rejecting a post. Error 226 is an explicit automation rejection. Error 344 is reported as an action limit, and is not by itself proof that a daily quota ran out. Postslop keeps the draft and the failed result, and never retries automatically. The former HTTP-only X adapter remains available with `POSTSLOP_X_TRANSPORT=http` for diagnostics; it is never an automatic fallback.
