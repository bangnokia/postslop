# Postslop remote account client

This is the `bangnokia/postslop` Composer package, run with `cpx`. It connects browser sessions to an existing Postslop server and manages its accounts. The backend, database and publishing workers run on that server.

Requires cpx/Composer, PHP 8.2+ with curl, OpenSSL, PDO SQLite and iconv. These extensions are included in typical Homebrew PHP installations; no Python or Node is needed. Chrome imports work on macOS; Firefox imports work on macOS and Linux. macOS may ask permission to read Chrome Safe Storage from Keychain.

```sh
# Set these in your terminal; use your own server and token.
export POSTSLOP_SERVER_URL=https://YOUR_SERVER
export POSTSLOP_REMOTE_TOKEN=YOUR_TOKEN

cpx bangnokia/postslop setup threads
cpx bangnokia/postslop setup dev-null --dev-null=YOUR_HANDLE
cpx bangnokia/postslop setup threads --threads=YOUR_HANDLE --browser=firefox
cpx bangnokia/postslop accounts
cpx bangnokia/postslop accounts check threads
cpx bangnokia/postslop accounts disable threads
cpx bangnokia/postslop accounts enable threads
cpx bangnokia/postslop accounts remove threads
```

`--to=URL` and `--token=TOKEN` override the environment. Prefer the token environment variable so the token is not part of the command arguments. No configuration, sessions, or tokens are stored in cpx's package cache. Read the server's account status to confirm sign-in; importing cookies alone is not proof that a site still accepts them.

Use `--profile=DIR` for a particular browser profile. `setup --check-only` reads the selected browser sessions without contacting any server; output contains only handles and cookie counts. For noninteractive setup, name each site and give its handle explicitly. `accounts check SITE` contacts only that site's check endpoint. `accounts --refresh` explicitly checks all accounts.

Postslop's `--json` option prints structured results without session values. cpx may print its own package installation/status messages; automated consumers needing only JSON should use the installed `vendor/bin/postslop` executable directly. The executable exits 0 on success, 2 for input/connection/protocol errors, and 4 when an imported account is not ready. Listing an unavailable account is still a successful listing.

```sh
cpx update bangnokia/postslop             # update the cached client now
cpx bangnokia/postslop:0.1.0 --version   # use a specific release
```

The package is discovered through Packagist; a GitHub release alone is not enough. Before its first registration, a maintainer must submit `https://github.com/bangnokia/postslop` at <https://packagist.org/packages/submit>. See [releasing](../../docs/releasing.md).

For a source checkout, `php bin/postslop --help` works without installing server dependencies. `server/bin/postslop` is the separate full local CLI, which runs the shared core and requires the backend dependencies. Both commands use the same PHP browser readers. This remote client never publishes directly to a social site.
