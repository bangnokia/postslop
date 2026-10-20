import { useEffect, useState } from "react";
import { api, PLATFORM_NAMES, type Post } from "../api";
import { toIso, toLocalInput, when } from "../compose";
import { go, MediaThumb, Results, StatusBadge } from "../ui";

export function PostDetail({ id }: { id: string }) {
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

  async function act(action: () => Promise<Post | void>, after?: () => void) {
    setBusy(true);
    setError(undefined);
    try {
      const updated = await action();
      if (updated) setPost(updated);
      after?.();
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (!post) return <section className="page">{error ? <p className="error">{error}</p> : <p className="loading">Loading…</p>}</section>;

  const editable = post.status === "draft" || post.status === "scheduled";
  const failed = post.results.some((result) => result.status === "failed");
  const recheck = post.status === "unknown" || post.status === "publishing";
  const future = time !== "" && new Date(time).getTime() > Date.now();

  return (
    <section className="page" aria-labelledby="post-title">
      <a className="back" href="#/posts">
        ← Posts
      </a>
      <div className="page-heading">
        <h1 id="post-title" className="visually-hidden">
          Post
        </h1>
        <StatusBadge status={post.status} />
        <span className="muted">
          {post.status === "scheduled" && post.scheduledAt ? `Publishes ${when(post.scheduledAt)}` : post.publishedAt ? `Published ${when(post.publishedAt)}` : `Written ${when(post.createdAt)}`}
          {" · "}
          {post.platforms.map((platform) => PLATFORM_NAMES[platform]).join(", ")}
        </span>
      </div>
      <article className="post-body">
        <p>{post.text || <em>No text</em>}</p>
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
      <Results results={post.results} />
      {error && <p className="error">{error}</p>}
      <div className="detail-actions">
        {editable && (
          <>
            <button type="button" className="publish-button" data-ready="true" disabled={busy} onClick={() => act(() => api.publish(post.id))}>
              {busy ? "Working…" : "Publish now"} <span aria-hidden="true">↗</span>
            </button>
            <a className="clear-button" href={`#/compose/${post.id}`}>
              Edit
            </a>
            <span className="schedule-inline">
              <input type="datetime-local" aria-label="Publish at" value={time} min={toLocalInput(new Date().toISOString())} onChange={(event) => setTime(event.target.value)} />
              <button type="button" className="clear-button" disabled={busy || !future} onClick={() => act(() => api.schedule(post.id, toIso(time)))}>
                {post.status === "scheduled" ? "Reschedule" : "Schedule"}
              </button>
            </span>
            {post.status === "scheduled" && (
              <button type="button" className="clear-button" disabled={busy} onClick={() => act(() => api.schedule(post.id, null), () => setTime(""))}>
                Unschedule
              </button>
            )}
            <button
              type="button"
              className="clear-button danger"
              disabled={busy}
              onClick={() => {
                if (window.confirm("Delete this post? It has not been sent anywhere.")) act(() => api.remove(post.id), () => go("/posts"));
              }}
            >
              Delete
            </button>
          </>
        )}
        {failed && !editable && (
          <button type="button" className="publish-button" data-ready="true" disabled={busy} onClick={() => act(() => api.retry(post.id))}>
            {busy ? "Working…" : "Retry failed destinations"} <span aria-hidden="true">↗</span>
          </button>
        )}
        {recheck && (
          <button type="button" className="clear-button" disabled={busy} onClick={() => act(() => api.post(post.id))}>
            Check result again
          </button>
        )}
      </div>
      {post.status === "unknown" && <p className="hint">Postslop never resends an unknown result. Check the account first: the post may already be live.</p>}
    </section>
  );
}
