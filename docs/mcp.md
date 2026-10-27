# Postslop MCP

Agents can use the running Postslop server to save drafts, schedule posts, publish explicitly, and read delivery results. MCP uses the same core, SQLite database and per-site queues as the web client. No extra service or client package is needed.

## Connect

Configure your MCP client's remote server connection:

| Setting | Value |
| --- | --- |
| Transport | Streamable HTTP |
| URL | `https://YOUR_SERVER/api/v1/mcp` |
| Authorization header | `Bearer <your Postslop access token>` |

The hosted instance uses `https://postslop.daudau.cc/api/v1/mcp`. The token is the same password used by the web client (`POSTSLOP_TOKEN` on the server). Store it in the MCP client's secret or environment settings, not in a committed configuration file or a prompt. The exact configuration format depends on the client. It must support a remote HTTP URL and a bearer header; OAuth-only clients are not supported yet.

Start the server with `composer start`. Set `APP_URL` to its public URL, such as `https://postslop.daudau.cc`. Requests with an `Origin` header must match that configured origin; requests without one are accepted. Authentication still applies. As with the REST API, leaving `POSTSLOP_TOKEN` empty makes the endpoint public.

## Tools

| Tool | Purpose |
| --- | --- |
| `postslop_accounts` | Account handles, availability, limits and failure details |
| `postslop_check_account` | Refresh one account's sign-in status |
| `postslop_list_posts` | Saved posts, filtered by statuses, with pagination |
| `postslop_get_post` | A post and each destination's result |
| `postslop_create_draft` | Save text, destinations and optional images; never publishes |
| `postslop_update_draft` | Replace editable content and return it to draft, clearing its schedule |
| `postslop_schedule_post` | Schedule/reschedule a post, or pass `scheduledAt: null` to unschedule |
| `postslop_publish_post` | Queue an existing post for immediate publishing |

An agent should check accounts, create a draft, then schedule or publish **only when the user asks**. The schedule and publish tools perform those actions immediately; tool descriptions guide agents but do not replace the client's tool approval controls.

Example arguments for `postslop_create_draft`:

```json
{
  "requestId": "7fba1ba6-85b4-4820-b03b-7af9a858087c",
  "text": "A post prepared for review.",
  "platforms": ["bluesky", "threads"]
}
```

Generate one lowercase UUID for each new post and reuse it after a lost response. Repeating the same ID and content returns the recorded post. Different content under an existing ID is refused. Use the returned `id` for subsequent tools.

For `postslop_schedule_post`, resolve the user's timezone and include it explicitly:

```json
{
  "id": "7fba1ba6-85b4-4820-b03b-7af9a858087c",
  "scheduledAt": "2027-01-02T09:00:00+07:00"
}
```

Scheduling requires the server's scheduler and queue workers to remain running. `composer start` runs them. Times without a timezone, relative dates and invalid calendar dates are refused.

Publishing answers immediately with queued deliveries. Poll `postslop_get_post` every few seconds until the post is no longer `publishing`, then report each delivery's URL or error. Never retry an `unknown` result or create a replacement: it may already be live. MCP does not expose retries, deletion, browser session imports, or account credentials.

Images use `images: [{"data": "<base64 bytes>", "mimeType": "image/png", "alt": "Description"}]`; JPEG, PNG and WebP are accepted, up to 1 MiB each and the destinations' image limit. Local paths and remote image URLs are not accepted. Updating a draft requires the complete text, platforms and images; `images: []` removes attachments. Read the existing post first. Updating a scheduled post clears its schedule.

## Protocol

The endpoint supports MCP versions `2025-11-25`, `2025-06-18` and `2025-03-26`, with `initialize`, `ping`, `tools/list` and `tools/call`. It is stateless, sends JSON responses, and does not issue session IDs. Notifications receive an empty HTTP 202 response. GET and DELETE return 405: there is no SSE stream or session to terminate.

Tool results include JSON text in `content` and, for the newer versions, the same object in `structuredContent`. Validation and core refusals return `isError: true` with an error code and message. Protocol errors use JSON-RPC errors. No extra runtime dependencies are needed.
