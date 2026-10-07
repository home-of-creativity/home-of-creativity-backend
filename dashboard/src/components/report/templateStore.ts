/**
 * Word files staff keep as templates. They live in this browser, show on the template
 * picker, and opening one copies the file into a new report.
 */

export type SavedReportTemplate = {
  id: string;
  name: string;
  bytes: ArrayBuffer;
  savedAt: number;
};

const DB_NAME = "hoc-report-templates";
const STORE = "templates";

let database: Promise<IDBDatabase> | null = null;

function openDatabase() {
  database ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      request.result.createObjectStore(STORE, { keyPath: "id" });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error("template store"));
  });
  return database;
}

function readRows(): Promise<SavedReportTemplate[]> {
  return openDatabase().then((db) => new Promise((resolve, reject) => {
    const request = db.transaction(STORE, "readonly").objectStore(STORE).getAll();
    request.onsuccess = () => resolve((request.result as SavedReportTemplate[]).filter((row) => row?.id && row.name && row.bytes));
    request.onerror = () => reject(request.error ?? new Error("template store"));
  }));
}

function byNewest(rows: SavedReportTemplate[]) {
  return rows.slice().sort((a, b) => b.savedAt - a.savedAt);
}

export async function loadSavedTemplates(): Promise<SavedReportTemplate[]> {
  try {
    return byNewest(await readRows());
  } catch {
    return [];
  }
}

/** Keep this Word file under `name`. The same name replaces the earlier copy. */
export async function rememberReportTemplate(name: string, bytes: Uint8Array): Promise<SavedReportTemplate[]> {
  const title = name.trim().slice(0, 80);
  const rows = await readRows();
  const existing = rows.find((row) => row.name === title);
  const copy = new ArrayBuffer(bytes.byteLength);
  new Uint8Array(copy).set(bytes);
  const row: SavedReportTemplate = {
    id: existing?.id ?? crypto.randomUUID(),
    name: title,
    bytes: copy,
    savedAt: Date.now(),
  };
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const request = db.transaction(STORE, "readwrite").objectStore(STORE).put(row);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error ?? new Error("template store"));
  });
  return byNewest([row, ...rows.filter((item) => item.id !== row.id)]).slice(0, 24);
}

export async function forgetReportTemplate(id: string): Promise<SavedReportTemplate[]> {
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const request = db.transaction(STORE, "readwrite").objectStore(STORE).delete(id);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error ?? new Error("template store"));
  });
  return byNewest((await readRows()).filter((row) => row.id !== id));
}
