# Releasing Postslop

The repository root is the Composer package `bangnokia/postslop`, with `bin/postslop` as its executable. It ships a remote account client that requires PHP 8.2+ with curl, OpenSSL, PDO SQLite and iconv. It uses PHP directly and requires no Python. It has no CrabPHP or Node dependency. The backend has its own Composer project and lockfile in `server/`.

## First Packagist registration

1. Sign in to <https://packagist.org> using the GitHub account that owns this repository.
2. Open <https://packagist.org/packages/submit>, enter `https://github.com/bangnokia/postslop`, check it and submit it.
3. Enable Packagist's GitHub integration/automatic updates for the package. This lets new tags be indexed without manually updating the package.
4. Confirm `v0.1.0` appears at <https://packagist.org/packages/bangnokia/postslop> and run `cpx bangnokia/postslop --version`.

Registration needs your Packagist login; GitHub release permissions alone cannot register or configure a Packagist account. Do not put a Packagist API token in repository files, CI output, or release notes.

## Later releases

1. Update `Application::VERSION` in `clients/cli/src/Application.php` and the changelog. Composer's package version comes from the Git tag; do not add a `version` field to `composer.json`.
2. Run `composer validate --strict`, `composer --working-dir=server validate --strict`, `composer check`, and `composer raycast:check`.
3. Test the client as an installed Composer package, from another working directory. `POSTSLOP_CLIENT_BINARY=/path/to/vendor/bin/postslop composer test:client` runs the same synthetic browser/API tests against that installation. Never test by publishing to real accounts.
4. Inspect staged files and the release archive for tokens, cookies, `.env`, private storage, dependencies and browser profiles. Only source and deliberate build artifacts belong in the release.
5. Commit, push, create a new immutable version tag, and publish GitHub release notes. Confirm the GitHub checks pass.
6. Confirm Packagist indexed the tag and `cpx update bangnokia/postslop` can fetch it. If automatic updates are not enabled, use the package's Update button.

The server is deployed independently: install its locked dependencies, rebuild the web client and use `composer reload` to load changed code. A cpx update only updates the remote client. Neither path should recreate or clear an existing database or session store.
