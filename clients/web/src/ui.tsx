import { useEffect, useState } from "react";
import { api, cleanUrl, PLATFORM_NAMES, type Accounts, type Platform, type PostStatus, type Result } from "./api";

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

export function go(path: string) {
  window.location.hash = path;
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

export function PlatformIcon({ platform }: { platform: Platform }) {
  if (platform === "x") return <span className="network-icon x-icon" aria-hidden="true">𝕏</span>;
  if (platform === "bluesky")
    return (
      <span className="network-icon bluesky-icon" aria-hidden="true">
        <svg viewBox="0 0 32 32" fill="currentColor">
          <path d="M7 5c4 3 8 9 9 12 1-3 5-9 9-12 3-2 5-1 5 2 0 1-1 8-2 9-1 3-5 3-8 2 5 1 6 5 3 8-5 5-7-3-7-3s-2 8-7 3c-3-3-2-7 3-8-3 1-7 1-8-2C3 15 2 8 2 7c0-3 2-4 5-2Z" />
        </svg>
      </span>
    );
  return <span className="network-icon terminal-icon" aria-hidden="true">&gt;_</span>;
}

const STATUS_LABELS: Record<PostStatus, string> = {
  draft: "Draft",
  scheduled: "Scheduled",
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
  return src ? <img className="thumb" src={src} alt={alt || "Attached image"} /> : <span className="thumb thumb-loading" />;
}

export function Results({ results }: { results: Result[] }) {
  if (!results.length) return null;
  return (
    <ul className="results">
      {results.map((result) => {
        const url = cleanUrl(result.url);
        return (
          <li key={result.platform} data-state={result.status}>
            <PlatformIcon platform={result.platform} />
            <span className="result-name">{PLATFORM_NAMES[result.platform]}</span>
            <span className="result-detail">
              {result.status === "published" ? "Published" : (result.error ?? result.status)}
            </span>
            {url && (
              <a href={url} target="_blank" rel="noopener noreferrer">
                View ↗
              </a>
            )}
          </li>
        );
      })}
    </ul>
  );
}
