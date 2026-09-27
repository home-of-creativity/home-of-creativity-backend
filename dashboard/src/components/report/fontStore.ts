export type StoredReportFont = { family: string; url: string };

type FontRow = { family: string; bytes: ArrayBuffer };

const DB_NAME = "hoc-report-fonts";
const STORE = "fonts";

let faces: StoredReportFont[] | null = null;
let database: Promise<IDBDatabase> | null = null;

function openDatabase() {
  database ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      request.result.createObjectStore(STORE, { keyPath: "family" });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error("font store"));
  });
  return database;
}

function readRows(): Promise<FontRow[]> {
  return openDatabase().then((db) => new Promise((resolve, reject) => {
    const request = db.transaction(STORE, "readonly").objectStore(STORE).getAll();
    request.onsuccess = () => resolve((request.result as FontRow[]).filter((row) => row?.family && row.bytes));
    request.onerror = () => reject(request.error ?? new Error("font store"));
  }));
}

function faceFromRow(row: FontRow): StoredReportFont {
  const copy = row.bytes.slice(0);
  return { family: row.family, url: URL.createObjectURL(new Blob([copy])) };
}

/** Faces already loaded this session. Empty until `loadReportFonts` finishes. */
export function cachedReportFontFamilies() {
  return (faces ?? []).map((font) => font.family);
}

export async function loadReportFonts(): Promise<StoredReportFont[]> {
  if (faces) return faces;
  try {
    const rows = await readRows();
    faces = rows
      .sort((a, b) => a.family.localeCompare(b.family))
      .map(faceFromRow);
  } catch {
    faces = [];
  }
  return faces;
}

export async function rememberReportFont(family: string, bytes: ArrayBuffer): Promise<StoredReportFont[]> {
  const name = family.trim().slice(0, 80);
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const request = db.transaction(STORE, "readwrite").objectStore(STORE).put({ family: name, bytes });
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error ?? new Error("font store"));
  });
  const previous = faces?.find((font) => font.family === name);
  if (previous) URL.revokeObjectURL(previous.url);
  const next = faceFromRow({ family: name, bytes });
  faces = [next, ...(faces ?? []).filter((font) => font.family !== name)].slice(0, 6);
  return faces;
}
