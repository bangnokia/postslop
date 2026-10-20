import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { api, inFlight, PLATFORM_NAMES, PLATFORMS, signOut, storedToken, type Post, type PostStatus } from "./api";
import { Icon, type IconName } from "./components/icons";
import { FILTERS } from "./pages/Posts";
import { PlatformIcon, postsChanged, usePolling, usePostsChanged, usable, useTheme, type AccountState, type Theme } from "./ui";

export interface Toast {
  id: number;
  text: string;
  tone: "info" | "success" | "error";
  link?: { href: string; label: string };
}

interface ShellApi {
  // Opens the composer over the current page, for a new post (optionally at a time) or to edit a saved one.
  compose: (options?: { at?: Date; editing?: string }) => void;
  openPost: (id: string) => void;
  // Follows a post the queue is publishing and reports its result, whichever page is open.
  track: (post: Post) => void;
  toast: (toast: Omit<Toast, "id">) => void;
  // Opens the sidebar on small screens.
  openMenu: () => void;
}

const ShellContext = createContext<ShellApi | null>(null);

export function useShell(): ShellApi {
  const shell = useContext(ShellContext);
  if (!shell) throw new Error("useShell is used outside the shell.");
  return shell;
}

let toastId = 0;

export function useShellState() {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [tracked, setTracked] = useState<Post[]>([]);
  const [composing, setComposing] = useState<{ at?: Date; editing?: string }>();
  const [openPost, setOpenPost] = useState<string>();

  const toast = (value: Omit<Toast, "id">) => {
    const id = ++toastId;
    setToasts((current) => [...current.slice(-3), { ...value, id }]);
    if (value.tone !== "error") window.setTimeout(() => setToasts((current) => current.filter((item) => item.id !== id)), 6000);
  };

  usePolling(tracked.length > 0, () => {
    for (const post of tracked) {
      api
        .post(post.id)
        .then((fresh) => {
          if (inFlight(fresh)) return;
          setTracked((current) => current.filter((item) => item.id !== fresh.id));
          postsChanged();
          const link = { href: `#/posts/${fresh.id}`, label: "View post" };
          if (fresh.status === "published") {
            toast({ tone: "success", text: `Published to ${fresh.platforms.map((platform) => PLATFORM_NAMES[platform]).join(", ")}.`, link });
            return;
          }
          // Failed or uncertain: the post and its images stay in Postslop, where it can be retried or checked.
          const problems = fresh.results.filter((result) => result.status !== "published").map((result) => `${PLATFORM_NAMES[result.platform]}: ${result.error ?? result.status}`);
          toast({ tone: "error", text: `${fresh.status === "unknown" ? "The outcome is unknown." : "Not everything was published."} ${problems.join(" ")}`, link });
        })
        .catch(() => {});
    }
  });

  const shell: Omit<ShellApi, "openMenu"> = {
    compose: (options) => setComposing(options ?? {}),
    openPost: setOpenPost,
    track: (post) => setTracked((current) => [...current.filter((item) => item.id !== post.id), post]),
    toast,
  };
  return {
    shell,
    toasts,
    dismiss: (id: number) => setToasts((current) => current.filter((item) => item.id !== id)),
    composing,
    closeCompose: () => setComposing(undefined),
    openPost,
    closePost: () => setOpenPost(undefined),
    publishing: tracked.length,
  };
}

export function ShellProvider({ value, children }: { value: ShellApi; children: ReactNode }) {
  return <ShellContext.Provider value={value}>{children}</ShellContext.Provider>;
}

// How many posts each sidebar filter holds, refreshed whenever posts change.
function useCounts() {
  const [counts, setCounts] = useState<Record<string, string>>({});
  const load = () => {
    for (const filter of FILTERS) {
      if (filter.statuses.length === 0) continue;
      api
        .posts(filter.statuses as PostStatus[], undefined, 99)
        .then((page) => setCounts((current) => ({ ...current, [filter.id]: page.next ? "99+" : page.posts.length ? String(page.posts.length) : "" })))
        .catch(() => {});
    }
  };
  useEffect(load, []);
  usePostsChanged(load);
  return counts;
}

const THEMES: { id: Theme; icon: IconName; label: string }[] = [
  { id: "light", icon: "sun", label: "Light" },
  { id: "system", icon: "monitor", label: "System" },
  { id: "dark", icon: "moon", label: "Dark" },
];

