# Local publishing verification

These mocks model the authenticated website requests used by Postslop. They contain fake accounts and no real cookies. They were written from the current X web bundles, Bluesky browser session/PDS responses, and dev-null.sh's Inertia forms and responses inspected during implementation. A published owner mock was not searched because this verification is local-only and does not authenticate to Postman cloud.

The simulation runs the three upstream dependencies; Postslop itself must run as the real CrabPHP server. No mock is deployed or uploaded.

From the `postslop` directory:

```sh
python3 scripts/prepare-simulation.py
npx --yes --package=postman-cli@1.75.0 postman simulation run postman/simulations/publishing.sim.yaml --port 4900 --output ndjson > storage/simulation.ndjson 2> storage/simulation.err
```

In another terminal, start an isolated Postslop server:

```sh
POSTSLOP_SESSIONS_PATH="$PWD/storage/test-sessions" \
POSTSLOP_POSTS_PATH="$PWD/storage/test-posts" \
POSTSLOP_TOKEN_PATH="$PWD/storage/test-client-token" \
POSTSLOP_X_TRANSPORT=http \
POSTSLOP_X_URL=http://127.0.0.1:4900/x \
POSTSLOP_X_UPLOAD_URL=http://127.0.0.1:4900/x \
POSTSLOP_X_BUNDLE_URL=http://127.0.0.1:4900/x/main.js \
POSTSLOP_BLUESKY_URL=http://127.0.0.1:4900/bluesky \
POSTSLOP_DEV_NULL_URL=http://127.0.0.1:4900/dev-null \
APP_URL=http://127.0.0.1:8091 SERVER_ADDRESS=tcp://127.0.0.1:8091 php cua serve --single --no-watch
```

Then run:

```sh
python3 scripts/verify-publishing.py
```

This exercises verified identity, multipart image uploads, alt text, a three-destination publication, repeated Enter with the same request ID, conflicting content, invalid image bytes, and an X server error after it accepted a post. The latter must remain `unknown`; resubmitting the same request ID must not create another upstream post.

The `request` events in `storage/simulation.ndjson` establish that Crab's real HTTP client reached every member. These stand-ins establish request wiring and local error handling; live service acceptance is a separate check. In particular, they cannot prove that X will keep accepting browser sessions, or simulate future browser challenges.

Stop the simulation using Ctrl-C or:

```sh
pkill -INT -f 'simulation run postman/simulations/publishing.sim.yaml'
```

The simulation and Postslop test server should be restarted after handler edits. Each mock also offers local `/__reset` and `/__stats` fixture endpoints. Session files, publication records and request logs stay under ignored `storage/`.

## Background X browser integration

The current X publisher uses the site's UI in a headless browser. Its local website fixture comes from `browser-worker/test/fixture.mjs` and is mirrored in `mocks/x-browser/default.js` for a local Postman run. It models the observed composer controls and response shapes; it cannot predict X's bot checks. It is a test fixture and must not be deployed.

Start the browser simulation:

```sh
npx --yes --package=postman-cli@1.75.0 postman simulation run postman/simulations/browser-publishing.sim.yaml --port 4902 --output ndjson > storage/browser-simulation.ndjson 2> storage/browser-simulation.err
```

Start the isolated app with the environment above, replacing the simulation port with `4902`, setting `POSTSLOP_X_TRANSPORT=browser`, and adding:

```sh
POSTSLOP_TEST_MODE=1
POSTSLOP_X_BROWSER_PROFILE="$PWD/storage/test-browser/profile"
POSTSLOP_POSTS_PATH="$PWD/storage/test-browser-posts"
```

Then run `python3 scripts/verify-browser-publishing.py`. It verifies the actual PHP process bridge, actual headless browser, text/image/alt transport, receipt recovery, and no new click for an already-recorded request ID. Use only the fake session files created by `scripts/prepare-simulation.py`. The browser worker's `npm test` covers rejected and lost responses, account mismatch, challenge detection, and its transport retry ceiling.
