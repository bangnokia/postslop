import { PLATFORMS, PLATFORM_NAMES } from "../api";
import { Icon } from "../components/icons";
import { PageHeader } from "../shell";
import { PlatformIcon, type AccountState } from "../ui";

export function AccountsPage({ accounts }: { accounts: AccountState }) {
  return (
    <>
      <PageHeader title="Accounts">
        <button type="button" className="btn btn-secondary" disabled={accounts.checking} onClick={accounts.refresh}>
          <Icon name="refresh" /> {accounts.checking ? "Checking…" : "Check again"}
        </button>
      </PageHeader>
      <div className="page-body page-body-narrow">
        {accounts.error && <p className="error-text">{accounts.error}</p>}
        <ul className="account-grid">
          {PLATFORMS.map((id) => {
            const account = accounts.accounts?.platforms.find((item) => item.id === id);
            const state = !account ? "checking" : account.available ? "ready" : "attention";
            return (
              <li key={id} className="card account-card" data-state={state}>
                <div className="account-head">
                  <PlatformIcon platform={id} size={28} />
                  <div>
                    <strong>{PLATFORM_NAMES[id]}</strong>
                    <span className="muted">{account?.available ? `@${account.handle?.replace(/^@/, "")}` : account ? "Not ready" : "Checking…"}</span>
                  </div>
                  <span className="account-state" data-state={state}>
                    <span className="status-dot" data-state={state} />
                    {state === "ready" ? "Ready" : state === "attention" ? "Needs attention" : "Checking"}
                  </span>
                </div>
                {account && !account.available && <p className="account-reason">{account.reason ?? "Session unavailable."}</p>}
                {account?.detail && (
                  <details className="account-detail">
                    <summary>Technical details</summary>
                    <code>{account.detail}</code>
                  </details>
                )}
                {account && (
                  <dl className="account-limits">
                    <div>
                      <dt>Characters</dt>
                      <dd>{account.textLimit}</dd>
                    </div>
                    <div>
                      <dt>Images</dt>
                      <dd>{account.maxImages}</dd>
                    </div>
                  </dl>
                )}
              </li>
            );
          })}
        </ul>
        <section className="card setup-card">
          <h2>Connect or refresh accounts</h2>
          <p>Postslop uses the sessions you are already signed in with in Chrome or Firefox, on the machine that runs it. It needs no API keys. Check which account is signed in on each site, then run:</p>
          <pre>
            <code>cd server && bin/postslop setup</code>
          </pre>
          <p className="muted">
            It reads Chrome (add <code>--browser=firefox</code> for Firefox), asks for each handle, and refuses a session that belongs to another account.
          </p>
        </section>
      </div>
    </>
  );
}
