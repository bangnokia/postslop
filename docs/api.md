# Local API

Base URL: `http://127.0.0.1:8087`. Every `/api/` request requires `Authorization: Bearer <contents of storage/client-token>`. This token stays local and must not be included in examples, logs, or shared files. Mutations require `Content-Type: application/json`. Responses are not cached.

## Accounts — `GET /api/status`

Read-only authenticated sign-in checks (cached for 30 seconds). Bluesky may refresh an expired access token using the saved refresh token. Returns:

X is checked through Postslop's private background browser worker. It opens no visible window and returns unavailable if an account check is required. The HTTP contract and request-ID rules are shared by both transports.

```json
{
  "platforms": [
    {"id":"x","label":"X","available":true,"handle":"example","maxImages":4},
    {"id":"bluesky","label":"Bluesky","available":false,"handle":"example.test","reason":"Import this session again.","maxImages":4},
    {"id":"dev-null","label":"dev-null.sh","available":true,"handle":"example","maxImages":3}
  ],
  "limits":{"maxImages":4,"maxImageBytes":1048576},
  "publishingEnabled":true
}
```

Handles have no leading `@`. A platform can be unavailable even when local session data exists. Clients should surface `reason` and allow selecting other destinations.

## Publish — `POST /api/posts`

```json
{
  "requestId":"00000000-0000-4000-8000-000000000001",
  "text":"A short post",
  "platforms":["x","bluesky","dev-null"],
  "images":[{"filename":"image.png","mimeType":"image/png","data":"BASE64_IMAGE_BYTES","alt":"A useful description"}]
}
```

- `requestId`: lowercase UUID; reuse the same ID when recovering from a lost response.
- `text`: required UTF-8 string, at most 3,000 code points; may be empty when images are attached. Platform-specific limits can be lower.
- `platforms`: one to three distinct supported IDs.
- `images`: optional list, maximum four (three when dev-null.sh is selected). Each decoded JPEG/PNG/WebP must be at most 1 MiB and 40 megapixels. The declared MIME type must match the bytes. The server chooses an upload filename. Alt text is optional, at most 1,000 characters.

A 200 response contains per-destination results, including partial or failed outcomes:

```json
{
  "requestId":"00000000-0000-4000-8000-000000000001",
  "status":"partial",
  "results":[
    {"platform":"x","status":"published","url":"https://x.com/example/status/123"},
    {"platform":"bluesky","status":"failed","error":"Refresh this session and try again."},
    {"platform":"dev-null","status":"unknown","url":"https://dev-null.sh/@example/commits/00000000-0000-4000-8000-000000000002","pendingReview":true,"error":"Saved; awaiting moderation. Do not post again."}
  ]
}
```

Overall status is `published`, `partial`, `failed`, or `unknown`. Per-platform status is `published`, `failed`, or `unknown`. A URL identifies an existing platform post; it is not an instruction to resend it. `pendingReview` identifies a saved dev-null.sh post awaiting moderation.

Existing IDs are never submitted again, including failures. Explicitly retrying definitive failures requires a new ID with only the failed destinations. Unknown outcomes require inspection; never silently generate a new ID for them. The server writes the receipt before contacting each platform. A process crash leaves an uncertain destination recorded and unattempted destinations marked separately.

## Recover — `GET /api/posts/{requestId}`

Returns the saved result in the same shape, or 404 when no attempt is recorded. Saved dev-null.sh posts awaiting review can be checked again without resubmitting their content. A held publishing lock leaves the current receipt unchanged.

## Errors

Errors use `{"error":{"message":"..."}}` (additional framework metadata may be present):

| Status | Meaning |
| --- | --- |
| 401 | Missing or invalid local bearer token |
| 403 | Invalid local host or cross-site origin |
| 404 | Unknown request ID or route |
| 409 | Another publication is active, or this request ID belongs to different content |
| 413 | Request exceeds the server's 7 MiB body limit |
| 415 | Mutation did not use JSON |
| 422 | Invalid post/image input |

Neither status checks nor receipt recovery create posts. Request bodies, image bytes, cookies, and tokens must not be logged.
