# Threads

Threads is an experimental destination in the CLI, HTTP API, web composer and Raycast extension. The integration is tested against a local fake site. Live sign-in and publication have not been verified.

## Setup

Sign in to <https://www.threads.com/> in Chrome or Firefox and check the account handle. An Instagram-only sign-in or old threads.net cookies are not imported.

From `server/`:

```sh
bin/postslop setup threads --threads=YOUR_HANDLE
# Or use Firefox:
bin/postslop setup threads --threads=YOUR_HANDLE --browser=firefox

bin/postslop post "Hello Threads" --to=threads             # saves a draft
bin/postslop post "Hello Threads" --to=threads --now       # explicitly publishes
bin/postslop post "Tomorrow's post" --to=threads --at="tomorrow 9am"
```

For a remote Postslop, run setup on the computer with the browser and add `--to=https://YOUR_SERVER --token=TOKEN`. This uses the existing account-import API. The session stays in that Postslop's private `storage/sessions.json`. No Meta developer account, access token or new browser worker is required for the Threads integration.

Select Threads in either composer once its account is available. Postslop supports a 500-character post and up to four images, or three when also sending to dev-null.sh. Scheduled posts use the `threads` queue and worker under `postslop serve`. Restart a running server after installing this change so it starts that worker.

## Results and limitations

Before sending a post, Postslop checks that the authenticated viewer's ID matches the imported session and that its profile matches the intended handle. It uploads images, submits once, and checks the returned author, text, media count and any supplied image descriptions. It produces a clean `https://www.threads.com/@HANDLE/post/CODE` URL only after those checks pass.

Threads' web API is undocumented and its persisted query IDs and provider flags may change. Sign-in challenges, missing identity data and failed uploads stop before publication. An explicit rejection is eligible for a manual retry through Postslop. A lost response, server error, or unconfirmed post/description stays `unknown` with its content and attachments retained; it is never automatically resent. If Threads does not return an image description, Postslop cannot confirm that description even if the post was saved.

## Protocol reference

The adapter uses the web protocol described by the public [threads-bot source at commit 93f1c38](https://github.com/alpersamur3/threads-bot/tree/93f1c38187ab70c5ec6a60aefed4c3e860aeecac/src): homepage LSD/DTSG tokens, viewer/profile GraphQL queries, `rupload_igphoto`, and the text, image and carousel configuration endpoints. This is a protocol reference, not a runtime dependency. Postslop does not use that library's automatic retries or credential-login behavior.

Image descriptions use `custom_accessibility_caption`, with confirmation required in the returned media. That field's live Threads behavior remains unverified. The fake site's responses exercise Postslop's handling; they do not establish live compatibility.

Run `php tests/threads.php` from `server/` for isolated integration checks. `POSTSLOP_THREADS_URL` can target a loopback fake site for testing; production session requests are restricted to `https://www.threads.com`.
