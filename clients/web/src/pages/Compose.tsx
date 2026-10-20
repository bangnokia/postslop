import { useEffect, useRef, useState } from "react";
import { api, ApiError, PLATFORM_NAMES, PLATFORMS, type NewImage, type Platform, type Post, type PostInput } from "../api";
import { blankDraft, length, loadDraft, prepareImage, saveDraft, toIso, toLocalInput, when, type LocalDraft } from "../compose";
import { go, PlatformIcon, Results, type AccountState } from "../ui";

type Mode = "now" | "schedule";

async function toNewImage(id: string, alt: string): Promise<NewImage> {
  const blob = await api.media(id);
  const data = await new Promise<string>((resolve) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(",")[1]);
    reader.readAsDataURL(blob);
  });
  return { data, mimeType: blob.type, alt };
}

export function Compose({ editing, accounts }: { editing?: string; accounts: AccountState }) {
  const [draft, setDraft] = useState<LocalDraft>();
  const [mode, setMode] = useState<Mode>("now");
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(0);
  const [notice, setNotice] = useState<{ text: string; error?: boolean; link?: string }>();
  const [sent, setSent] = useState<Post>();
  const [dragging, setDragging] = useState(false);
  const files = useRef<HTMLInputElement>(null);
  const text = useRef<HTMLTextAreaElement>(null);
  const available = accounts.accounts?.platforms ?? [];

  // Open the saved local draft, or a saved post when editing one.
  useEffect(() => {
    let live = true;
    (async () => {
      if (editing) {
        const post = await api.post(editing);
        if (!["draft", "scheduled"].includes(post.status)) throw new Error("This post was already sent; it can no longer be edited.");
        const images = await Promise.all(post.images.map((image) => toNewImage(image.id, image.alt)));
        if (!live) return;
        setDraft({ requestId: post.id, postId: post.id, text: post.text, platforms: post.platforms, images, scheduledAt: post.scheduledAt ? toLocalInput(post.scheduledAt) : "" });
        setMode(post.status === "scheduled" ? "schedule" : "now");
        setNotice({ text: `Editing a saved ${post.status === "scheduled" ? "scheduled post" : "draft"}.` });
        return;
      }
      const saved = await loadDraft().catch(() => undefined);
      if (!live) return;
      setDraft(saved ?? blankDraft([...PLATFORMS]));
      if (saved?.text || saved?.images.length) setNotice({ text: "Draft restored, including images." });
      if (saved?.scheduledAt) setMode("schedule");
    })().catch((error: Error) => {
      if (!live) return;
      setDraft(blankDraft([...PLATFORMS]));
      setNotice({ text: error.message, error: true });
    });
    return () => {
      live = false;
    };
  }, [editing]);

  function edit(change: Partial<LocalDraft>) {
    if (!draft) return;
    // Any change makes a new post: a request ID is reused only to repeat the same one.
    const next = { ...draft, ...change, requestId: draft.postId ?? (sent ? crypto.randomUUID() : draft.requestId) };
    setSent(undefined);
    setDraft(next);
    if (!next.postId) saveDraft(next).catch(() => setNotice({ text: "Draft storage is unavailable. Keep this tab open.", error: true }));
  }

  async function addImages(list: File[]) {
    if (!draft || !list.length) return;
    const limit = draft.platforms.includes("dev-null") ? 3 : 4;
    if (draft.images.length + list.length > limit) {
      setNotice({ text: `These destinations allow ${limit} images.`, error: true });
      return;
    }
    setPreparing((count) => count + 1);
    try {
      const prepared: NewImage[] = [];
      for (const file of list) prepared.push(await prepareImage(file));
      setDraft((current) => {
        if (!current) return current;
        const next = { ...current, images: [...current.images, ...prepared] };
        if (!next.postId) saveDraft(next).catch(() => {});
        return next;
      });
      setSent(undefined);
    } catch (error) {
      setNotice({ text: (error as Error).message, error: true });
    } finally {
      setPreparing((count) => count - 1);
    }
  }

  async function pasteImages() {
    try {
      const items = await navigator.clipboard.read();
      const images = await Promise.all(
        items.flatMap((item) => item.types.filter((type) => type.startsWith("image/")).slice(0, 1).map(async (type) => new File([await item.getType(type)], "pasted", { type }))),
      );
      if (images.length) addImages(images);
      else setNotice({ text: "The clipboard has no image. Copy one, then paste.", error: true });
    } catch {
      setNotice({ text: "Couldn't read the clipboard. Press ⌘V instead.", error: true });
    }
  }

  useEffect(() => {
    const paste = (event: ClipboardEvent) => {
      const images = [...(event.clipboardData?.items ?? [])].filter((item) => item.kind === "file" && item.type.startsWith("image/")).map((item) => item.getAsFile()).filter((file): file is File => file !== null);
      if (images.length) {
        event.preventDefault();
        addImages(images);
      }
    };
    document.addEventListener("paste", paste);
    return () => document.removeEventListener("paste", paste);
  });

  if (!draft) return <p className="loading">Opening your draft…</p>;

  const counts = draft.platforms.map((platform) => {
    const limit = available.find((account) => account.id === platform)?.textLimit ?? 280;
    return { platform, used: length(draft.text, platform), limit };
  });
  const tooLong = counts.filter((count) => count.used > count.limit);
  const unavailable = draft.platforms.filter((platform) => !available.find((account) => account.id === platform)?.available);
  const maxImages = draft.platforms.includes("dev-null") ? 3 : 4;
  const empty = !draft.text.trim() && draft.images.length === 0;
  const scheduleTime = draft.scheduledAt ? new Date(draft.scheduledAt) : undefined;
  const blocked = busy || preparing > 0 || empty || draft.platforms.length === 0 || tooLong.length > 0 || draft.images.length > maxImages;
  // After an attempt, its result card offers the safe next step; editing starts a new post.
  const canSubmit = !blocked && !sent && (mode === "schedule" ? Boolean(scheduleTime && scheduleTime.getTime() > Date.now()) : unavailable.length === 0 && Boolean(accounts.accounts));
  const failed = sent?.results.some((result) => result.status === "failed");
  const uncertain = sent?.status === "unknown";

  async function finish(post: Post) {
    if (post.status === "published" || post.status === "draft" || post.status === "scheduled") {
      await saveDraft(null).catch(() => {});
      setDraft(blankDraft(draft!.platforms));
      setMode("now");
      setSent(post.status === "published" ? post : undefined);
      setNotice(
        post.status === "published"
          ? { text: "Published. Ready for your next post." }
          : post.status === "scheduled"
            ? { text: `Scheduled for ${when(post.scheduledAt!)}.`, link: `#/posts/${post.id}` }
            : { text: "Saved as a draft in Postslop.", link: `#/posts/${post.id}` },
      );
      if (editing) go("/");
      return;
    }
    // Failed or uncertain: keep everything so nothing is lost.
    setSent(post);
    setNotice({ text: post.status === "unknown" ? "The outcome is unknown. Check the account before sending again." : "Some destinations need attention. Your draft is kept.", error: true });
  }

  async function submit(as: PostInput["mode"]) {
    if (!draft || busy) return;
    setBusy(true);
    setNotice({ text: as === "now" ? "Publishing to your accounts…" : "Saving…" });
    const input: PostInput = {
      requestId: draft.requestId,
      text: draft.text,
      platforms: draft.platforms,
      images: draft.images,
      mode: as,
      ...(as === "schedule" ? { scheduledAt: toIso(draft.scheduledAt) } : {}),
    };
    try {
      await finish(draft.postId ? await api.update(draft.postId, input) : await api.create(input));
    } catch (error) {
      const busyNow = error instanceof ApiError && error.code === "busy";
      setNotice({ text: `${(error as Error).message}${busyNow ? "" : " Your draft is kept."}`, error: true });
    } finally {
      setBusy(false);
    }
  }

  async function followUp(action: "retry" | "check") {
    if (!sent) return;
    setBusy(true);
    try {
      await finish(action === "retry" ? await api.retry(sent.id) : await api.post(sent.id));
    } catch (error) {
      setNotice({ text: (error as Error).message, error: true });
    } finally {
      setBusy(false);
    }
  }

  function clear() {
    saveDraft(null).catch(() => {});
    setDraft(blankDraft(draft!.platforms));
    setSent(undefined);
    setMode("now");
    setNotice({ text: "Ready for your next post." });
    if (editing) go("/");
    text.current?.focus();
  }

  const primary = mode === "schedule" ? "Schedule" : "Publish";

  return (
    <>
      <section
        className={`composer${dragging ? " dragging" : ""}`}
        aria-label="Compose a post"
        onDragOver={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          addImages([...event.dataTransfer.files].filter((file) => file.type.startsWith("image/")));
        }}
      >
        <div className="writing-pane">
          <div className="pane-heading">
            <label className="section-label" htmlFor="post-text">
              <span>01</span> The words
            </label>
            <span className="draft-tag">{draft.postId ? "EDITING" : "DRAFT"}</span>
          </div>
          <textarea
            id="post-text"
            ref={text}
            autoFocus
            value={draft.text}
            placeholder="What’s on your mind?"
            disabled={busy}
            onChange={(event) => edit({ text: event.target.value })}
            onKeyDown={(event) => {
              if (event.key === "Enter" && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                if (canSubmit) submit(mode === "schedule" ? "schedule" : "now");
              }
            }}
          />
          {draft.images.length > 0 && (
            <div className="attachments" aria-label="Attached images">
              {draft.images.map((image, index) => (
                <figure key={index}>
                  <img src={`data:${image.mimeType};base64,${image.data}`} alt={image.alt || `Attachment ${index + 1}`} />
                  <button type="button" aria-label={`Remove image ${index + 1}`} disabled={busy} onClick={() => edit({ images: draft.images.filter((_, other) => other !== index) })}>
                    ×
                  </button>
                  <input
                    placeholder="Describe the image"
                    aria-label={`Description for image ${index + 1}`}
                    maxLength={1000}
                    value={image.alt}
                    disabled={busy}
                    onChange={(event) => edit({ images: draft.images.map((other, i) => (i === index ? { ...other, alt: event.target.value } : other)) })}
                  />
                </figure>
              ))}
            </div>
          )}
          <div className="attachment-actions">
            <button type="button" disabled={busy || preparing > 0 || draft.images.length >= maxImages} onClick={() => files.current?.click()}>
              ＋ Add images
            </button>
            <button type="button" disabled={busy || preparing > 0 || draft.images.length >= maxImages} onClick={pasteImages} title="Or press ⌘V anywhere on the page">
              Paste image
            </button>
            <input
              ref={files}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              multiple
              hidden
              onChange={(event) => {
                addImages([...(event.target.files ?? [])]);
                event.target.value = "";
              }}
            />
            <span>{preparing ? "Preparing images…" : `Paste, drop or pick · up to ${maxImages}`}</span>
          </div>
          <div className="writing-footer">
            <p className="draft-status" role="status" aria-live="polite" data-error={notice?.error ?? false}>
              {notice?.text ?? "Ready when you are."} {notice?.link && <a href={notice.link}>Open it</a>}
            </p>
            <div className="counters">
              {counts.map((count) => (
                <span key={count.platform} data-over={count.used > count.limit} title={`${PLATFORM_NAMES[count.platform]}: ${count.used} of ${count.limit}`}>
                  {PLATFORM_NAMES[count.platform]} {count.limit - count.used}
                </span>
              ))}
            </div>
          </div>
        </div>

        <aside className="destinations-pane" aria-labelledby="destinations-title">
          <div className="pane-heading">
            <h2 id="destinations-title" className="section-label">
              <span>02</span> The places
            </h2>
          </div>
          <fieldset className="destinations">
            <legend className="visually-hidden">Choose destinations</legend>
            {PLATFORMS.map((platform) => {
              const account = available.find((item) => item.id === platform);
              return (
                <label className="destination" key={platform} title={account?.reason ?? ""}>
                  <input
                    type="checkbox"
                    checked={draft.platforms.includes(platform)}
                    disabled={busy}
                    onChange={(event) => edit({ platforms: event.target.checked ? PLATFORMS.filter((item) => item === platform || draft.platforms.includes(item)) : draft.platforms.filter((item) => item !== platform) })}
                  />
                  <PlatformIcon platform={platform} />
                  <span className="network-name">
                    {PLATFORM_NAMES[platform]}
                    <span data-error={account ? !account.available : false}>
                      {accounts.checking && !account ? "Checking account…" : account?.available ? `@${account.handle}` : account ? "Session unavailable" : (accounts.error ?? "Could not check")}
                    </span>
                  </span>
                  <span className="checkbox-mark" aria-hidden="true" />
                </label>
              );
            })}
          </fieldset>
          <div className="schedule">
            <div className="segmented" role="radiogroup" aria-label="When to publish">
              <button type="button" role="radio" aria-checked={mode === "now"} onClick={() => setMode("now")}>
                Now
              </button>
              <button type="button" role="radio" aria-checked={mode === "schedule"} onClick={() => setMode("schedule")}>
                Later
              </button>
            </div>
            {mode === "schedule" && (
              <input
                type="datetime-local"
                aria-label="Publish at"
                value={draft.scheduledAt}
                min={toLocalInput(new Date().toISOString())}
                onChange={(event) => edit({ scheduledAt: event.target.value })}
              />
            )}
            {mode === "schedule" && <p className="hint">Runs from the scheduler: <code>postslop scheduler install</code></p>}
          </div>
          {unavailable.length > 0 && mode === "now" && (
            <p className="connection-note">
              <span className="note-symbol" aria-hidden="true">↗</span> {unavailable.map((platform) => PLATFORM_NAMES[platform as Platform]).join(", ")} not ready. <a href="#/accounts">Check accounts</a>
            </p>
          )}
        </aside>

        <div className="composer-actions">
          <div className="secondary-actions">
            <button className="clear-button" type="button" disabled={busy || (empty && !draft.postId)} onClick={clear}>
              {draft.postId ? "Stop editing" : "Clear"}
            </button>
            <button className="clear-button" type="button" disabled={blocked} onClick={() => submit("draft")}>
              Save draft
            </button>
          </div>
          <div className="publish-actions">
            <span className="publish-hint">⌘Enter to {mode === "schedule" ? "schedule" : "publish"}</span>
            <button className="publish-button" type="button" disabled={!canSubmit} data-ready={canSubmit} onClick={() => submit(mode === "schedule" ? "schedule" : "now")}>
              {busy ? "Working…" : primary} <span aria-hidden="true">↗</span>
            </button>
          </div>
        </div>
      </section>

      {sent && (
        <section className="outcome" aria-live="polite">
          <Results results={sent.results} />
          <div className="outcome-actions">
            {failed && (
              <button type="button" className="clear-button" disabled={busy} onClick={() => followUp("retry")}>
                Retry failed destinations
              </button>
            )}
            {uncertain && (
              <button type="button" className="clear-button" disabled={busy} onClick={() => followUp("check")}>
                Check result
              </button>
            )}
            <a className="clear-button" href={`#/posts/${sent.id}`}>
              Open in Posts
            </a>
          </div>
        </section>
      )}
    </>
  );
}
