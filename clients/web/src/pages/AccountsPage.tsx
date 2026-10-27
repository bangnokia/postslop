import { useState } from "react";
import { api, PLATFORMS, PLATFORM_NAMES, storedToken, type Account, type Accounts, type Platform } from "../api";
import { Icon } from "../components/icons";
import { when } from "../compose";
import { PageHeader } from "../shell";
import { PlatformIcon, type AccountState } from "../ui";

type State = "checking" | "ready" | "attention" | "off" | "missing";

const STATE_LABELS: Record<State, string> = { checking: "Checking", ready: "Ready", attention: "Needs attention", off: "Off", missing: "Not connected" };

function stateOf(account?: Account): State {
  if (!account) return "checking";
  if (!account.connected) return "missing";
  if (!account.enabled) return "off";
  return account.available ? "ready" : "attention";
}

// The command that imports a site's session from this browser's machine into this server.
function setupCommand(platform?: Platform) {
  const site = platform ? ` ${platform}` : "";
  const local = ["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname);
  if (local) return `composer accounts:import --${site}`;
  return `cpx bangnokia/postslop setup${site} --to=${window.location.origin}${storedToken() ? " --token=YOUR_TOKEN" : ""}`;
}

export function AccountsPage({ accounts }: { accounts: AccountState }) {
  const [busy, setBusy] = useState<Platform>();
  const [checking, setChecking] = useState<Set<Platform>>(new Set());
  const [error, setError] = useState<string>();
  const changing = accounts.checking || busy !== undefined || checking.size > 0;

  async function check(id: Platform) {
    setChecking((current) => new Set(current).add(id));
    setError(undefined);
    try {
      accounts.replaceAccount(await api.checkAccount(id));
    } catch (reason) {
      setError(`${PLATFORM_NAMES[id]}: ${(reason as Error).message}`);
    } finally {
      setChecking((current) => {
        const next = new Set(current);
        next.delete(id);
        return next;
      });
    }
  }

  async function change(id: Platform, action: () => Promise<Accounts>) {
    setBusy(id);
    setError(undefined);
    try {
      accounts.replace(await action());
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(undefined);
    }
  }

  return (
    <>
      <PageHeader title="Accounts">
        <button type="button" className="btn btn-secondary" disabled={changing} onClick={accounts.refresh}>
          <Icon name="refresh" /> {accounts.checking ? "Checking…" : "Check all"}
        </button>
      </PageHeader>
      <div className="page-body page-body-narrow">
        {(accounts.error || error) && <p className="error-text">{error ?? accounts.error}</p>}
        <ul className="account-grid">
          {PLATFORMS.map((id) => {
            const account = accounts.accounts?.platforms.find((item) => item.id === id);
            const state = stateOf(account);
            return (
              <li key={id} className="card account-card" data-state={state}>
                <div className="account-head">
                  <PlatformIcon platform={id} size={28} />
                  <div>
                    <strong>{PLATFORM_NAMES[id]}</strong>
                    <span className="muted">{account?.handle ? `@${account.handle.replace(/^@/, "")}` : account ? "No account" : "Checking…"}</span>
                  </div>
                  {account?.connected ? (
                    <label className="switch" title={account.enabled ? "Turn off" : "Turn on"}>
                      <input type="checkbox" role="switch" checked={account.enabled} disabled={changing} onChange={(event) => change(id, () => api.setAccount(id, event.target.checked))} />
                      <span className="visually-hidden">Use {PLATFORM_NAMES[id]}</span>
                    </label>
                  ) : null}
                </div>
                <span className="account-state" data-state={state}>
                  <span className="status-dot" data-state={state} />
                  {STATE_LABELS[state]}
                </span>
                {state === "attention" && <p className="account-reason">{account?.reason ?? "Session unavailable."}</p>}
                {state === "off" && <p className="account-reason">Turned off: not offered in the composer and never published to. Its session is kept.</p>}
                {state === "missing" && (
                  <div className="account-reason">
                    Sign in to {PLATFORM_NAMES[id]} in Chrome or Firefox, then run:
                    <pre>
                      <code>{setupCommand(id)}</code>
                    </pre>
                  </div>
                )}
                {account?.detail && state === "attention" && (
                  <details className="account-detail">
                    <summary>Check details</summary>
                    <code>{account.detail}</code>
                  </details>
                )}
                {account?.connected && (
                  <dl className="account-session">
                    {account.importedAt && <div><dt>Session imported</dt><dd>{when(account.importedAt)}{account.source ? ` from ${account.source}` : ""}</dd></div>}
                    {account.checkedAt && <div><dt>Last checked</dt><dd>{when(account.checkedAt)}</dd></div>}
                  </dl>
                )}
                {state === "attention" && (
                  <details className="account-reason">
                    <summary>Refresh browser session</summary>
                    <p>Sign in as {account?.handle ? `@${account.handle.replace(/^@/, "")}` : "your intended account"} in Chrome or Firefox, then run on your computer:</p>
                    <pre><code>{setupCommand(id)}</code></pre>
                  </details>
                )}
                {account?.connected && (
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
                {account?.connected && (
                  <div className="account-actions">
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      disabled={accounts.checking || busy !== undefined || checking.has(id) || !account.enabled}
                      aria-label={`Check ${PLATFORM_NAMES[id]} account`}
                      title={account.enabled ? "Check only this account" : "Turn on this account to check it"}
                      onClick={() => check(id)}
                    >
                      <Icon name="refresh" size={14} /> {checking.has(id) ? "Checking…" : "Check again"}
                    </button>
                    <button
                      type="button"
                      className="btn btn-ghost btn-sm btn-danger"
                      disabled={changing}
                      onClick={() => {
                        if (window.confirm(`Remove ${PLATFORM_NAMES[id]}${account.handle ? ` @${account.handle}` : ""}? Its saved session is deleted; its posts stay in the history.`)) change(id, () => api.removeAccount(id));
                      }}
                    >
                      <Icon name="trash" size={14} /> Remove
                    </button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        <section className="card setup-card">
          <h2>Connect or refresh accounts</h2>
          <p>
            Postslop uses the sessions you are already signed in with in Chrome or Firefox. It needs no API keys. Postslop holds one account per site. Check which account is signed in on each site, then run on your computer:
          </p>
          <pre>
            <code>{setupCommand()}</code>
          </pre>
          <p className="muted">
            Name a site, such as <code>setup x</code>, to import only that one. It reads Chrome (add <code>--browser=firefox</code> for Firefox), asks for each handle, and refuses a session that belongs to another account.
            {!["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname)
              ? <> The client needs cpx and PHP with curl, OpenSSL, PDO SQLite and iconv. No Python or Node is needed. With <code>--to</code> the sessions go straight to this server instead of being saved on your computer.</>
              : <> Run the Composer command from the repository root.</>}
          </p>
        </section>
      </div>
    </>
  );
}
