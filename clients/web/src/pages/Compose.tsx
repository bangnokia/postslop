import { useEffect, useRef, useState } from "react";
import { api, ApiError, inFlight, outcome, PLATFORM_NAMES, PLATFORMS, type NewImage, type NewVideo, type Platform, type Post, type PostInput } from "../api";
import { Icon } from "../components/icons";
import { base64ToBlob, blankDraft, clock, isVideoFile, length, loadDraft, prepareImage, prepareVideo, saveDraft, toIso, toLocalInput, uuid, when, type LocalDraft } from "../compose";
import { PageHeader, useShell } from "../shell";
import { PlatformIcon, postsChanged, Results, usable, type AccountState } from "../ui";

type Mode = "now" | "schedule";

const IMAGE_TYPES = "image/png,image/jpeg,image/webp";
const MEDIA_TYPES = `${IMAGE_TYPES},video/mp4,video/quicktime`;

// A saved image or video, fetched back as base64 so an edit sends it again unchanged.
async function toNewMedia(id: string, alt: string): Promise<NewImage | NewVideo> {
  const blob = await api.media(id);
  const data = await new Promise<string>((resolve) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(",")[1]);
    reader.readAsDataURL(blob);
  });
  return { data, mimeType: blob.type, alt };
}

// The attached video as an object URL, made once per video rather than on every render.
function useVideoUrl(video?: NewVideo): string | undefined {
  const [url, setUrl] = useState<string>();
  const data = video?.data;
  const mimeType = video?.mimeType;
  useEffect(() => {
    if (!data || !mimeType) {
      setUrl(undefined);
      return;
    }
    const next = URL.createObjectURL(base64ToBlob(data, mimeType));
    setUrl(next);
    return () => URL.revokeObjectURL(next);
  }, [data, mimeType]);
  return url;
}

const mebibytes = (base64: string) => `${((base64.length * 3) / 4 / 1024 / 1024).toFixed(1)} MiB`;

export function ComposePage({ editing, accounts }: { editing?: string; accounts: AccountState }) {
  return (
    <>
      <PageHeader title={editing ? "Edit post" : "New post"} />
      <div className="page-body">
        <Composer key={editing ?? "new"} editing={editing} accounts={accounts} />
      </div>
    </>
  );
}

/**
 * The composer, on its own page or in the modal. A post handed off (queued, scheduled or saved) clears it, text and
 * images included; anything that fails or is uncertain stays in Postslop to retry or check.
 */
