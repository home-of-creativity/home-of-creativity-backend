import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type Client, type DriveFolder } from "../api";
import { copy } from "../i18n";

const ROOT = "root";

function folderIdFrom(value: string): string | null {
  const text = value.trim();
  const match = text.match(/folders\/([a-zA-Z0-9_-]+)/);
  if (match) return match[1];
  if (/^[a-zA-Z0-9_-]{10,}$/.test(text)) return text;
  return null;
}

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
  const [name, setName] = useState("");
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<DriveFolder[] | null>(null);
  const [searchNext, setSearchNext] = useState<string | null>(null);
  const [searching, setSearching] = useState(false);
  const [link, setLink] = useState("");
  const [currentName, setCurrentName] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const term = query.trim();
  const showSearch = term.length >= 2;

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

  useEffect(() => {
    const id = client.google_drive_folder_id;
    if (!id) {
      setCurrentName(null);
      return;
    }
    let cancelled = false;
    api.driveFolder(id)
      .then((res) => {
        if (!cancelled) setCurrentName(res.data[0]?.name ?? null);
      })
      .catch(() => {
        if (!cancelled) setCurrentName(null);
      });
    return () => {
      cancelled = true;
    };
  }, [client.google_drive_folder_id]);

  useEffect(() => {
    if (!showSearch) {
      setResults(null);
      setSearchNext(null);
      setSearching(false);
      return;
    }
    setResults(null);
    setSearchNext(null);
    setSearching(true);
    let cancelled = false;
    const timer = window.setTimeout(() => {
      api.driveFolders(undefined, undefined, term)
        .then((res) => {
          if (!cancelled) {
            setResults(res.data);
            setSearchNext(res.meta.next_page_token);
          }
        })
        .catch((err) => {
          if (!cancelled) setResults([]);
          toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
        })
        .finally(() => {
          if (!cancelled) setSearching(false);
        });
    }, 350);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [showSearch, term]);

  function toggle(folder: DriveFolder) {
    setSelected(folder);
    setOpen((current) => {
      const next = !current[folder.id];
      if (next && !nodes[folder.id]?.loaded) load(folder.id);
      return { ...current, [folder.id]: next };
    });
  }

  function moreSearch() {
    if (!searchNext) return;
    const token = searchNext;
    setSearching(true);
    api.driveFolders(undefined, token, term)
      .then((res) => {
        setResults((current) => [...(current ?? []), ...res.data]);
        setSearchNext(res.meta.next_page_token);
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setSearching(false));
  }

  function holdLink() {
    const id = folderIdFrom(link);
    if (!id) {
      toast.error(t(copy.driveLinkInvalid));
      return;
    }
    setSelected({ id, name: link.trim() });
  }

  function createHere() {
    const folderName = name.trim();
    if (!selected || folderName === "") return;
    const parent = selected.id;
    setBusy(true);
    api.createDriveFolder(folderName, parent)
      .then((res) => api.assignClientDriveFolder(client.id, { mode: "existing", folder: res.data.id }))
      .then((res) => {
        toast.success(t(copy.driveFolderCreated));
        onSaved(res.data);
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
      <h2 className="section-title">{t(copy.driveFolder)}</h2>
      <div className="drive-current field-span">
        <span className="drive-current-label">{t(copy.driveCurrent)}</span>
        {client.google_drive_folder_id ? (
          <>
            <strong className="drive-name">{currentName || t(copy.driveFolderExisting)}</strong>
            {client.google_drive_folder_url ? (
              <a href={client.google_drive_folder_url} target="_blank" rel="noreferrer">{t(copy.driveOpenFolder)}</a>
            ) : null}
          </>
        ) : (
          <span>{t(copy.driveNoFolderAssigned)}</span>
        )}
      </div>
      <label className="field-label field-span">
        {t(copy.driveSearch)}
        <input
          className="field"
          value={query}
          placeholder={t(copy.driveSearchHint)}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") event.preventDefault();
          }}
        />
      </label>
      {showSearch ? (
        <ul className="drive-search-results field-span" aria-label={t(copy.driveSearch)}>
          {results === null ? <li className="drive-status">{t(copy.driveSearching)}</li> : null}
          {results?.length === 0 ? <li className="drive-status">{t(copy.driveNoMatches)}</li> : null}
          {results?.map((folder) => (
            <li key={folder.id}>
              <div className={`drive-row${selected?.id === folder.id ? " is-selected" : ""}`}>
                <button type="button" className="drive-toggle" onClick={() => setSelected(folder)}>
                  <span className="drive-name">{folder.name}</span>
                </button>
              </div>
            </li>
          ))}
          {searchNext ? (
            <li>
              <button type="button" className="btn btn-ghost" disabled={busy || searching} onClick={moreSearch}>{t(copy.driveMore)}</button>
            </li>
          ) : null}
        </ul>
      ) : (
        <ul className="drive-tree field-span" role="tree" aria-label={t(copy.driveRoot)}>
          <Branch folderKey={ROOT} depth={0} />
          {root?.next ? (
            <li>
              <button type="button" className="btn btn-ghost" disabled={busy || root.loading} onClick={() => load(ROOT, root.next ?? undefined)}>{t(copy.driveMore)}</button>
            </li>
          ) : null}
        </ul>
      )}
      <p className="drive-target field-span">
        {selected ? `${t(copy.driveInside)} ${selected.name}` : t(copy.driveRoot)}
      </p>
      <label className="field-label field-span">
        {t(copy.drivePaste)}
        <span className="drive-paste">
          <input
            className="field"
            value={link}
            placeholder="https://drive.google.com/drive/folders/…"
            onChange={(event) => setLink(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                holdLink();
              }
            }}
          />
          <button type="button" className="btn" disabled={busy || link.trim() === ""} onClick={holdLink}>{t(copy.driveUseLink)}</button>
        </span>
      </label>
      <label className="field-label field-span">
        {t(copy.driveFolderName)}
        <input
          className="field"
          value={name}
          placeholder={client.company_name || client.name}
          onChange={(event) => setName(event.target.value)}
          required
        />
      </label>
      <p className="drive-target field-span">{t(copy.driveNameThenCreate)}</p>
      <div className="row-actions field-span">
        <button className="btn btn-primary" type="submit" disabled={busy || !selected || name.trim() === ""}>{t(copy.driveCreateAndUse)}</button>
        <button className="btn" type="button" onClick={onClose}>{t(copy.cancel)}</button>
      </div>
    </form>
  );
}
