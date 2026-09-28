/**
 * The newest copy of a report kept in this browser until the server has it, so a failed save
 * or a closed tab does not lose the latest edits. `base` is the server's `updated_at` the copy
 * was made from: it only wins over the server file while the server has not moved since.
 */
export type ReportDraft = {
  reportId: number;
  bytes: Uint8Array;
  title: string;
  base: string | null;
  savedAt: number;
};

const DB_NAME = "hoc-report-drafts";
const STORE = "drafts";

let database: Promise<IDBDatabase> | null = null;

function openDatabase() {
  database ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      request.result.createObjectStore(STORE, { keyPath: "reportId" });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error("draft store"));
  });
  return database;
}

function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>) {
  return openDatabase().then((db) => new Promise<T>((resolve, reject) => {
    const request = action(db.transaction(STORE, mode).objectStore(STORE));
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error("draft store"));
  }));
}

export async function readReportDraft(reportId: number): Promise<ReportDraft | null> {
  try {
    const row = await run<ReportDraft | undefined>("readonly", (store) => store.get(reportId));
    return row?.bytes ? row : null;
  } catch {
    return null;
  }
}

export async function writeReportDraft(draft: ReportDraft) {
  try {
    await run("readwrite", (store) => store.put(draft));
  } catch {
    // Private windows may refuse IndexedDB; the server save still runs.
  }
}

export async function clearReportDraft(reportId: number) {
  try {
    await run("readwrite", (store) => store.delete(reportId));
  } catch {
    // Nothing to clear.
  }
}