export function Composer({ editing, at, accounts, onDone }: { editing?: string; at?: Date; accounts: AccountState; onDone?: () => void }) {
  const shell = useShell();
  const [draft, setDraft] = useState<LocalDraft>();
  const [mode, setMode] = useState<Mode>("now");
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(0);
  const [notice, setNotice] = useState<{ text: string; error?: boolean }>();
  const [sent, setSent] = useState<Post>();
  const [dragging, setDragging] = useState(false);
  // The attached video's length, read by the browser once its preview loads.
  const [videoSeconds, setVideoSeconds] = useState<number>();
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
        const images = await Promise.all(post.images.map((image) => toNewMedia(image.id, image.alt)));
        const video = post.video ? await toNewMedia(post.video.id, post.video.alt) : undefined;
        if (!live) return;
        setDraft({ requestId: post.id, postId: post.id, text: post.text, platforms: post.platforms, images, video, scheduledAt: post.scheduledAt ? toLocalInput(post.scheduledAt) : "" });
        setMode(post.status === "scheduled" ? "schedule" : "now");
        return;
      }
      const saved = await loadDraft().catch(() => undefined);
      if (!live) return;
      const next = saved ?? blankDraft([...PLATFORMS]);
      // A day picked on the calendar schedules the draft for then.
      setDraft(at ? { ...next, scheduledAt: toLocalInput(at.toISOString()) } : next);
      if (saved?.text || saved?.images.length || saved?.video) setNotice({ text: saved.video ? "Draft restored, including its video." : "Draft restored, including images." });
      if (at || saved?.scheduledAt) setMode("schedule");
    })().catch((error: Error) => {
      if (!live) return;
      setDraft(blankDraft([...PLATFORMS]));
      setNotice({ text: error.message, error: true });
    });
    return () => {
      live = false;
    };
  }, [editing]);

  // Only accounts that are set up and turned on are offered; a saved post keeps the sites it was written for.
  const offered = PLATFORMS.filter((platform) => {
    const account = available.find((item) => item.id === platform);
    return !account || usable(account) || (editing && draft?.platforms.includes(platform));
  });
  useEffect(() => {
    if (!draft || editing || !accounts.accounts) return;
    const kept = draft.platforms.filter((platform) => offered.includes(platform));
    if (kept.length !== draft.platforms.length) edit({ platforms: kept });
  }, [draft?.requestId, accounts.accounts]);

  function edit(change: Partial<LocalDraft>) {
    if (!draft) return;
    // Any change makes a new post: a request ID is reused only to repeat the same one.
    const next = { ...draft, ...change, requestId: draft.postId ?? (sent ? uuid() : draft.requestId) };
    setSent(undefined);
    setDraft(next);
    if (!next.postId) saveDraft(next).catch(() => setNotice({ text: "Draft storage is unavailable. Keep this tab open.", error: true }));
  }

  async function addImages(list: File[]) {
    if (!draft || !list.length) return;
    if (draft.video) {
      setNotice({ text: "A post takes images or one video. Remove the video to add images.", error: true });
      return;
    }
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

  // Picked or dropped files: images, or one video on its own.
  async function addFiles(list: File[]) {
    if (!draft || !list.length) return;
    if (!list.some(isVideoFile)) return addImages(list);
    if (list.length > 1) {
      setNotice({ text: "Attach one video on its own, without images.", error: true });
      return;
    }
    if (draft.images.length || draft.video) {
      setNotice({ text: draft.video ? "Remove the attached video first." : "A post takes images or one video. Remove the images to add a video.", error: true });
      return;
    }
    setPreparing((count) => count + 1);
    try {
      const video = await prepareVideo(list[0], accounts.accounts?.limits.maxVideoBytes);
      setDraft((current) => {
        if (!current) return current;
        // Like any edit, a change after an attempt makes a new post.
        const next = { ...current, video, requestId: current.postId ?? (sent ? uuid() : current.requestId) };
        if (!next.postId) saveDraft(next).catch(() => setNotice({ text: "Draft storage is unavailable. Keep this tab open.", error: true }));
        return next;
      });
      setSent(undefined);
      setNotice(undefined);
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
      setNotice({ text: "Couldn't read the clipboard here. Press ⌘V instead.", error: true });
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

  const videoUrl = useVideoUrl(draft?.video);
  useEffect(() => setVideoSeconds(undefined), [videoUrl]);

  if (!draft) return <div className="composer-skeleton skeleton" />;

  const counts = draft.platforms.map((platform) => {
    const limit = available.find((account) => account.id === platform)?.textLimit ?? 280;
    return { platform, used: length(draft.text, platform), limit };
  });
  const tooLong = counts.filter((count) => count.used > count.limit);
  const unavailable = draft.platforms.filter((platform) => !available.find((account) => account.id === platform)?.available);
  const maxImages = draft.platforms.includes("dev-null") ? 3 : 4;
  const empty = !draft.text.trim() && draft.images.length === 0 && !draft.video;
  // Sites that report taking no videos, or a shorter one than this; older servers report nothing and decide themselves.
  const videoLimit = (platform: Platform) => available.find((account) => account.id === platform)?.maxVideoSeconds;
  const takesNoVideo = draft.video ? draft.platforms.filter((platform) => videoLimit(platform) === null) : [];
  const videoTooLong = draft.video && videoSeconds !== undefined ? draft.platforms.filter((platform) => { const limit = videoLimit(platform); return typeof limit === "number" && videoSeconds > limit; }) : [];
  const scheduleTime = draft.scheduledAt ? new Date(draft.scheduledAt) : undefined;
  const blocked = busy || preparing > 0 || empty || draft.platforms.length === 0 || tooLong.length > 0 || draft.images.length > maxImages || takesNoVideo.length > 0 || videoTooLong.length > 0;
  // After an attempt, its result card offers the safe next step; editing starts a new post.
  const canSubmit = !blocked && !sent && (mode === "schedule" ? Boolean(scheduleTime && scheduleTime.getTime() > Date.now()) : unavailable.length === 0 && Boolean(accounts.accounts));
  const failed = sent?.results.some((result) => result.status === "failed");
  const uncertain = sent?.status === "unknown";

  function reset() {
    saveDraft(null).catch(() => {});
    setDraft(blankDraft(draft!.platforms));
    setMode("now");
    setSent(undefined);
    setNotice(undefined);
  }

  async function finish(post: Post) {
    postsChanged();
    const link = { href: `#/posts/${post.id}`, label: "View post" };
    if (inFlight(post)) {
      // Handed to the queue: free the composer now; the shell reports the result when it arrives.
      shell.track(post);
      shell.toast({ tone: "info", text: "Publishing in the background.", link });
    } else if (post.status === "published") {
      shell.toast({ tone: "success", text: "Published.", link });
    } else if (post.status === "scheduled") {
      shell.toast({ tone: "success", text: `Scheduled for ${when(post.scheduledAt!)}.`, link });
    } else if (post.status === "draft") {
      shell.toast({ tone: "success", text: "Saved as a draft.", link });
    } else {
      // Failed or uncertain: keep everything so nothing is lost.
      setSent(post);
      setNotice({ text: `${outcome(post)} Your draft is kept.`, error: true });
      return;
    }
    reset();
    onDone?.();
    text.current?.focus();
  }

  async function submit(as: PostInput["mode"]) {
    if (!draft || busy) return;
    setBusy(true);
    setNotice(undefined);
    const input: PostInput = {
      requestId: draft.requestId,
      text: draft.text,
      platforms: draft.platforms,
      images: draft.images,
      ...(draft.video ? { video: draft.video } : {}),
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

  const primary = mode === "schedule" ? "Schedule" : "Publish";
  const handle = (platform: Platform) => available.find((account) => account.id === platform)?.handle?.replace(/^@/, "");

  return (
    <div
      className="composer"
      data-dragging={dragging}
      onDragOver={(event) => {
        event.preventDefault();
        setDragging(true);
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(event) => {
        event.preventDefault();
        setDragging(false);
        addFiles([...event.dataTransfer.files].filter((file) => file.type.startsWith("image/") || isVideoFile(file)));
      }}
    >
      <section className="card editor" aria-label="Post">
        <label className="visually-hidden" htmlFor="post-text">
          Post text
        </label>
        <textarea
          id="post-text"
          ref={text}
          autoFocus
          value={draft.text}
          placeholder="What do you want to share?"
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
                <button type="button" className="attachment-remove" aria-label={`Remove image ${index + 1}`} disabled={busy} onClick={() => edit({ images: draft.images.filter((_, other) => other !== index) })}>
                  <Icon name="close" size={14} />
                </button>
                <input
                  className="input"
                  placeholder="Alt text"
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
        {draft.video && (
          <div className="attachments" aria-label="Attached video">
            <figure className="attachment-video">
              {videoUrl ? (
                <video src={videoUrl} muted controls playsInline preload="metadata" aria-label={draft.video.alt || "Attached video"} onLoadedMetadata={(event) => Number.isFinite(event.currentTarget.duration) && setVideoSeconds(event.currentTarget.duration)} />
              ) : (
                <span className="thumb-loading" />
              )}
              <button type="button" className="attachment-remove" aria-label="Remove video" disabled={busy} onClick={() => edit({ video: undefined })}>
                <Icon name="close" size={14} />
              </button>
              <input
                className="input"
                placeholder="Alt text"
                aria-label="Description for the video"
                maxLength={1000}
                value={draft.video.alt}
                disabled={busy}
                onChange={(event) => edit({ video: { ...draft.video!, alt: event.target.value } })}
              />
            </figure>
          </div>
        )}
        <div className="editor-toolbar">
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            disabled={busy || preparing > 0 || Boolean(draft.video) || draft.images.length >= maxImages}
            onClick={() => files.current?.click()}
            title={draft.images.length ? "Add images" : "Add images or one video (MP4 or MOV, up to 20 MiB)"}
          >
            <Icon name="image" /> Add media
          </button>
          <button type="button" className="btn btn-ghost btn-sm" disabled={busy || preparing > 0 || Boolean(draft.video) || draft.images.length >= maxImages} onClick={pasteImages} title="Or press ⌘V anywhere">
            <Icon name="clipboard" /> Paste
          </button>
          <input
            ref={files}
            type="file"
            accept={draft.images.length ? IMAGE_TYPES : MEDIA_TYPES}
            multiple
            hidden
            onChange={(event) => {
              addFiles([...(event.target.files ?? [])]);
              event.target.value = "";
            }}
          />
          <span className="muted editor-hint">
            {preparing ? "Preparing media…" : draft.video ? `1 video · ${videoSeconds === undefined ? "" : `${clock(videoSeconds)} · `}${mebibytes(draft.video.data)}` : draft.images.length ? `${draft.images.length}/${maxImages} images` : `Up to ${maxImages} images or 1 video`}
          </span>
          <div className="counters">
            {counts.map((count) => (
              <span key={count.platform} className="counter" data-over={count.used > count.limit} title={`${PLATFORM_NAMES[count.platform]}: ${count.used} of ${count.limit}`}>
                <PlatformIcon platform={count.platform} size={12} />
                {count.limit - count.used}
              </span>
            ))}
          </div>
        </div>
      </section>

      <aside className="composer-side">
        <section className="card side-section">
          <h2 className="label">Publish to</h2>
          <fieldset className="destinations">
            <legend className="visually-hidden">Choose destinations</legend>
            {offered.map((platform) => {
              const account = available.find((item) => item.id === platform);
              const noVideo = Boolean(draft.video) && account?.maxVideoSeconds === null;
              return (
                <label className="destination" key={platform} title={noVideo ? `${PLATFORM_NAMES[platform]} takes no videos from Postslop yet.` : (account?.reason ?? "")}>
                  <input
                    type="checkbox"
                    checked={draft.platforms.includes(platform)}
                    disabled={busy}
                    onChange={(event) => edit({ platforms: event.target.checked ? PLATFORMS.filter((item) => item === platform || draft.platforms.includes(item)) : draft.platforms.filter((item) => item !== platform) })}
                  />
                  <PlatformIcon platform={platform} />
                  <span className="destination-name">
                    {PLATFORM_NAMES[platform]}
                    <small data-error={account ? !account.available || noVideo : false}>
                      {accounts.checking && !account ? "Checking…" : noVideo ? "Takes no videos" : account?.available ? `@${handle(platform)}` : account && !usable(account) ? (account.connected ? "Turned off" : "Not connected") : account ? "Needs attention" : (accounts.error ?? "Could not check")}
                    </small>
                  </span>
                  <span className="checkbox" aria-hidden="true">
                    <Icon name="check" size={12} />
                  </span>
                </label>
              );
            })}
          </fieldset>
          {offered.length === 0 && (
            <p className="side-note">
              <Icon name="alert" /> No account is set up and turned on. <a href="#/accounts">Set up accounts</a>
            </p>
          )}
          {takesNoVideo.length > 0 && (
            <p className="side-note">
              <Icon name="alert" /> {takesNoVideo.map((platform) => PLATFORM_NAMES[platform]).join(", ")} {takesNoVideo.length === 1 ? "takes" : "take"} no videos yet. Remove {takesNoVideo.length === 1 ? "it" : "them"} or the video.
            </p>
          )}
          {videoTooLong.length > 0 && (
            <p className="side-note">
              <Icon name="alert" /> This video runs {clock(videoSeconds!)}.{" "}
              {videoTooLong.map((platform) => `${PLATFORM_NAMES[platform]} takes up to ${clock(videoLimit(platform) as number)}`).join("; ")}.
            </p>
          )}
          {unavailable.length > 0 && mode === "now" && (
            <p className="side-note">
              <Icon name="alert" /> {unavailable.map((platform) => PLATFORM_NAMES[platform]).join(", ")} not ready. <a href="#/accounts">Check accounts</a>
            </p>
          )}
        </section>

        <section className="card side-section">
          <h2 className="label">When</h2>
          <div className="segmented segmented-full" role="radiogroup" aria-label="When to publish">
            <button type="button" role="radio" aria-checked={mode === "now"} onClick={() => setMode("now")}>
              <Icon name="send" size={14} /> Now
            </button>
            <button type="button" role="radio" aria-checked={mode === "schedule"} onClick={() => setMode("schedule")}>
              <Icon name="clock" size={14} /> Later
            </button>
          </div>
          {mode === "schedule" && (
            <>
              <input className="input" type="datetime-local" aria-label="Publish at" value={draft.scheduledAt} min={toLocalInput(new Date().toISOString())} onChange={(event) => edit({ scheduledAt: event.target.value })} />
              <p className="muted side-hint">The server publishes it on time, even with this page closed.</p>
            </>
          )}
        </section>

        {draft.platforms.length > 0 && !empty && (
          <section className="card side-section previews">
            <h2 className="label">Preview</h2>
            {draft.platforms.map((platform) => {
              const limit = counts.find((count) => count.platform === platform)?.limit ?? 280;
              return (
                <article key={platform} className="preview" data-platform={platform}>
                  <header>
                    <PlatformIcon platform={platform} size={16} />
                    <strong>{handle(platform) ? `@${handle(platform)}` : PLATFORM_NAMES[platform]}</strong>
                  </header>
                  {draft.text && <p data-over={length(draft.text, platform) > limit}>{draft.text}</p>}
                  {draft.images.length > 0 && (
                    <div className="preview-media" data-count={Math.min(draft.images.length, 4)}>
                      {draft.images.slice(0, 4).map((image, index) => (
                        <img key={index} src={`data:${image.mimeType};base64,${image.data}`} alt="" />
                      ))}
                    </div>
                  )}
                  {draft.video && (
                    <div className="preview-media" data-count="1">
                      {videoUrl ? <video src={videoUrl} muted playsInline preload="metadata" aria-label={draft.video.alt || "Attached video"} /> : <span className="thumb-loading" />}
                    </div>
                  )}
                </article>
              );
            })}
          </section>
        )}
      </aside>

      <footer className="composer-actions">
        <p className="composer-status" role="status" aria-live="polite" data-error={notice?.error ?? false}>
          {notice?.text ?? (draft.postId ? "Editing a saved post." : "Drafts save in this browser as you type.")}
        </p>
        <div className="composer-buttons">
          <button
            className="btn btn-ghost"
            type="button"
            disabled={busy || (empty && !draft.postId)}
            onClick={() => {
              const editingSaved = Boolean(draft.postId);
              reset();
              if (editingSaved) onDone?.();
            }}
          >
            {draft.postId ? "Discard changes" : "Clear"}
          </button>
          <button className="btn btn-secondary" type="button" disabled={blocked} onClick={() => submit("draft")}>
            Save draft
          </button>
          <button className="btn btn-primary" type="button" disabled={!canSubmit} onClick={() => submit(mode === "schedule" ? "schedule" : "now")} title={`⌘Enter to ${primary.toLowerCase()}`}>
            <Icon name={mode === "schedule" ? "clock" : "send"} /> {busy ? "Working…" : primary}
          </button>
        </div>
      </footer>

      {sent && (
        <section className="card outcome" aria-live="polite">
          <Results results={sent.results} />
          <div className="outcome-actions">
            {failed && (
              <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => followUp("retry")}>
                <Icon name="refresh" /> Retry failed
              </button>
            )}
            {uncertain && (
              <button type="button" className="btn btn-secondary btn-sm" disabled={busy} onClick={() => followUp("check")}>
                Check result
              </button>
            )}
            <a className="btn btn-ghost btn-sm" href={`#/posts/${sent.id}`}>
              Open post
            </a>
          </div>
        </section>
      )}
    </div>
  );
}
