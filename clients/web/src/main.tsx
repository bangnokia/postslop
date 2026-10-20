import { StrictMode, useEffect, useState, type FormEvent } from "react";
import { createRoot } from "react-dom/client";
import { ApiError, signIn, storedToken } from "./api";
import { AccountsPage } from "./pages/AccountsPage";
import { Calendar } from "./pages/Calendar";
import { Composer, ComposePage } from "./pages/Compose";
import { PostDetail } from "./pages/PostDetail";
import { Posts } from "./pages/Posts";
import { ShellProvider, Sidebar, Toasts, useShellState } from "./shell";
import { Drawer, Modal, useAccounts, useRoute, useTheme } from "./ui";
import "@fontsource-variable/geist";
import "@fontsource-variable/geist-mono";
import "./styles/tokens.css";
import "./styles/base.css";
import "./styles/layout.css";
import "./styles/posts.css";
import "./styles/calendar.css";
import "./styles/compose.css";
import "./styles/accounts.css";

function App() {
  const [page, id, filter] = useRoute();
  const accounts = useAccounts();
  const state = useShellState();
  const [menu, setMenu] = useState(false);
  useTheme();
  const section = page === "posts" || page === "accounts" || page === "compose" ? page : "calendar";

  // A link to one post opens it in the drawer over the posts.
  const linked = page === "posts" && id && id !== "filter" ? id : undefined;
  useEffect(() => {
    if (linked) state.shell.openPost(linked);
  }, [linked]);

  // C starts a new post from anywhere outside a text field.
  useEffect(() => {
    const key = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement;
      if (event.key.toLowerCase() !== "c" || event.metaKey || event.ctrlKey || event.altKey) return;
      if (target.closest("input, textarea, select, [contenteditable]") || document.querySelector(".layer")) return;
      event.preventDefault();
      state.shell.compose();
    };
    document.addEventListener("keydown", key);
    return () => document.removeEventListener("keydown", key);
  }, []);

  return (
    <ShellProvider value={{ ...state.shell, openMenu: () => setMenu(true) }}>
      <div className="app">
        <Sidebar section={section} filter={page === "posts" && id === "filter" ? filter : undefined} accounts={accounts} open={menu} onClose={() => setMenu(false)} />
        <main className="main">
          {section === "calendar" && <Calendar view={id === "week" ? "week" : "month"} />}
          {section === "compose" && <ComposePage key={id ?? "new"} editing={id} accounts={accounts} />}
          {section === "posts" && <Posts filter={id === "filter" ? filter : undefined} />}
          {section === "accounts" && <AccountsPage accounts={accounts} />}
        </main>
      </div>
      {state.openPost && (
        <Drawer
          title="Post"
          onClose={() => {
            state.closePost();
            if (linked) window.location.hash = "#/posts";
          }}
        >
          <PostDetail key={state.openPost} id={state.openPost} onClose={state.closePost} />
        </Drawer>
      )}
      {state.composing && (
        <Modal title={state.composing.editing ? "Edit post" : "New post"} onClose={state.closeCompose}>
          <Composer editing={state.composing.editing} at={state.composing.at} accounts={accounts} onDone={state.closeCompose} />
        </Modal>
      )}
      <Toasts toasts={state.toasts} dismiss={state.dismiss} />
    </ShellProvider>
  );
}

// Sign in with the access token, POSTSLOP_TOKEN in server/.env, checked against the API before it is kept.
function SignIn() {
  const [token, setToken] = useState("");
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
  useTheme();
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      const response = await fetch("/api/v1/accounts", { headers: { Authorization: `Bearer ${token.trim()}`, Accept: "application/json" } });
      if (response.status === 401) throw new ApiError("That access token is not right.", "unauthorized", 401);
      if (!response.ok) throw new ApiError(`Postslop answered HTTP ${response.status}.`, "error", response.status);
      signIn(token.trim());
      window.location.reload();
    } catch (failure) {
      setError(failure instanceof ApiError ? failure.message : "Postslop is not reachable.");
      setBusy(false);
    }
  };
  return (
    <main className="sign-in">
      <form className="card sign-in-card" onSubmit={submit}>
        <span className="brand">
          <span className="brand-mark" aria-hidden="true">
            <span />
            <span />
          </span>
          postslop<span className="brand-period">.</span>
        </span>
        <div>
          <h1>Sign in</h1>
          <p className="muted">Enter the access token, POSTSLOP_TOKEN in server/.env on the machine running Postslop.</p>
        </div>
        <div>
          <label className="label" htmlFor="token">
            Access token
          </label>
          <input id="token" className="input" type="password" autoComplete="current-password" autoFocus required value={token} onChange={(event) => setToken(event.target.value)} />
        </div>
        {error && <p className="error-text">{error}</p>}
        <button className="btn btn-primary btn-block" disabled={token.trim() === "" || busy} type="submit">
          {busy ? "Checking…" : "Sign in"}
        </button>
      </form>
    </main>
  );
}

// Without POSTSLOP_TOKEN the server is open, so sign-in is asked for only when the API refuses this browser.
function Gate() {
  const [open, setOpen] = useState<boolean>();
  useEffect(() => {
    fetch("/api/v1/posts?limit=1", { headers: { Authorization: `Bearer ${storedToken()}`, Accept: "application/json" } })
      .then((response) => setOpen(response.status !== 401))
      .catch(() => setOpen(true));
  }, []);
  if (open === undefined) return null;
  return open ? <App /> : <SignIn />;
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <Gate />
  </StrictMode>,
);
