import { PLATFORMS } from "../api";
import { PlatformIcon, type AccountState } from "../ui";

export function AccountsPage({ accounts }: { accounts: AccountState }) {
  return (
    <section className="page" aria-labelledby="accounts-title">
      <div className="page-heading">
        <h1 id="accounts-title">Accounts</h1>
        <button type="button" className="clear-button" disabled={accounts.checking} onClick={accounts.refresh}>
          {accounts.checking ? "Checking…" : "Check again"}
        </button>
      </div>
      {accounts.error && <p className="error">{accounts.error}</p>}
      <ul className="account-list">
        {PLATFORMS.map((id) => {
          const account = accounts.accounts?.platforms.find((item) => item.id === id);
          return (
            <li key={id} data-ready={account?.available ?? false}>
              <PlatformIcon platform={id} />
              <div>
                <strong>{account?.label ?? id}</strong>
                <span>{account ? (account.available ? `Signed in as @${account.handle}` : (account.reason ?? "Session unavailable.")) : "Checking…"}</span>
                {account?.detail && (
                  <details className="account-detail">
                    <summary>Technical details</summary>
                    <code>{account.detail}</code>
                  </details>
                )}
              </div>
              <span className="account-state">{account?.available ? "Ready" : account ? "Needs attention" : ""}</span>
            </li>
          );
        })}
      </ul>
      <div className="setup-note">
        <h2>Connect or refresh accounts</h2>
        <p>Postslop uses the sessions you are already signed in with in Chrome or Firefox. It needs no API keys, and nothing leaves this Mac. Check which account is signed in on each site, then run:</p>
        <pre>
          <code>cd server && bin/postslop setup</code>
        </pre>
        <p>It reads Chrome (add <code>--browser=firefox</code> for Firefox), asks for each handle, and refuses a session that belongs to another account.</p>
      </div>
    </section>
  );
}