export function Sidebar({ section, filter, accounts, open, onClose }: { section: string; filter?: string; accounts: AccountState; open: boolean; onClose: () => void }) {
  const shell = useShell();
  const counts = useCounts();
  const [theme, setTheme] = useTheme();
  const nav = (href: string, icon: IconName, label: string, current: boolean) => (
    <a className="nav-item" href={href} aria-current={current ? "page" : undefined} onClick={onClose}>
      <Icon name={icon} />
      {label}
    </a>
  );
  return (
    <>
      <div className="sidebar-scrim" data-open={open} onClick={onClose} aria-hidden="true" />
      <aside className="sidebar" data-open={open} aria-label="Postslop">
        <a className="brand" href="#/" onClick={onClose} aria-label="Postslop home">
          <span className="brand-mark" aria-hidden="true">
            <span />
            <span />
          </span>
          postslop<span className="brand-period">.</span>
        </a>
        <button
          type="button"
          className="btn btn-primary btn-block"
          onClick={() => {
            onClose();
            shell.compose();
          }}
        >
          <Icon name="plus" /> New post
          <kbd className="sidebar-kbd">C</kbd>
        </button>
        <nav className="nav" aria-label="Main">
          {nav("#/calendar", "calendar", "Calendar", section === "calendar")}
          {nav("#/compose", "edit", "Compose", section === "compose")}
          {nav("#/posts", "grid", "Posts", section === "posts" && (!filter || filter === "all"))}
          <div className="nav-sub">
            {FILTERS.filter((item) => item.id !== "all").map((item) => (
              <a key={item.id} className="nav-item nav-item-sub" href={`#/posts/filter/${item.id}`} aria-current={section === "posts" && filter === item.id ? "page" : undefined} onClick={onClose}>
                <span className={`nav-dot nav-dot-${item.id}`} aria-hidden="true" />
                {item.label}
                {counts[item.id] && <span className="nav-count">{counts[item.id]}</span>}
              </a>
            ))}
          </div>
        </nav>
        <div className="sidebar-section">
          <a className="sidebar-heading" href="#/accounts" onClick={onClose} aria-current={section === "accounts" ? "page" : undefined}>
            Accounts
          </a>
          <ul className="sidebar-accounts">
            {PLATFORMS.map((platform) => {
              const account = accounts.accounts?.platforms.find((item) => item.id === platform);
              const state = !account ? "checking" : !usable(account) ? "off" : account.available ? "ready" : "attention";
              return (
                <li key={platform}>
                  <a href="#/accounts" onClick={onClose} title={state === "off" ? (account?.connected ? "Turned off" : "Not connected") : (account?.reason ?? (state === "ready" ? "Ready" : "Checking…"))}>
                    <PlatformIcon platform={platform} size={16} />
                    <span className="sidebar-handle">{account?.handle ? `@${account.handle.replace(/^@/, "")}` : PLATFORM_NAMES[platform]}</span>
                    <span className="status-dot" data-state={state} aria-label={state} />
                  </a>
                </li>
              );
            })}
          </ul>
        </div>
        <div className="sidebar-footer">
          <div className="segmented theme-switch" role="radiogroup" aria-label="Theme">
            {THEMES.map((item) => (
              <button key={item.id} type="button" role="radio" aria-checked={theme === item.id} aria-label={item.label} title={item.label} onClick={() => setTheme(item.id)}>
                <Icon name={item.icon} size={14} />
              </button>
            ))}
          </div>
          {storedToken() && (
            <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label="Sign out" title="Sign out" onClick={signOut}>
              <Icon name="logout" />
            </button>
          )}
        </div>
      </aside>
    </>
  );
}

export function Toasts({ toasts, dismiss }: { toasts: Toast[]; dismiss: (id: number) => void }) {
  return (
    <div className="toasts" role="status" aria-live="polite">
      {toasts.map((item) => (
        <div key={item.id} className="toast" data-tone={item.tone}>
          <Icon name={item.tone === "error" ? "alert" : item.tone === "success" ? "check" : "send"} />
          <p>{item.text}</p>
          {item.link && (
            <a
              className="btn btn-ghost btn-sm"
              href={item.link.href}
              onClick={() => {
                dismiss(item.id);
              }}
            >
              {item.link.label}
            </a>
          )}
          <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label="Dismiss" onClick={() => dismiss(item.id)}>
            <Icon name="close" size={14} />
          </button>
        </div>
      ))}
    </div>
  );
}

export function PageHeader({ title, children }: { title: ReactNode; children?: ReactNode }) {
  const shell = useShell();
  return (
    <header className="page-header">
      <button type="button" className="btn btn-ghost btn-icon menu-button" aria-label="Open menu" onClick={shell.openMenu}>
        <Icon name="menu" />
      </button>
      <h1>{title}</h1>
      <div className="page-actions">{children}</div>
    </header>
  );
}

