import { StrictMode } from "react";
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

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
