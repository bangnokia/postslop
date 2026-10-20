# HTTP API

The web client talks to the core through this API. The command line (`bin/postslop`) runs the same core directly; both follow the same rules.

Base URL: `http://127.0.0.1:8087`. Every `/api/` request requires `Authorization: Bearer <contents of server/storage/client-token>`. The token stays local and must not appear in examples, logs or shared files. Requests must come from the local host; `POST` and `PUT` bodies must be `Content-Type: application/json`. Responses are not cached.

## Posts and their lifecycle

```
draft ──▶ scheduled ──▶ publishing ──▶ published | partial | failed | unknown
  └──────────────────────▲   (publish now)
```

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
- Before contacting each site, the core records that destination as `unknown`. A crash leaves the uncertain destination recorded, and destinations it never reached marked as failed.
- `unknown` is never retried, automatically or by `/retry`: the post may already be live. Check the account first.
- One post publishes at a time. Another attempt meanwhile gets 409 `busy`, and its post is kept as a draft.

## Accounts — `GET /api/v1/accounts`

Read-only sign-in checks, cached for 30 seconds; `?refresh=1` checks again. Bluesky may refresh an expired access token with its saved refresh token. X is checked through the private background browser, which opens no window.

```json
{
  "platforms": [
    {"id": "x", "label": "X", "available": true, "handle": "example", "maxImages": 4, "textLimit": 280},
    {"id": "bluesky", "label": "Bluesky", "available": false, "handle": "example.test", "reason": "Import this session again.", "maxImages": 4, "textLimit": 300},
    {"id": "dev-null", "label": "dev-null.sh", "available": true, "handle": "example", "maxImages": 3, "textLimit": 4000}
  ],
  "limits": {"maxImages": 4, "maxImageBytes": 1048576},
  "publishingEnabled": true
}
```

Handles have no leading `@`. A platform can be unavailable even when session data exists; show `reason` and let the user pick other destinations. `textLimit` is for counters; each publisher enforces its site's exact rule.

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
- `platforms`: one to three distinct IDs of `x`, `bluesky`, `dev-null`.
- `images`: at most four, three with dev-null.sh. Each JPEG, PNG or WebP is at most 1 MiB and 40 megapixels, and its declared type must match its bytes. Alt text is optional, at most 1,000 characters.
- `mode`: `now` publishes, `draft` saves, `schedule` saves for `scheduledAt` (an ISO 8601 time in the future). Defaults to `schedule` when `scheduledAt` is given, otherwise `now`.

Returns the post. A publication that partly or wholly failed is still a 200: read its results.

## Read posts

- `GET /api/v1/posts?status=failed,unknown&limit=20&before=…` lists posts newest first: `{"posts": [...], "next": "…"}`. Pass `next` as `before` for the following page; it is `null` on the last.
- `GET /api/v1/posts/{id}` returns one post.
- `GET /api/v1/media/{id}` returns an attached image's bytes.

## Change a post

These work on drafts and scheduled posts only; a sent post's history cannot change (409).

- `PUT /api/v1/posts/{id}` replaces the content, in the same shape as creating it. `mode` decides what happens next.
- `POST /api/v1/posts/{id}/schedule` with `{"scheduledAt": "…"}` schedules or moves it, and with `null` returns it to the drafts.
- `POST /api/v1/posts/{id}/publish` publishes it now. On a post already sent, it returns the post unchanged.
- `DELETE /api/v1/posts/{id}` deletes it (204).

## Retry — `POST /api/v1/posts/{id}/retry`

Sends again to the destinations that `failed`, with the stored text and images, under the same post. Unknown destinations are left alone. 422 when nothing failed.

## Errors

`{"error": {"code": "…", "message": "…"}}`

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | | Missing or invalid local token |
| 403 | | Not the local host, or a cross-site request |
| 404 | `not_found` | Unknown post, image or route |
| 409 | `busy` | Another post is publishing; try again shortly |
| 409 | `conflict` | The request ID belongs to other content, or the post was already sent |
| 413 | | Body larger than the server's 7 MiB limit |
| 415 | | A `POST` or `PUT` without JSON |
| 422 | `invalid` | Invalid input; the message says what to fix |

Account checks and reads never create posts. Request bodies, image bytes, cookies and tokens are never logged.
