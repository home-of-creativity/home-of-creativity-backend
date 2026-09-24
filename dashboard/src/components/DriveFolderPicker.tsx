import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type Client, type DriveFolder } from "../api";
import { copy } from "../i18n";

type Crumb = { id: string; name: string };

export function DriveFolderPicker({
  client,
  t,
  onSaved,
  onClose,
}: {
  client: Client;
  t: (c: { ar: string; en: string }) => string;
  onSaved: (client: Client) => void;
  onClose: () => void;
}) {
  const [crumbs, setCrumbs] = useState<Crumb[]>([]);
  const [folders, setFolders] = useState<DriveFolder[]>([]);
  const [nextToken, setNextToken] = useState<string | null>(null);
  const [name, setName] = useState(client.company_name || client.name);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const parent = crumbs[crumbs.length - 1]?.id;

  useEffect(() => {
    setLoading(true);
    api.driveFolders(parent)
      .then((res) => {
        setFolders(res.data);
        setNextToken(res.meta.next_page_token);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [parent, t]);

  function choose(folderId: string) {
    setBusy(true);
    api.assignClientDriveFolder(client.id, { mode: "existing", folder: folderId })
      .then((res) => {
        toast.success(t(copy.saveSuccess));
        onSaved(res.data);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setBusy(false));
  }

  function createHere() {
    const folderName = name.trim();
    if (folderName === "") return;
    setBusy(true);
    api.createDriveFolder(folderName, parent)
      .then((res) => {
        toast.success(t(copy.driveFolderCreated));
        setName("");
        setCrumbs([...crumbs, { id: res.data.id, name: res.data.name }]);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setBusy(false));
  }

  function loadMore() {
    if (!nextToken) return;
    setBusy(true);
    api.driveFolders(parent, nextToken)
      .then((res) => {
        setFolders((current) => [...current, ...res.data]);
        setNextToken(res.meta.next_page_token);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setBusy(false));
  }

  return (
    <form
      className="card form-grid"
      onSubmit={(event) => {
        event.preventDefault();
        createHere();
      }}
    >
      <h2 className="section-title">{client.company_name || client.name}</h2>
      <p className="field-span">
        <button type="button" className="btn btn-ghost" onClick={() => setCrumbs([])}>{t(copy.driveRoot)}</button>
        {crumbs.map((crumb, index) => (
          <button key={crumb.id} type="button" className="btn btn-ghost" onClick={() => setCrumbs(crumbs.slice(0, index + 1))}>
            {crumb.name}
          </button>
        ))}
      </p>
      <ul className="plain-list field-span">
        {loading ? <li>{t(copy.loading)}</li> : null}
        {!loading && folders.length === 0 ? <li>{t(copy.driveNoFolders)}</li> : null}
        {folders.map((folder) => (
          <li key={folder.id} className="row-actions">
            <span>{folder.name}</span>
            <button type="button" className="btn btn-ghost" onClick={() => setCrumbs([...crumbs, folder])}>{t(copy.driveOpen)}</button>
            <button type="button" className="btn" disabled={busy} onClick={() => choose(folder.id)}>{t(copy.driveChoose)}</button>
          </li>
        ))}
      </ul>
      {nextToken ? (
        <button type="button" className="btn field-span" disabled={busy} onClick={loadMore}>{t(copy.driveMore)}</button>
      ) : null}
      <label className="field-label field-span">
        {t(copy.driveFolderName)}
        <input className="field" value={name} onChange={(event) => setName(event.target.value)} required />
      </label>
      <div className="row-actions field-span">
        <button className="btn btn-primary" type="submit" disabled={busy}>{t(copy.driveCreateHere)}</button>
        {parent ? (
          <button type="button" className="btn" disabled={busy} onClick={() => choose(parent)}>{t(copy.driveChooseCurrent)}</button>
        ) : null}
        <button className="btn" type="button" onClick={onClose}>{t(copy.cancel)}</button>
      </div>
    </form>
  );
}
