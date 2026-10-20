# Postslop for Raycast

Search **Postslop** in Raycast to open a fresh **New Post** composer without opening a separate app. Previous drafts and results are preserved in **Saved Posts**. The extension calls the existing local Postslop API; the web composer remains available at <http://127.0.0.1:8087>.

## Install locally

```sh
npm install
npm run dev
```

This compiles a small background image helper and installs the extension in Raycast. The **Postslop Project Folder** preference points to the project containing `cua` and `storage/client-token`. If needed, the extension starts the local PHP server in the background and waits for readiness. No macOS composer window or visible publishing browser is launched.

## Compose

- Write in the native Raycast multiline textarea. Ordinary text paste uses **⌘V**.
- Use **Paste Image** (**⌘⇧V**) to attach a screenshot, copied image pixels, or a copied image file. You can also choose files using the Images field.
- Attached images appear in the Images list immediately below the textarea. Remove an attachment with its **×** button; edit its optional description below. Image previews are omitted to keep the native form simple.
- Choose destinations; your selection persists.
- Use **Publish** (**⌘Enter**) to save and hand off the post immediately, without another confirmation. Raycast closes while a separate background command finishes publishing. The textarea uses Raycast's standard newline behavior.
- **New Post** (**⌘N**) opens an empty composer and keeps the previous draft or result in **Saved Posts**. This also works when a previous post is awaiting moderation.
- **Escape** leaves the command and keeps the draft. The next launch starts a new post; **Saved Posts** restores anything you were working on.

Raycast's supported form controls determine the textarea size and keyboard behavior. Clipboard images use **⌘⇧V**; ordinary text paste remains **⌘V**. The retained web composer includes image thumbnails.

The extension supports four images, or three when dev-null.sh is selected. The background helper resizes images to JPEG locally, each no larger than 1 MiB. Transparent backgrounds become white. It reads the pasteboard only when Paste Image is invoked and does not monitor clipboard history.

## Results and recovery

Publishing runs in a separate no-view command after the composer closes. It is launched as a user-initiated command because Raycast disallows feedback APIs for background launches. A brief “Posting…” HUD acknowledges submission and closes the composer immediately. A second HUD reports success or any destinations needing attention; Raycast dismisses these automatically. You do not need to keep the composer open. Raycast itself must remain running.

After full success, the active composer clears automatically. Failed or uncertain outcomes remain recoverable in **Saved Posts**. Earlier drafts and results never block the default New Post form. A saved task and request ID also survive an interrupted background command; recovery never automatically resubmits a post. While a task is running, reopening the composer still starts a new post. The background result updates the saved post without overwriting your newer draft. After five minutes, an interrupted task becomes available for explicit recovery.

Drafts and request IDs survive closing the command. **Retry Safely** uses the same ID after a lost response. **Check Result** retrieves the existing receipt without publishing again. **Retry Failed** creates a new request for only definitively failed destinations. Submitted content stays read-only until its outcome is known. **Edit as New Post** then makes an editable copy and warns when the previous version was already published somewhere. Publishing is never retried automatically.

Existing drafts from the former macOS composer load from the same private `native-draft.json` file, including attachments and request IDs. The earlier Raycast Form draft is imported only if that file is absent.

## Local data and hosting

Social sessions stay in Postslop's `storage/sessions/`; the extension reads only the local client token. Draft JSON and image copies live in Raycast's extension support directory with restricted permissions. Successfully published draft images and removed attachments are deleted unless a Saved Post still references them; original source images are preserved. HTTP requests go to `127.0.0.1:8087` and redirects are rejected.

The web UI and Raycast share the same API contract. This extension currently connects locally. Remote hosting still requires server-side HTTPS, authentication and session setup, plus a configurable client connection; it is not enabled by this change.

## Development

```sh
npm run typecheck
npm run lint
npm run build
```

The manifest's `author` is a local placeholder. Store publication would need the owner's Raycast username; local installation does not require it.

For an isolated API integration check, start the existing [Postman publishing simulation and test backend](../postman/README.md), then run from this directory:

```sh
node scripts/verify-client.mjs
node scripts/verify-background.mjs
```

This runs the actual TypeScript API client against the isolated backend and upstream simulation. It verifies publishing with an image, duplicate suppression, receipt recovery, partial failure and failed-only retries, private draft persistence, and background success/failure/recovery with duplicate-worker suppression. It refuses accounts other than the fixture identities and never uses the production token or social sessions.
