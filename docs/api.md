# HTTP API

The web client talks to the core through this API. The command line (`bin/postslop`) runs the same core directly; both follow the same rules.

Base URL: `SERVER_ADDRESS` from `server/.env` (`http://127.0.0.1:8087` by default). When `POSTSLOP_TOKEN` is set in `server/.env`, every `/api/` request requires `Authorization: Bearer <POSTSLOP_TOKEN>`, from any host; when it is empty, the API is open to anyone who can reach it. The token is private and must not appear in examples, logs or shared files. `POST` and `PUT` bodies must be `Content-Type: application/json`. Responses are not cached.

## Posts and their lifecycle

```
draft ──▶ scheduled ──▶ publishing ──▶ published | partial | failed | unknown
  └─────────────────────────▲   (publish now)
```

Publishing never makes a request wait on the sites. `mode: "now"` and `POST …/publish` answer at once with the post `publishing` and each result `queued`. Every site has its own queue and worker in `postslop serve`, so the sites are sent at the same time, each one post after another. A result goes `queued`, then `sending`, then its outcome. Poll `GET /api/v1/posts/{id}` until the status is no longer `queued` or `publishing`. A post on its way can no longer be edited or deleted. A result still `queued` after 15 minutes was never sent and becomes `failed`; one left `sending` by a crash becomes `unknown`. (`queued` as a post's status comes only from earlier versions.)

A post is one piece of content for one or more destinations. Each destination has its own result: `published`, `failed` or `unknown`. The post's status adds them up: `published` when every destination published, `partial` when some did, `unknown` when any outcome is unknown, otherwise `failed`.

```json
{
  "id": "00000000-0000-4000-8000-000000000001",
  "status": "partial",
  "text": "A short post",
  "platforms": ["bluesky", "dev-null", "x"],
  "images": [{"id": "…", "mimeType": "image/jpeg", "width": 1200, "height": 800, "alt": "A useful description"}],
  "results": [
    {"platform": "bluesky", "status": "failed", "error": "Refresh this session and try again."},
    {"platform": "dev-null", "status": "unknown", "error": "dev-null.sh did not confirm the post. Check your profile before posting again."},
    {"platform": "x", "status": "published", "url": "https://x.com/example/status/123"}
  ],
  "scheduledAt": null,
  "publishedAt": "2026-10-20T09:00:04Z",
  "createdAt": "2026-10-20T09:00:00Z",
  "updatedAt": "2026-10-20T09:00:04Z"
}
```

Times are UTC. A URL identifies an existing post on that site; it is never an instruction to send again. A dev-null.sh post that is saved counts as published, even while it awaits moderation. `results` is empty until the post is sent.

### Rules against posting twice

- The post's `id` is the client's request ID. Sending the same ID again returns what was recorded and never sends it again. Different content under an existing ID is refused with 409.
- Before contacting a site, the core moves that destination from `queued` to `sending` in one write, so only one worker ever sends it. A crash leaves it `unknown`; a destination never picked up within 15 minutes becomes `failed`.
- `unknown` is never retried, automatically or by `/retry`: the post may already be live. Check the account first.
- Each site sends one post at a time; different sites send at the same time. Publishing a post already on its way returns it unchanged.

## Accounts — `GET /api/v1/accounts`

Postslop holds one account per site. Sign-in checks are cached for 30 seconds; `?refresh=1` checks again. Bluesky may refresh an expired access token with its saved refresh token. X is checked through the private background browser, which opens no window.

```json
{
  "platforms": [
    {"id": "x", "label": "X", "available": true, "handle": "example", "enabled": true, "connected": true, "importedAt": "2026-10-20T17:29:06Z", "source": "firefox", "maxImages": 4, "textLimit": 280},
    {"id": "bluesky", "label": "Bluesky", "available": false, "handle": "example.test", "reason": "Turned off in Postslop.", "enabled": false, "connected": true, "importedAt": "2026-10-20T17:29:06Z", "source": "chrome", "maxImages": 4, "textLimit": 300},
    {"id": "dev-null", "label": "dev-null.sh", "available": false, "handle": null, "reason": "Not connected. Run: postslop setup dev-null", "enabled": true, "connected": false, "importedAt": null, "source": null, "maxImages": 3, "textLimit": 4000}
  ],
  "limits": {"maxImages": 4, "maxImageBytes": 1048576},
  "publishingEnabled": true
}
```

Handles have no leading `@`. A platform can be unavailable even when session data exists; show `reason` and let the user pick other destinations. An optional `detail` holds technical information for diagnosing it, such as the background browser's own error, with secrets removed. `textLimit` is for counters; each publisher enforces its site's exact rule.

`connected` says a session is saved. `enabled` is false for an account turned off in Postslop: it keeps its session, is not checked, and a post still addressed to it fails for that site with nothing sent. Offer only accounts that are connected and enabled.

Each of these answers with the accounts, as `GET /api/v1/accounts?refresh=1` does:

- `PATCH /api/v1/accounts/{site}` with `{"enabled": false}` turns an account off, `true` back on.
- `DELETE /api/v1/accounts/{site}` forgets the account: its session and, for X, its background browser profile. Its posts stay.
- `PUT /api/v1/accounts/{site}` with `{"handle": "example", "session": {"cookies": [...], "origins": [...]}, "source": "firefox"}` saves a session, turned on, and checks it signs in. `postslop setup --to=URL` sends this from the machine with the browser; clients do not build sessions themselves.

`{site}` is `x`, `bluesky`, `dev-null`, `linkedin` or `threads`; another answers `404`.

## Write a post — `POST /api/v1/posts`

```json
{
  "requestId": "00000000-0000-4000-8000-000000000001",
  "text": "A short post",
  "platforms": ["x", "bluesky", "dev-null"],
  "images": [{"mimeType": "image/jpeg", "data": "BASE64_IMAGE_BYTES", "alt": "A useful description"}],
  "mode": "now",
  "scheduledAt": null
}
```

- `requestId`: lowercase UUID, optional (one is made when left out). Reuse it when recovering from a lost response.
- `text`: UTF-8, at most 3,000 characters; may be empty when images are attached.
- `platforms`: one to five distinct IDs of `x`, `bluesky`, `dev-null`, `linkedin`, `threads`.
- `images`: at most four, three with dev-null.sh. Each JPEG, PNG or WebP is at most 1 MiB and 40 megapixels, and its declared type must match its bytes. Alt text is optional, at most 1,000 characters.
- `mode`: `now` publishes, `draft` saves, `schedule` saves for `scheduledAt` (an ISO 8601 time in the future). Defaults to `schedule` when `scheduledAt` is given, otherwise `now`.

Returns the post. A publication that partly or wholly failed is still a 200: read its results.

## Read posts

- `GET /api/v1/posts?status=failed,unknown&limit=20&before=…` lists posts newest first: `{"posts": [...], "next": "…"}`. Pass `next` as `before` for the following page; it is `null` on the last.
- `GET /api/v1/posts?from=…&to=…` lists the posts on a calendar between two times, soonest first: `{"posts": [...]}`. A post sits at its published time, else its scheduled time, else when it was last tried; drafts are left out. At most 62 days at a time.
- `GET /api/v1/posts/{id}` returns one post.
- `GET /api/v1/media/{id}` returns an attached image's bytes.

## Change a post

These work on drafts and scheduled posts only; a sent post's history cannot change (409).

- `PUT /api/v1/posts/{id}` replaces the content, in the same shape as creating it. `mode` decides what happens next.
- `POST /api/v1/posts/{id}/schedule` with `{"scheduledAt": "…"}` schedules or moves it, and with `null` returns it to the drafts.
- `POST /api/v1/posts/{id}/publish` hands it to the sites' queues and returns it as `publishing`. On a post already on its way or sent, it returns the post unchanged.
- `DELETE /api/v1/posts/{id}` deletes it (204).

## Retry — `POST /api/v1/posts/{id}/retry`

Sends again to the destinations that `failed`, with the stored text and images, under the same post. Like publishing, it answers at once with the post `publishing` and those results `queued`; poll for the outcome. Unknown destinations are left alone. 422 when nothing failed or the post is still publishing.

## Errors

`{"error": {"code": "…", "message": "…"}}`

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | | Missing or invalid access token, when one is set |
| 404 | `not_found` | Unknown post, image or route |
| 409 | `conflict` | The request ID belongs to other content, or the post was already sent (also when it was sent while you edited it) |
| 413 | | Body larger than the server's 7 MiB limit |
| 415 | | A `POST` or `PUT` without JSON |
| 422 | `invalid` | Invalid input; the message says what to fix |

Account checks and reads never create posts. Request bodies, image bytes, cookies and tokens are never logged.
