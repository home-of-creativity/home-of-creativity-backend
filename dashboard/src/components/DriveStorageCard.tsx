import { useEffect, useState } from "react";
import { CheckCircle2, Copy, HardDrive, TriangleAlert } from "lucide-react";
import { toast } from "sonner";
import { api, type DriveStorageAccount } from "../api";
import { copy } from "../i18n";
import { ConfirmAction } from "./ConfirmAction";

type T = (c: { ar: string; en: string }) => string;

function fill(template: string, values: Record<string, string>) {
  return template.replace(/\{(\w+)\}/g, (_, key: string) => values[key] ?? "");
}

function size(bytes: number) {
  const gb = bytes / 1024 ** 3;
  return gb >= 1 ? `${gb.toFixed(1)} GB` : `${Math.max(0, Math.round(bytes / 1024 ** 2))} MB`;
}

/**
 * Which Google account's storage holds the report files uploaded to Drive, and the one-time
 * setup to connect it (the service account that owns the folders has no storage).
 */
export function DriveStorageCard({ t }: { t: T }) {
  const [status, setStatus] = useState<DriveStorageAccount | null>(null);
  const [editing, setEditing] = useState(false);
  const [clientId, setClientId] = useState("");
  const [secret, setSecret] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api.driveStorageAccount()
      .then((res) => {
        if (!cancelled) setStatus(res.data);
      })
      .catch((err) => {
        if (!cancelled) toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      });
    return () => {
      cancelled = true;
    };
  }, [t]);

  async function connect() {
    setBusy(true);
    try {
      const res = await api.connectDriveStorage();
      window.location.assign(res.data.authorize_url);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      setBusy(false);
    }
  }

  async function saveClient() {
    setBusy(true);
    try {
      const res = await api.saveDriveStorageClient({ client_id: clientId.trim(), client_secret: secret.trim() });
      setStatus(res.data);
      setEditing(false);
      setSecret("");
      toast.success(t(copy.saveSuccess));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function disconnect() {
    setBusy(true);
    try {
      setStatus((await api.disconnectDriveStorage()).data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  if (!status) {
    return <section className="card drive-storage" aria-busy="true"><p className="muted">{t(copy.loading)}</p></section>;
  }

  const setup = !status.client_from_env && (!status.oauth_configured || editing);
  const healthy = status.connected && status.working;

  return (
    <section className="card drive-storage" aria-labelledby="drive-storage-title">
      <header className="drive-storage-head">
        <HardDrive size={18} aria-hidden="true" />
        <h2 id="drive-storage-title">{t(copy.driveStorageTitle)}</h2>
      </header>

      {healthy ? (
        <div className="drive-storage-state is-ok">
          <CheckCircle2 size={16} aria-hidden="true" />
          <div>
            <p>{fill(t(copy.driveStorageConnected), { email: status.email ?? "" })}</p>
            {status.storage ? (
              <p className="muted">
                {status.storage.limit
                  ? fill(t(copy.driveStorageUsage), { used: size(status.storage.usage), limit: size(status.storage.limit) })
                  : fill(t(copy.driveStorageUsageOnly), { used: size(status.storage.usage) })}
              </p>
            ) : null}
          </div>
        </div>
      ) : (
        <p className="muted">{t(copy.driveStorageLede)}</p>
      )}

      {status.connected && !status.working ? (
        <p className="drive-storage-state is-warn" role="alert">
          <TriangleAlert size={16} aria-hidden="true" />
          <span>{status.email ? `${status.email}: ` : ""}{status.error}</span>
        </p>
      ) : null}

      {setup ? (
        <form
          className="drive-storage-setup"
          onSubmit={(event) => {
            event.preventDefault();
            void saveClient();
          }}
        >
          <h3>{t(copy.driveStorageSetupTitle)}</h3>
          <p className="muted">{t(copy.driveStorageSetupSteps)}</p>
          <label className="field-label">
            {t(copy.driveStorageRedirect)}
            <span className="drive-storage-copy">
              <input className="field" dir="ltr" readOnly value={status.redirect_uri} onFocus={(event) => event.currentTarget.select()} />
              <button
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => {
                  void navigator.clipboard?.writeText(status.redirect_uri).then(() => toast.success(t(copy.driveStorageCopied)));
                }}
              >
                <Copy size={14} aria-hidden="true" />{t(copy.driveStorageCopy)}
              </button>
            </span>
          </label>
          <label className="field-label">
            {t(copy.driveStorageClientId)}
            <input className="field" dir="ltr" required autoComplete="off" value={clientId} placeholder="….apps.googleusercontent.com" onChange={(event) => setClientId(event.target.value)} />
          </label>
          <label className="field-label">
            {t(copy.driveStorageClientSecret)}
            <input className="field" dir="ltr" type="password" required autoComplete="new-password" value={secret} onChange={(event) => setSecret(event.target.value)} />
          </label>
          <p className="muted">{t(copy.driveStorageSetupConsent)}</p>
          <div className="row-actions">
            <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>{t(copy.driveStorageSaveClient)}</button>
            {editing ? <button type="button" className="btn btn-ghost btn-sm" onClick={() => setEditing(false)}>{t(copy.cancel)}</button> : null}
          </div>
        </form>
      ) : (
        <div className="row-actions">
          <button type="button" className={`btn btn-sm${healthy ? "" : " btn-primary"}`} disabled={busy || !status.oauth_configured} onClick={() => void connect()}>
            {status.connected ? t(copy.driveStorageReconnect) : t(copy.driveStorageConnect)}
          </button>
          {status.connected ? (
            <ConfirmAction
              label={t(copy.driveStorageDisconnect)}
              confirmLabel={t(copy.driveStorageDisconnectConfirm)}
              yesLabel={t(copy.driveStorageDisconnect)}
              noLabel={t(copy.cancel)}
              className="btn btn-ghost btn-sm"
              onConfirm={() => void disconnect()}
            />
          ) : null}
          {status.client_from_env ? (
            <span className="muted">{t(copy.driveStorageClientFromEnv)}</span>
          ) : (
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setClientId(status.client_id ?? ""); setEditing(true); }}>
              {t(copy.driveStorageEditClient)}
            </button>
          )}
        </div>
      )}
      {!healthy && !setup ? <p className="muted">{t(copy.driveStorageChooseHint)}</p> : null}
    </section>
  );
}
