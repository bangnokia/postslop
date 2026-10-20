import { useEffect, useState } from "react";
import { api, inFlight, PLATFORM_NAMES, postTime, type Post } from "../api";
import { Icon } from "../components/icons";
import { toIso, toLocalInput, when } from "../compose";
import { useShell } from "../shell";
import { MediaThumb, postsChanged, Results, StatusBadge, usePolling } from "../ui";

// One post, shown in the drawer over whichever page is open.
export function PostDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const shell = useShell();
  const [post, setPost] = useState<Post>();
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [time, setTime] = useState("");

  useEffect(() => {
    let live = true;
    api
      .post(id)
      .then((value) => {
        if (!live) return;
        setPost(value);
        setTime(value.scheduledAt ? toLocalInput(value.scheduledAt) : "");
      })
      .catch((reason: Error) => live && setError(reason.message));
    return () => {
      live = false;
    };
  }, [id]);

  usePolling(Boolean(post && inFlight(post)), () => {
    api.post(id).then(setPost).catch(() => {});
  });

  async function act(action: () => Promise<Post | void>, after?: (post: Post | void) => void) {
    setBusy(true);
    setError(undefined);
    try {
      const updated = await action();
      if (updated) setPost(updated);
      postsChanged();
      after?.(updated);
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (!post) return error ? <p className="error-text">{error}</p> : <div className="detail-skeleton skeleton" />;

  const editable = post.status === "draft" || post.status === "scheduled";
  const failed = post.results.some((result) => result.status === "failed");
  const future = time !== "" && new Date(time).getTime() > Date.now();
  const at = postTime(post);

  return (
    <div className="detail">
      <div className="detail-meta">
        <StatusBadge status={post.status} />
        <span className="muted">
          {post.status === "scheduled" ? `Publishes ${when(at)}` : post.publishedAt ? `Published ${when(post.publishedAt)}` : post.status === "draft" ? `Edited ${when(post.updatedAt)}` : when(at)}
        </span>
      </div>
      <article className="detail-body">
        <p>{post.text || <em className="muted">No text</em>}</p>
        {post.images.length > 0 && (
          <div className="detail-images">
            {post.images.map((image) => (
              <figure key={image.id}>
                <MediaThumb id={image.id} alt={image.alt} />
                {image.alt && <figcaption>{image.alt}</figcaption>}
              </figure>
            ))}
          </div>
        )}
      </article>
      <section>
        <h3 className="label">Destinations</h3>
        {post.results.length > 0 ? (
          <Results results={post.results} />
        ) : (
          <p className="muted">{post.platforms.map((platform) => PLATFORM_NAMES[platform]).join(", ")}</p>
        )}
      </section>
      {editable && (
        <section>
          <h3 className="label">Schedule</h3>
          <div className="detail-schedule">
            <input className="input" type="datetime-local" aria-label="Publish at" value={time} min={toLocalInput(new Date().toISOString())} onChange={(event) => setTime(event.target.value)} />
            <button type="button" className="btn btn-secondary" disabled={busy || !future} onClick={() => act(() => api.schedule(post.id, toIso(time)))}>
              <Icon name="clock" /> {post.status === "scheduled" ? "Reschedule" : "Schedule"}
            </button>
          </div>
          {post.status === "scheduled" && (
            <button type="button" className="btn btn-ghost btn-sm" disabled={busy} onClick={() => act(() => api.schedule(post.id, null), () => setTime(""))}>
              Move back to drafts
            </button>
          )}
        </section>
      )}
      {error && <p className="error-text">{error}</p>}
      {post.status === "unknown" && <p className="muted">Postslop never resends an unknown result. Check the account first: the post may already be live.</p>}
      <footer className="detail-actions">
        {editable && (
          <>
            <button
              type="button"
              className="btn btn-primary"
              disabled={busy}
              onClick={() =>
                act(
                  () => api.publish(post.id),
                  (updated) => updated && shell.track(updated),
                )
              }
            >
              <Icon name="send" /> Publish now
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => {
                onClose();
                shell.compose({ editing: post.id });
              }}
            >
              <Icon name="edit" /> Edit
            </button>
            <button
              type="button"
              className="btn btn-danger"
              disabled={busy}
              onClick={() => {
                if (window.confirm("Delete this post? It has not been sent anywhere.")) act(() => api.remove(post.id), onClose);
              }}
            >
              <Icon name="trash" /> Delete
            </button>
          </>
        )}
        {failed && !editable && (
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => act(() => api.retry(post.id))}>
            <Icon name="refresh" /> {busy ? "Retrying…" : "Retry failed destinations"}
          </button>
        )}
        {post.status === "unknown" && (
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => act(() => api.post(post.id))}>
            <Icon name="refresh" /> Check again
          </button>
        )}
      </footer>
    </div>
  );
}
