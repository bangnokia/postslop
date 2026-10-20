import { useEffect, useState } from "react";
import { api, inFlight, postTime, type Post, type PostStatus } from "../api";
import { Icon } from "../components/icons";
import { when } from "../compose";
import { PageHeader, useShell } from "../shell";
import { MediaThumb, PlatformIcon, StatusBadge, usePolling, usePostsChanged } from "../ui";

export const FILTERS: { id: string; label: string; statuses: PostStatus[] }[] = [
  { id: "all", label: "All", statuses: [] },
  { id: "scheduled", label: "Scheduled", statuses: ["scheduled"] },
  { id: "drafts", label: "Drafts", statuses: ["draft"] },
  { id: "attention", label: "Needs attention", statuses: ["partial", "failed", "unknown"] },
  { id: "published", label: "Published", statuses: ["published"] },
];

export function Posts({ filter }: { filter?: string }) {
  const shell = useShell();
  const active = FILTERS.find((item) => item.id === filter) ?? FILTERS[0];
  const [posts, setPosts] = useState<Post[]>();
  const [next, setNext] = useState<string | null>(null);
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState("");

  const load = () => {
    let live = true;
    api
      .posts(active.statuses, undefined, 48)
      .then((page) => {
        if (!live) return;
        // Upcoming posts read best soonest first.
        setPosts(active.id === "scheduled" ? [...page.posts].sort((a, b) => (a.scheduledAt ?? "").localeCompare(b.scheduledAt ?? "")) : page.posts);
        setNext(page.next);
        setError(undefined);
      })
      .catch((reason: Error) => live && setError(reason.message));
    return () => {
      live = false;
    };
  };

  useEffect(() => {
    setPosts(undefined);
    return load();
  }, [active.id]);
  usePostsChanged(load);

  // Posts the queue is still publishing settle on their own; refresh just those.
  const pending = posts?.filter(inFlight) ?? [];
  usePolling(pending.length > 0, () => {
    for (const post of pending) {
      api
        .post(post.id)
        .then((fresh) => setPosts((current) => current?.map((item) => (item.id === fresh.id ? fresh : item))))
        .catch(() => {});
    }
  });

  async function more() {
    if (!next) return;
    setLoading(true);
    try {
      const page = await api.posts(active.statuses, next, 48);
      setPosts((current) => [...(current ?? []), ...page.posts]);
      setNext(page.next);
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setLoading(false);
    }
  }

  const query = search.trim().toLowerCase();
  const shown = query ? posts?.filter((post) => post.text.toLowerCase().includes(query)) : posts;

  return (
    <>
      <PageHeader title={active.id === "all" ? "Posts" : active.label}>
        <button type="button" className="btn btn-primary" onClick={() => shell.compose()}>
          <Icon name="plus" /> New post
        </button>
      </PageHeader>
      <div className="page-body">
        <div className="toolbar">
          <nav className="segmented filter-tabs" aria-label="Filter posts">
            {FILTERS.map((item) => (
              <a key={item.id} href={item.id === "all" ? "#/posts" : `#/posts/filter/${item.id}`} aria-current={item.id === active.id ? "page" : undefined}>
                {item.label}
              </a>
            ))}
          </nav>
          <label className="search">
            <Icon name="search" />
            <span className="visually-hidden">Search posts</span>
            <input className="input" type="search" placeholder="Search loaded posts" value={search} onChange={(event) => setSearch(event.target.value)} />
          </label>
        </div>
        {error && <p className="error-text">{error}</p>}
        {!posts && !error && (
          <div className="post-grid" aria-busy="true">
            {Array.from({ length: 8 }, (_, index) => (
              <div key={index} className="card post-card skeleton" />
            ))}
          </div>
        )}
        {shown?.length === 0 && (
          <div className="empty-state">
            <Icon name="grid" size={28} />
            <h2>{query ? "No posts match" : "Nothing here yet"}</h2>
            <p>{query ? "Try other words, or load older posts." : 'Write a post, or from a terminal: postslop post "Hello"'}</p>
            {!query && (
              <button type="button" className="btn btn-secondary" onClick={() => shell.compose()}>
                <Icon name="plus" /> New post
              </button>
            )}
          </div>
        )}
        {shown && shown.length > 0 && (
          <ul className="post-grid">
            {shown.map((post) => (
              <li key={post.id}>
                <PostCard post={post} onOpen={() => shell.openPost(post.id)} />
              </li>
            ))}
          </ul>
        )}
        {next && (
          <div className="load-more">
            <button type="button" className="btn btn-secondary" disabled={loading} onClick={more}>
              {loading ? "Loading…" : "Load older posts"}
            </button>
          </div>
        )}
      </div>
    </>
  );
}

export function PostCard({ post, onOpen }: { post: Post; onOpen: () => void }) {
  const time = post.status === "draft" ? post.updatedAt : postTime(post);
  return (
    <button type="button" className="card post-card" onClick={onOpen}>
      <div className="post-card-top">
        <StatusBadge status={post.status} />
        <time className="post-card-time" dateTime={time}>
          {post.status === "draft" ? "Edited " : ""}
          {when(time)}
        </time>
      </div>
      {post.images.length > 0 && (
        <div className="post-card-media" data-count={Math.min(post.images.length, 4)}>
          {post.images.slice(0, 4).map((image) => (
            <MediaThumb key={image.id} id={image.id} alt={image.alt} />
          ))}
        </div>
      )}
      <p className="post-card-text">{post.text || <em className="muted">No text</em>}</p>
      <div className="post-card-foot">
        {post.platforms.map((platform) => {
          const result = post.results.find((item) => item.platform === platform);
          return (
            <span key={platform} className="platform-chip" data-state={result?.status ?? "waiting"} title={result ? `${platform}: ${result.status}` : platform}>
              <PlatformIcon platform={platform} size={14} />
            </span>
          );
        })}
      </div>
    </button>
  );
}
