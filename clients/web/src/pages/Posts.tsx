import { useEffect, useState } from "react";
import { api, type Post, type PostStatus } from "../api";
import { when } from "../compose";
import { MediaThumb, PlatformIcon, StatusBadge } from "../ui";

const FILTERS: { id: string; label: string; statuses: PostStatus[] }[] = [
  { id: "all", label: "All", statuses: [] },
  { id: "scheduled", label: "Scheduled", statuses: ["scheduled"] },
  { id: "drafts", label: "Drafts", statuses: ["draft"] },
  { id: "attention", label: "Needs attention", statuses: ["partial", "failed", "unknown"] },
  { id: "published", label: "Published", statuses: ["published"] },
];

export function Posts({ filter }: { filter?: string }) {
  const active = FILTERS.find((item) => item.id === filter) ?? FILTERS[0];
  const [posts, setPosts] = useState<Post[]>();
  const [next, setNext] = useState<string | null>(null);
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let live = true;
    setPosts(undefined);
    setError(undefined);
    api
      .posts(active.statuses)
      .then((page) => {
        if (!live) return;
        // Upcoming posts read best soonest first.
        setPosts(active.id === "scheduled" ? [...page.posts].sort((a, b) => (a.scheduledAt ?? "").localeCompare(b.scheduledAt ?? "")) : page.posts);
        setNext(page.next);
      })
      .catch((reason: Error) => live && setError(reason.message));
    return () => {
      live = false;
    };
  }, [active.id]);

  async function more() {
    if (!next) return;
    setLoading(true);
    try {
      const page = await api.posts(active.statuses, next);
      setPosts((current) => [...(current ?? []), ...page.posts]);
      setNext(page.next);
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <section className="page" aria-labelledby="posts-title">
      <div className="page-heading">
        <h1 id="posts-title">Posts</h1>
        <a className="publish-button" data-ready="true" href="#/">
          New post <span aria-hidden="true">↗</span>
        </a>
      </div>
      <nav className="tabs" aria-label="Filter posts">
        {FILTERS.map((item) => (
          <a key={item.id} href={`#/posts/filter/${item.id}`} aria-current={item.id === active.id ? "page" : undefined}>
            {item.label}
          </a>
        ))}
      </nav>
      {error && <p className="error">{error}</p>}
      {!posts && !error && <p className="loading">Loading posts…</p>}
      {posts?.length === 0 && <p className="empty">Nothing here yet. Write something on the compose page, or with: postslop post "Hello"</p>}
      <ul className="post-list">
        {posts?.map((post) => (
          <li key={post.id}>
            <a href={`#/posts/${post.id}`} className="post-row">
              <div className="post-meta">
                <StatusBadge status={post.status} />
                <time dateTime={post.scheduledAt ?? post.publishedAt ?? post.createdAt}>
                  {post.status === "scheduled" ? "for " : ""}
                  {when(post.scheduledAt ?? post.publishedAt ?? post.createdAt)}
                </time>
              </div>
              <p className="post-text">{post.text || <em>No text</em>}</p>
              <div className="post-extras">
                <span className="post-platforms">
                  {post.platforms.map((platform) => {
                    const result = post.results.find((item) => item.platform === platform);
                    return (
                      <span key={platform} data-state={result?.status ?? "waiting"}>
                        <PlatformIcon platform={platform} />
                      </span>
                    );
                  })}
                </span>
                {post.images.slice(0, 4).map((image) => (
                  <MediaThumb key={image.id} id={image.id} alt={image.alt} />
                ))}
              </div>
            </a>
          </li>
        ))}
      </ul>
      {next && (
        <button type="button" className="clear-button more" disabled={loading} onClick={more}>
          {loading ? "Loading…" : "Show older posts"}
        </button>
      )}
    </section>
  );
}
