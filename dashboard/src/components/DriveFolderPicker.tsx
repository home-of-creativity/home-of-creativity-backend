import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type Client, type DriveFolder } from "../api";
import { copy } from "../i18n";

const ROOT = "root";

type NodeState = {
  folders: DriveFolder[];
  next: string | null;
  loading: boolean;
  loaded: boolean;
};

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
  const [nodes, setNodes] = useState<Record<string, NodeState>>({});
  const [open, setOpen] = useState<Record<string, boolean>>({ [ROOT]: true });
  const [selected, setSelected] = useState<DriveFolder | null>(null);
  const [name, setName] = useState(client.company_name || client.name);
  const [busy, setBusy] = useState(false);

  function remember(key: string, patch: Partial<NodeState>) {
    setNodes((current) => ({
      ...current,
      [key]: {
        folders: current[key]?.folders ?? [],
        next: current[key]?.next ?? null,
        loading: current[key]?.loading ?? false,
        loaded: current[key]?.loaded ?? false,
        ...patch,
      },
    }));
  }

  function load(key: string, pageToken?: string) {
    remember(key, { loading: true });
    api.driveFolders(key === ROOT ? undefined : key, pageToken)
      .then((res) => {
        setNodes((current) => {
          const previous = current[key]?.folders ?? [];
          return {
            ...current,
            [key]: {
              folders: pageToken ? [...previous, ...res.data] : res.data,
              next: res.meta.next_page_token,
              loading: false,
              loaded: true,
            },
          };
        });
      })
      .catch((err) => {
        remember(key, { loading: false, loaded: true });
        toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      });
  }

  useEffect(() => {
    load(ROOT);
    // The root list loads once when the picker opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function toggle(folder: DriveFolder) {
    setSelected(folder);
    setOpen((current) => {
      const next = !current[folder.id];
      if (next && !nodes[folder.id]?.loaded) load(folder.id);
      return { ...current, [folder.id]: next };
    });
  }

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
    const parent = selected?.id;
    setBusy(true);
    api.createDriveFolder(folderName, parent)
      .then((res) => {
        toast.success(t(copy.driveFolderCreated));
        setName("");
        const key = parent ?? ROOT;
        setOpen((current) => ({ ...current, [key]: true, [res.data.id]: false }));
        setSelected(res.data);
        load(key);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setBusy(false));
  }

  function Branch({ folderKey, depth }: { folderKey: string; depth: number }) {
    const node = nodes[folderKey];
    if (!node?.loaded && node?.loading) {
      return <li className="drive-status">{t(copy.loading)}</li>;
    }
    if (!node || node.folders.length === 0) {
      return node?.loaded ? <li className="drive-status">{t(copy.driveNoFolders)}</li> : null;
    }
    return (
      <>
        {node.folders.map((folder) => {
          const expanded = open[folder.id] === true;
          return (
            <li key={folder.id} role="treeitem" aria-expanded={expanded} aria-selected={selected?.id === folder.id}>
              <div className={`drive-row${selected?.id === folder.id ? " is-selected" : ""}`} style={{ paddingInlineStart: `${depth * 1.15}rem` }}>
                <button
                  type="button"
                  className={`drive-toggle${expanded ? " is-open" : ""}`}
                  aria-label={expanded ? t(copy.driveCollapse) : t(copy.driveOpen)}
                  onClick={() => toggle(folder)}
                >
                  <span className="drive-chevron" aria-hidden="true" />
                  <span className="drive-name">{folder.name}</span>
                </button>
                <button type="button" className="btn" disabled={busy} onClick={() => choose(folder.id)}>{t(copy.driveChoose)}</button>
              </div>
              {expanded ? (
                <ul className="drive-children" role="group">
                  <Branch folderKey={folder.id} depth={depth + 1} />
                  {nodes[folder.id]?.next ? (
                    <li>
                      <button type="button" className="btn btn-ghost" disabled={busy || nodes[folder.id]?.loading} onClick={() => load(folder.id, nodes[folder.id]?.next ?? undefined)}>
                        {t(copy.driveMore)}
                      </button>
                    </li>
                  ) : null}
                </ul>
              ) : null}
            </li>
          );
        })}
      </>
    );
  }

  const root = nodes[ROOT];

  return (
    <form
      className="card form-grid drive-picker"
      onSubmit={(event) => {
        event.preventDefault();
        createHere();
      }}
    >
      <h2 className="section-title">{client.company_name || client.name}</h2>
      <p className="drive-target field-span">
        {selected ? `${t(copy.driveInside)} ${selected.name}` : t(copy.driveRoot)}
      </p>
      <ul className="drive-tree field-span" role="tree" aria-label={t(copy.driveRoot)}>
        <Branch folderKey={ROOT} depth={0} />
        {root?.next ? (
          <li>
            <button type="button" className="btn btn-ghost" disabled={busy || root.loading} onClick={() => load(ROOT, root.next ?? undefined)}>{t(copy.driveMore)}</button>
          </li>
        ) : null}
      </ul>
      <label className="field-label field-span">
        {t(copy.driveFolderName)}
        <input className="field" value={name} onChange={(event) => setName(event.target.value)} required />
      </label>
      <div className="row-actions field-span">
        <button className="btn btn-primary" type="submit" disabled={busy}>{t(copy.driveCreateHere)}</button>
        {selected ? (
          <button type="button" className="btn" disabled={busy} onClick={() => choose(selected.id)}>{t(copy.driveChooseCurrent)}</button>
        ) : null}
        <button className="btn" type="button" onClick={onClose}>{t(copy.cancel)}</button>
      </div>
    </form>
  );
}
