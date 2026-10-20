import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, cleanUrl, PLATFORM_NAMES, type Accounts, type Platform, type PostStatus, type Result } from "./api";
import { Icon } from "./components/icons";

export function useRoute(): string[] {
  const read = () => window.location.hash.replace(/^#\/?/, "").split("/").filter(Boolean);
  const [route, setRoute] = useState(read);
  useEffect(() => {
    const changed = () => setRoute(read());
    window.addEventListener("hashchange", changed);
    return () => window.removeEventListener("hashchange", changed);
  }, []);
  return route;
}

// Calls tick every interval while active, for posts the queue is still publishing.
export function usePolling(active: boolean, tick: () => void, interval = 1500) {
  useEffect(() => {
    if (!active) return;
    const timer = window.setInterval(tick, interval);
    return () => window.clearInterval(timer);
  });
}

export function go(path: string) {
  window.location.hash = path;
}

// Any change to posts, from any page, drawer or modal, tells every open view to reload.
const CHANGED = "postslop:posts-changed";

export function postsChanged() {
  window.dispatchEvent(new Event(CHANGED));
}

export function usePostsChanged(reload: () => void) {
  const latest = useRef(reload);
  latest.current = reload;
  useEffect(() => {
    const listener = () => latest.current();
    window.addEventListener(CHANGED, listener);
    return () => window.removeEventListener(CHANGED, listener);
  }, []);
}

export interface AccountState {
  accounts?: Accounts;
  error?: string;
  checking: boolean;
  refresh: () => void;
}

export function useAccounts(): AccountState {
  const [accounts, setAccounts] = useState<Accounts>();
  const [error, setError] = useState<string>();
  const [checking, setChecking] = useState(true);
  const load = (refresh: boolean) => {
    setChecking(true);
    api
      .accounts(refresh)
      .then((value) => {
        setAccounts(value);
        setError(undefined);
      })
      .catch((reason: Error) => setError(reason.message))
      .finally(() => setChecking(false));
  };
  useEffect(() => load(false), []);
  return { accounts, error, checking, refresh: () => load(true) };
}

export type Theme = "system" | "light" | "dark";

// The theme follows the system unless picked; the choice is a per-browser convenience.
export function useTheme(): [Theme, (theme: Theme) => void] {
  const [theme, setTheme] = useState<Theme>(() => {
    try {
      return (localStorage.getItem("postslop-theme") as Theme | null) ?? "system";
    } catch {
      return "system";
    }
  });
  useEffect(() => {
    if (theme === "system") document.documentElement.removeAttribute("data-theme");
    else document.documentElement.setAttribute("data-theme", theme);
    try {
      localStorage.setItem("postslop-theme", theme);
    } catch {
      // The choice simply is not remembered.
    }
  }, [theme]);
  return [theme, setTheme];
}

export function PlatformIcon({ platform, size = 20 }: { platform: Platform; size?: number }) {
  const style = { width: size, height: size };
  if (platform === "x")
    return (
      <span className="platform platform-x" style={style} aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="currentColor">
          <path d="M17.75 3h3.07l-6.7 7.66L22 21h-6.17l-4.83-6.32L5.47 21H2.4l7.17-8.2L2 3h6.33l4.37 5.77L17.75 3Zm-1.08 16.17h1.7L7.4 4.73H5.58l11.09 14.44Z" />
        </svg>
      </span>
    );
  if (platform === "bluesky")
    return (
      <span className="platform platform-bluesky" style={style} aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="currentColor">
          <path d="M7 5c4 3 8 9 9 12 1-3 5-9 9-12 3-2 5-1 5 2 0 1-1 8-2 9-1 3-5 3-8 2 5 1 6 5 3 8-5 5-7-3-7-3s-2 8-7 3c-3-3-2-7 3-8-3 1-7 1-8-2C3 15 2 8 2 7c0-3 2-4 5-2Z" />
        </svg>
      </span>
    );
  // dev-null.sh's own mark, from https://dev-null.sh/favicon.svg.
  return (
    <span className="platform platform-dev-null" style={style} aria-hidden="true">
      <svg viewBox="1.6 1.5 45 45">
        <path className="ink" d="M2 42h10L26 6H16Z" />
        <path className="accent" fillRule="evenodd" clipRule="evenodd" d="M17.81 43.5h13.22L46.19 4.5H32.97ZM22.19 40.5l12.84-33h6.78l-12.84 33Z" />
      </svg>
    </span>
  );
}

export const STATUS_LABELS: Record<PostStatus, string> = {
  draft: "Draft",
  scheduled: "Scheduled",
  queued: "Queued",
  publishing: "Publishing",
  published: "Published",
  partial: "Partly published",
  failed: "Not published",
  unknown: "Check result",
};

export function StatusBadge({ status }: { status: PostStatus }) {
  return <span className={`badge badge-${status}`}>{STATUS_LABELS[status]}</span>;
}

export function MediaThumb({ id, alt }: { id: string; alt: string }) {
  const [src, setSrc] = useState<string>();
  useEffect(() => {
    let url: string | undefined;
    let live = true;
    api
      .media(id)
      .then((blob) => {
        url = URL.createObjectURL(blob);
        if (live) setSrc(url);
      })
      .catch(() => {});
    return () => {
      live = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [id]);
  return src ? <img className="thumb" src={src} alt={alt || "Attached image"} /> : <span className="thumb-loading" />;
}

export function Results({ results }: { results: Result[] }) {
  if (!results.length) return null;
  return (
    <ul className="results">
      {results.map((result) => {
        const url = cleanUrl(result.url);
        return (
          <li key={result.platform} data-state={result.status}>
            <PlatformIcon platform={result.platform} size={18} />
            <span className="result-name">{PLATFORM_NAMES[result.platform]}</span>
            <span className="result-detail">{result.status === "published" ? "Published" : (result.error ?? result.status)}</span>
            {url && (
              <a className="btn btn-ghost btn-sm" href={url} target="_blank" rel="noopener noreferrer">
                View <Icon name="external" size={14} />
              </a>
            )}
          </li>
        );
      })}
    </ul>
  );
}

// Closes on Escape and keeps scrolling to the layer on top.
function useLayer(onClose: () => void) {
  const latest = useRef(onClose);
  latest.current = onClose;
  useEffect(() => {
    const key = (event: KeyboardEvent) => {
      if (event.key === "Escape") latest.current();
    };
    document.addEventListener("keydown", key);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", key);
      document.body.style.overflow = "";
    };
  }, []);
}

export function Drawer({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  useLayer(onClose);
  return (
    <div className="layer" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <aside className="drawer" role="dialog" aria-modal="true" aria-label={title}>
        <header className="layer-header">
          <h2>{title}</h2>
          <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label="Close" onClick={onClose}>
            <Icon name="close" />
          </button>
        </header>
        <div className="layer-body">{children}</div>
      </aside>
    </div>
  );
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  useLayer(onClose);
  return (
    <div className="layer layer-center" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className="modal" role="dialog" aria-modal="true" aria-label={title}>
        <header className="layer-header">
          <h2>{title}</h2>
          <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label="Close" onClick={onClose}>
            <Icon name="close" />
          </button>
        </header>
        <div className="layer-body">{children}</div>
      </section>
    </div>
  );
}
