import { StrictMode, useState, type FormEvent } from "react";
import { ApiError, signIn, storedToken } from "./api";
import { createRoot } from "react-dom/client";
import { AccountsPage } from "./pages/AccountsPage";
import { Compose } from "./pages/Compose";
import { PostDetail } from "./pages/PostDetail";
import { Posts } from "./pages/Posts";
import { useAccounts, useRoute } from "./ui";
import "@fontsource-variable/geist";
import "@fontsource-variable/geist-mono";
import "./styles.css";

function App() {
  const [page, id, filter] = useRoute();
  const accounts = useAccounts();
  const ready = accounts.accounts?.platforms.filter((account) => account.available).length;
  const section = page === "posts" ? "posts" : page === "accounts" ? "accounts" : "compose";

  return (
    <div className="workspace">
      <header className="site-header">
        <a className="brand" href="#/" aria-label="Postslop home">
          <span className="brand-mark" aria-hidden="true">
            <span />
            <span />
          </span>
          postslop<span className="brand-period">.</span>
        </a>
        <nav className="main-nav" aria-label="Postslop">
          <a href="#/" aria-current={section === "compose" ? "page" : undefined}>
            Compose
          </a>
          <a href="#/posts" aria-current={section === "posts" ? "page" : undefined}>
            Posts
          </a>
          <a href="#/accounts" aria-current={section === "accounts" ? "page" : undefined}>
            Accounts
            <span className="account-dot" data-ready={ready === 3} title={accounts.accounts ? `${ready} of 3 accounts ready` : "Checking accounts"} />
          </a>
        </nav>
      </header>
      <main>
        {section === "compose" && <Compose key={id ?? "new"} editing={page === "compose" ? id : undefined} accounts={accounts} />}
        {section === "posts" && (id === "filter" ? <Posts filter={filter} /> : id ? <PostDetail key={id} id={id} /> : <Posts />)}
        {section === "accounts" && <AccountsPage accounts={accounts} />}
      </main>
      <footer className="workspace-footer">
        <p>Write it once. Put it out there.</p>
        <span>Also from the terminal: postslop post "…"</span>
      </footer>
    </div>
  );
}

// Sign in with the access token from server/storage/client-token, checked against the API before it is kept.
function SignIn() {
  const [token, setToken] = useState("");
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
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
    <div className="workspace">
      <main className="page sign-in">
        <h1>Sign in to postslop<span className="brand-period">.</span></h1>
        <form onSubmit={submit}>
          <label htmlFor="token">Access token</label>
          <input id="token" type="password" autoComplete="current-password" autoFocus required value={token} onChange={(event) => setToken(event.target.value)} />
          <button className="publish-button" data-ready={token.trim() !== "" && !busy} disabled={token.trim() === "" || busy} type="submit">
            Sign in
          </button>
        </form>
        {error && <p className="sign-in-error">{error}</p>}
        <p className="hint">It is in server/storage/client-token on the machine running Postslop.</p>
      </main>
    </div>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    {storedToken() ? <App /> : <SignIn />}
  </StrictMode>,
);
