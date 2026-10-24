# Postslop for AI agents

Postslop posts one piece of writing to X, Bluesky, dev-null.sh, LinkedIn and Threads, with the user's own signed-in accounts. Threads support is experimental; see [its setup notes](threads.md). This page tells an AI agent how to use it safely. Hand it to the agent as is.

## Ground rules

- **Never publish unless the user asked you to publish.** A post is a draft unless you pass `--now` or a time. Writing drafts is always safe.
- **Never post the same thing twice.** Give every post a `--request-id` (a lowercase UUID you generate once) and reuse it if you run the command again. Never retry an `unknown` result: the post may already be live. Ask the user to check the account.
- **Publish only through Postslop.** Do not open a browser, a site's composer or a site's API yourself, and never read or print `server/storage/sessions.json`, cookies or the access token.
- **Use `--json`** on every command. Each prints exactly one JSON document, errors included, and exits with a stable code.

## Two ways in

| Where the agent runs | Use |
| --- | --- |
| On the machine with Postslop | The command line, `postslop` (or `server/bin/postslop`). It needs no server. |
| Anywhere else | The HTTP API of a running `postslop serve`, with `Authorization: Bearer <token>`. See [api.md](api.md). |

The two follow the same rules. The rest of this page uses the command line; the API section at the end maps each step.

## Workflow

### 1. See which accounts can be used

```sh
postslop accounts --json
```

Use only platforms with `"available": true`. For the others, tell the user the `reason` (for example "Not connected. Run: postslop setup threads"); do not try to fix sessions yourself. Each platform gives `textLimit` (X 280, Bluesky 300, dev-null.sh 4000, LinkedIn 3000, Threads 500) and `maxImages` (4, or 3 for dev-null.sh). Write to the smallest limit among the destinations, or make one post per site.

### 2. Check the post without saving it

```sh
postslop post "Text" --to=x,bluesky --dry-run --json
```

Answers `{"ok": true, ...}` or `{"ok": false, "problems": [...]}` with exit code 2. Fix every problem before going on.

### 3. Save, schedule or publish

```sh
ID=$(uuidgen | tr A-Z a-z)
postslop post "Text" --to=x,bluesky --request-id=$ID --json                       # draft (default)
postslop post "Text" --to=x,bluesky --request-id=$ID --at="tomorrow 9am" --json   # scheduled, local time
postslop post "Text" --to=x,bluesky --request-id=$ID --now --json                 # publish now, waits for the sites
```

Leaving out `--to` sends to every account set up and turned on; name the sites explicitly.

For text with quotes or newlines, and for images, send the whole post as JSON on standard input:

```sh
postslop post --input=- --request-id=$ID --json <<'JSON'
{
  "text": "Line one\n\nLine two",
  "platforms": ["bluesky", "linkedin"],
  "images": [{"path": "/abs/path/shot.png", "alt": "What the image shows"}],
  "scheduledAt": null
}
JSON
```

Images are JPEG, PNG or WebP, at most 1 MiB each. Always write alt text.

### 4. Read the result

The post comes back as JSON:

```json
{
  "id": "…",
  "status": "partial",
  "platforms": ["x", "bluesky"],
  "results": [
    {"platform": "x", "status": "published", "url": "https://x.com/…/status/…"},
    {"platform": "bluesky", "status": "failed", "error": "Refresh this session and try again."}
  ]
}
```

Report each site's outcome to the user, with its `url` or its `error`.

| Exit | Post status | What to do |
| --- | --- | --- |
| 0 | `draft`, `scheduled` or `published` | Done. |
| 3 | `partial` | Some sites published. Report which; offer to retry the failed ones. |
| 4 | `failed` | Nothing published. Fix the cause in `error`, then `postslop retry ID`. |
| 5 | `unknown` | A site may have the post. Do **not** retry; ask the user to check that account. |
| 5 | `publishing` | Still being sent by the server's queue. Run `postslop show ID --json` again in a few seconds. |
| 2 | (error) | Invalid input; `error.message` says what to fix. |
| 7 | (error) | The request ID belongs to other content, or the post was already sent. |
| 8 | (error) | No post has this ID. |

### 5. Manage posts

```sh
postslop posts --status=draft,scheduled --json     # list, newest first (--limit, --from/--to)
postslop show ID --json
postslop publish ID --json                         # publish a draft or scheduled post now
postslop schedule ID --at="+2 hours" --json        # or --clear to return it to the drafts
postslop retry ID --json                           # resend only the sites that failed
postslop delete ID --json                          # drafts and scheduled posts only
```

A sent post's history never changes: it cannot be edited or deleted.

## Through the HTTP API

Base URL and token come from the user (`POSTSLOP_TOKEN` in the server's `.env`). Send JSON with `Authorization: Bearer $TOKEN`.

| Step | Request |
| --- | --- |
| Accounts | `GET /api/v1/accounts` (`?refresh=1` checks again) |
| Draft | `POST /api/v1/posts` with `{"requestId", "text", "platforms", "mode": "draft"}` |
| Schedule | the same with `"scheduledAt": "2026-10-22T02:00:00Z"` |
| Publish now | the same with `"mode": "now"` |
| Images | `"images": [{"mimeType": "image/png", "data": "<base64>", "alt": "…"}]` |
| Read | `GET /api/v1/posts/{id}`, `GET /api/v1/posts?status=draft` |
| Publish, retry, schedule, delete | `POST …/{id}/publish`, `POST …/{id}/retry`, `POST …/{id}/schedule`, `DELETE …/{id}` |

The API never waits on the sites: publishing answers at once with status `publishing`. Poll `GET /api/v1/posts/{id}` every few seconds until the status is no longer `publishing`, then read `results` as above. Errors are `{"error": {"code", "message"}}` with HTTP 404, 409 or 422. The full reference is [api.md](api.md).
