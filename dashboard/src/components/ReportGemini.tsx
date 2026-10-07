import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api } from "../api";
import { copy } from "../i18n";
import type { ImagePlacement } from "./report/ReportDocEditor";
import type { TextBoxShape } from "./report/pageObjects";

type Memory = { id: number; body: string };

function asMemories(value: unknown): Memory[] {
  if (!Array.isArray(value)) return [];
  return value.filter((item): item is Memory => {
    if (item === null || typeof item !== "object") return false;
    const row = item as { id?: unknown; body?: unknown };
    return typeof row.id === "number" && typeof row.body === "string";
  });
}
type Scope = "selection" | "write" | "document";

async function rasterToPng(bytes: Uint8Array, mime: string) {
  const copy = new ArrayBuffer(bytes.byteLength);
  new Uint8Array(copy).set(bytes);
  const blob = new Blob([copy], { type: mime });
  const bitmap = await createImageBitmap(blob);
  const canvas = document.createElement("canvas");
  canvas.width = bitmap.width;
  canvas.height = bitmap.height;
  const context = canvas.getContext("2d");
  if (!context) return bytes;
  context.drawImage(bitmap, 0, 0);
  const png = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/png"));
  return png ? new Uint8Array(await png.arrayBuffer()) : bytes;
}

function escapeHtml(text: string) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function inlineText(element: HTMLElement) {
  let text = "";
  element.childNodes.forEach((node) => {
    if (node.nodeType === Node.TEXT_NODE) text += node.textContent ?? "";
    else if (node instanceof HTMLElement && node.tagName === "BR") text += "\n";
    else if (node instanceof HTMLElement) text += inlineText(node);
  });
  return text.replace(/\u00a0/g, " ");
}

function renderList(list: HTMLElement, indent: string): string[] {
  const ordered = list.tagName === "OL";
  let number = Number(list.getAttribute("start") || "1") || 1;
  const lines: string[] = [];
  Array.from(list.children).forEach((child) => {
    if (!(child instanceof HTMLElement) || child.tagName !== "LI") return;
    const copy = child.cloneNode(true) as HTMLElement;
    const nested = Array.from(copy.querySelectorAll(":scope > ol, :scope > ul"));
    nested.forEach((node) => node.remove());
    const body = inlineText(copy).replace(/[ \t]+$/gm, "").trim();
    const marker = ordered ? `${number}. ` : "• ";
    if (ordered) number += 1;
    body.split("\n").forEach((line, index) => {
      lines.push(index === 0 ? `${indent}${marker}${line}` : `${indent}${" ".repeat(marker.length)}${line}`);
    });
    nested.forEach((node) => {
      if (node instanceof HTMLElement) lines.push(...renderList(node, `${indent}  `));
    });
  });
  return lines;
}

/** Keep blank lines and the visible list numbers when leaving HTML. */
function htmlToText(html: string) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body;

  function blocks(parent: ParentNode): string[] {
    const lines: string[] = [];
    parent.childNodes.forEach((node) => {
      if (!(node instanceof HTMLElement)) return;
      const tag = node.tagName;
      if (tag === "OL" || tag === "UL") {
        lines.push(...renderList(node, ""));
        return;
      }
      if (tag === "BR") {
        lines.push("");
        return;
      }
      if (tag === "DIV" || tag === "SECTION") {
        lines.push(...blocks(node));
        return;
      }
      if (["P", "H1", "H2", "H3", "H4", "BLOCKQUOTE"].includes(tag)) {
        lines.push(inlineText(node).replace(/[ \t]+$/gm, "").replace(/^[ \t]+/gm, ""));
      }
    });
    return lines;
  }

  return blocks(root).join("\n");
}

type ChatImage = { mime: string; base64: string; preview: string };

async function fileToChatImage(file: File): Promise<ChatImage> {
  const bitmap = await createImageBitmap(file);
  const maxEdge = 1280;
  const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  const context = canvas.getContext("2d");
  if (!context) throw new Error("image");
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.85));
  if (!blob) throw new Error("image");
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let binary = "";
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return { mime: "image/jpeg", base64: btoa(binary), preview: URL.createObjectURL(blob) };
}

export function ReportGemini({
  t,
  selectedText,
  documentText,
  pageCount,
  pageText,
  onApply,
  onApplyDocument,
  onInsertImage,
  onInsertCover,
  onInsertTextBox,
  fonts = [],
  onInstallFont,
}: {
  t: (c: { ar: string; en: string }) => string;
  /** Text currently selected in the document. */
  selectedText: () => string;
  /** Plain text of every page. */
  documentText: () => string;
  pageCount: () => number;
  pageText: (page: number) => string;
  /** Apply the new text. `page` is set when writing onto a chosen page. */
  onApply: (text: string, page: number | null) => boolean | Promise<boolean>;
  /** Replace the whole document with the new text. */
  onApplyDocument: (text: string) => boolean | Promise<boolean>;
  onInsertImage: (bytes: Uint8Array, placement: ImagePlacement) => Promise<"ok" | "unsupported" | "refused">;
  onInsertCover: (bytes: Uint8Array) => Promise<"ok" | "unsupported" | "refused">;
  onInsertTextBox: (options: { kind: TextBoxShape; text: string }) => Promise<boolean>;
  fonts: Array<{ family: string }>;
  onInstallFont: (family: string, file: File) => Promise<void>;
}) {
  const fontRows = Array.isArray(fonts) ? fonts : [];
  const [instruction, setInstruction] = useState("");
  const [scope, setScope] = useState<Scope>("selection");
  const [saveMemory, setSaveMemory] = useState(false);
  const [memoryNote, setMemoryNote] = useState("");
  const [memories, setMemories] = useState<Memory[]>([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [width, setWidth] = useState(55);
  // "wrap": the picture sits behind the text on every page except the cover. "place": it goes where the caret is.
  const [place, setPlace] = useState<"wrap" | "place">("place");
  const [imagePrompt, setImagePrompt] = useState("");
  const [boxKind, setBoxKind] = useState<TextBoxShape>("rect");
  const [draft, setDraft] = useState<{ reply: string; previous: string; next: string } | null>(null);
  const [attachment, setAttachment] = useState<ChatImage | null>(null);
  const [source, setSource] = useState<ChatImage | null>(null);
  const [fontName, setFontName] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setPages(Math.max(1, pageCount()));
  }, [pageCount, scope, busy]);

  useEffect(() => {
    api.reportMemories()
      .then((res) => setMemories(asMemories(res.data)))
      .catch(() => setMemories([]));
  }, []);

  async function ask(text = instruction) {
    const request = text.trim();
    if (request === "") return;
    const source = scope === "selection" ? selectedText() : scope === "document" ? documentText() : pageText(page);
    if (scope === "selection" && source.trim() === "") {
      toast.info(t(copy.reportGeminiSelectFirst));
      return;
    }
    if (scope === "document" && source.trim() === "") {
      toast.info(t(copy.reportGeminiDocumentEmpty));
      return;
    }
    setBusy(true);
    try {
      const body = source === ""
        ? "<p></p>"
        : source.split("\n").map((line) => (line === "" ? "<p></p>" : `<p>${escapeHtml(line)}</p>`)).join("");
      const res = await api.editReportWithGemini({
        instruction: request,
        body,
        scope: "all",
        save_memory: saveMemory,
        ...(attachment ? { image_mime: attachment.mime, image_base64: attachment.base64 } : {}),
      });
      setMemories(asMemories(res.data.memories));
      setDraft({ reply: res.data.reply, previous: source, next: htmlToText(res.data.body) });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function apply() {
    if (!draft) return;
    if (scope === "selection" && selectedText().trim() === "") {
      toast.info(t(copy.reportGeminiSelectFirst));
      return;
    }
    const applied = scope === "document"
      ? await onApplyDocument(draft.next)
      : await onApply(draft.next, scope === "write" ? page : null);
    if (applied) {
      toast.success(t(copy.reportGeminiApplied));
      setDraft(null);
    }
  }

  function placement(): ImagePlacement {
    return place === "wrap" ? { mode: "background" } : { mode: "place", widthPercent: width };
  }

  async function insertPicture(file: File | undefined) {
    if (!file) return;
    setBusy(true);
    try {
      const result = await onInsertImage(new Uint8Array(await file.arrayBuffer()), placement());
      if (result === "ok") toast.success(t(copy.reportImageInserted));
      else if (result === "unsupported") toast.error(t(copy.reportImageUnsupported));
      else toast.error(t(copy.reportImageRefused));
    } finally {
      setBusy(false);
    }
  }

  /** Runs one editor action with the panel disabled, and reports it. */
  async function run(action: () => Promise<boolean>, done: { ar: string; en: string }, failed = copy.reportPlaceFailed) {
    setBusy(true);
    try {
      if (await action()) toast.success(t(done));
      else toast.error(t(failed));
    } finally {
      setBusy(false);
    }
  }

  async function addCover(file: File | undefined) {
    if (!file) return;
    setBusy(true);
    try {
      const result = await onInsertCover(new Uint8Array(await file.arrayBuffer()));
      if (result === "ok") toast.success(t(copy.reportCoverAdded));
      else if (result === "unsupported") toast.error(t(copy.reportImageUnsupported));
      else toast.error(t(copy.reportCoverFailed));
    } finally {
      setBusy(false);
    }
  }

  async function generatePicture(text = imagePrompt) {
    const prompt = text.trim();
    if (prompt === "") return;
    setBusy(true);
    try {
      const res = await api.generateReportImage({
        prompt,
        ...(source ? { image_mime: source.mime, image_base64: source.base64 } : {}),
      });
      const binary = Uint8Array.from(atob(res.data.base64), (char) => char.charCodeAt(0));
      const png = res.data.mime === "image/png" || res.data.mime === "image/jpeg" || res.data.mime === "image/gif"
        ? binary
        : await rasterToPng(binary, res.data.mime);
      const result = await onInsertImage(png, placement());
      if (result === "ok") toast.success(t(copy.reportImageGenerated));
      else if (result === "unsupported") toast.error(t(copy.reportImageUnsupported));
      else toast.error(t(copy.reportImageRefused));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function attachSource(file: File | undefined) {
    if (!file) return;
    setBusy(true);
    try {
      const next = await fileToChatImage(file);
      setSource((current) => {
        if (current) URL.revokeObjectURL(current.preview);
        return next;
      });
    } catch {
      toast.error(t(copy.reportGeminiImageFailed));
    } finally {
      setBusy(false);
    }
  }

  function generateFromSource(instruction: string) {
    if (!source) {
      toast.info(t(copy.reportImageNeedSource));
      return;
    }
    setImagePrompt(instruction);
    void generatePicture(instruction);
  }

  async function attachPicture(file: File | undefined) {
    if (!file) return;
    setBusy(true);
    try {
      const next = await fileToChatImage(file);
      setAttachment((current) => {
        if (current) URL.revokeObjectURL(current.preview);
        return next;
      });
    } catch {
      toast.error(t(copy.reportGeminiImageFailed));
    } finally {
      setBusy(false);
    }
  }

  function detachPicture() {
    setAttachment((current) => {
      if (current) URL.revokeObjectURL(current.preview);
      return null;
    });
  }

  async function installFont(file: File | undefined) {
    if (!file) return;
    if (!/\.(ttf|otf)$/i.test(file.name)) {
      toast.error(t(copy.reportFontInvalid));
      return;
    }
    const family = (fontName.trim() || file.name.replace(/\.(ttf|otf)$/i, "").replace(/[-_]+/g, " ")).trim();
    if (family === "") return;
    setBusy(true);
    try {
      await onInstallFont(family, file);
      setFontName("");
      toast.success(t(copy.reportFontAdded));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function remember() {
    const text = memoryNote.trim();
    if (text === "") return;
    setBusy(true);
    try {
      const res = await api.saveReportMemory(text);
      setMemories(asMemories(res.data));
      setMemoryNote("");
      toast.success(t(copy.reportMemorySaved));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const boxKinds: Array<[TextBoxShape, { ar: string; en: string }]> = [
    ["rect", copy.reportShapeRect],
    ["roundRect", copy.reportShapeRoundRect],
    ["ellipse", copy.reportShapeEllipse],
  ];

  const quick = [copy.reportGeminiProofread, copy.reportGeminiFormal, copy.reportGeminiShorten, copy.reportGeminiSummary, copy.reportGeminiTranslate];

  return (
    <div className="report-gemini" aria-label={t(copy.reportGemini)}>
      <fieldset className="report-picture">
        <legend>{t(copy.reportImage)}</legend>
        <p className="muted">{t(copy.reportImageHint)}</p>
        <div className="segmented" role="radiogroup" aria-label={t(copy.reportImagePlace)}>
          <button type="button" role="radio" aria-checked={place === "wrap"} className={place === "wrap" ? "is-active" : ""} onClick={() => setPlace("wrap")}>{t(copy.reportImageWrap)}</button>
          <button type="button" role="radio" aria-checked={place === "place"} className={place === "place" ? "is-active" : ""} onClick={() => setPlace("place")}>{t(copy.reportImageInPage)}</button>
        </div>
        {place === "wrap" ? null : (
          <label className="field-label">
            {t(copy.reportImageWidth)} ({width}%)
            <input className="field" type="range" min={20} max={100} step={5} value={width} onChange={(event) => setWidth(Number(event.target.value))} />
          </label>
        )}
        <label className="btn btn-sm">
          {t(copy.reportImageInsert)}
          <input type="file" accept="image/png,image/jpeg,image/gif" hidden disabled={busy} onChange={(event) => { void insertPicture(event.target.files?.[0]); event.target.value = ""; }} />
        </label>
        {source ? (
          <div className="report-gemini-attach">
            <img src={source.preview} alt="" />
            <span>{t(copy.reportImageSourceHint)}</span>
            <button type="button" className="btn btn-sm" onClick={() => setSource((current) => { if (current) URL.revokeObjectURL(current.preview); return null; })}>{t(copy.reportGeminiDetach)}</button>
          </div>
        ) : (
          <label className="btn btn-sm">
            {t(copy.reportImageSource)}
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden disabled={busy} onChange={(event) => { void attachSource(event.target.files?.[0]); event.target.value = ""; }} />
          </label>
        )}
        <div className="chip-row">
          <button type="button" className="chip" disabled={busy} onClick={() => generateFromSource(t(copy.reportImageQualityPrompt))}>{t(copy.reportImageQuality)}</button>
          <button type="button" className="chip" disabled={busy} onClick={() => generateFromSource(t(copy.reportImageSimilarPrompt))}>{t(copy.reportImageSimilar)}</button>
        </div>
        <label className="field-label">
          {t(copy.reportImagePrompt)}
          <textarea className="field field-area" rows={2} value={imagePrompt} onChange={(event) => setImagePrompt(event.target.value)} />
        </label>
        <button type="button" className="btn btn-sm" disabled={busy || imagePrompt.trim() === ""} onClick={() => void generatePicture()}>{t(copy.reportImageGenerate)}</button>
      </fieldset>
      <fieldset className="report-picture">
        <legend>{t(copy.reportCover)}</legend>
        <p className="muted">{t(copy.reportCoverHint)}</p>
        <label className="btn btn-sm">
          {t(copy.reportCoverAdd)}
          <input type="file" accept="image/png,image/jpeg,image/gif" hidden disabled={busy} onChange={(event) => { void addCover(event.target.files?.[0]); event.target.value = ""; }} />
        </label>
      </fieldset>
      <fieldset className="report-picture">
        <legend>{t(copy.reportTextBox)}</legend>
        <p className="muted">{t(copy.reportTextBoxHint)}</p>
        <label className="field-label">
          {t(copy.reportTextBoxShape)}
          <select className="field" value={boxKind} onChange={(event) => setBoxKind(event.target.value as TextBoxShape)}>
            {boxKinds.map(([kind, label]) => <option key={kind} value={kind}>{t(label)}</option>)}
          </select>
        </label>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => void run(() => onInsertTextBox({ kind: boxKind, text: t(copy.reportTextBoxText) }), copy.reportTextBoxInserted)}>
          {t(copy.reportTextBoxInsert)}
        </button>
      </fieldset>
      <div className="segmented is-stack" role="radiogroup">
        <button type="button" role="radio" aria-checked={scope === "selection"} className={scope === "selection" ? "is-active" : ""} onClick={() => setScope("selection")}>{t(copy.reportGeminiScopeSelection)}</button>
        <button type="button" role="radio" aria-checked={scope === "write"} className={scope === "write" ? "is-active" : ""} onClick={() => setScope("write")}>{t(copy.reportGeminiScopeWrite)}</button>
        <button type="button" role="radio" aria-checked={scope === "document"} className={scope === "document" ? "is-active" : ""} onClick={() => setScope("document")}>{t(copy.reportGeminiScopeDocument)}</button>
      </div>
      {scope === "write" ? (
        <label className="field-label">
          {t(copy.reportGeminiPage)}
          <select className="field" value={page} onChange={(event) => setPage(Number(event.target.value))}>
            {Array.from({ length: pages }, (_, index) => (
              <option key={index + 1} value={index + 1}>{index + 1}</option>
            ))}
          </select>
        </label>
      ) : (
        <div className="chip-row" aria-label={t(copy.reportGeminiQuick)}>
          {quick.map((item) => (
            <button key={item.en} type="button" className="chip" disabled={busy} onClick={() => { setInstruction(t(item)); void ask(t(item)); }}>{t(item)}</button>
          ))}
        </div>
      )}
      <label className="field-label">
        {t(copy.reportGeminiAsk)}
        <textarea
          className="field field-area"
          rows={3}
          value={instruction}
          onChange={(event) => setInstruction(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) void ask();
          }}
        />
      </label>
      {attachment ? (
        <div className="report-gemini-attach">
          <img src={attachment.preview} alt="" />
          <span>{t(copy.reportGeminiAttached)}</span>
          <button type="button" className="btn btn-sm" onClick={detachPicture}>{t(copy.reportGeminiDetach)}</button>
        </div>
      ) : (
        <label className="btn btn-sm">
          {t(copy.reportGeminiAttach)}
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden disabled={busy} onChange={(event) => { void attachPicture(event.target.files?.[0]); event.target.value = ""; }} />
        </label>
      )}
      <label className="check-row">
        <input type="checkbox" checked={saveMemory} onChange={(event) => setSaveMemory(event.target.checked)} />
        {t(copy.reportGeminiRemember)}
      </label>
      <button type="button" className="btn btn-primary btn-sm" disabled={busy || instruction.trim() === ""} onClick={() => void ask()}>
        {busy ? t(copy.loading) : t(copy.reportGeminiSend)}
      </button>
      {draft ? (
        <div className="report-gemini-draft">
          {draft.reply ? <p className="muted">{draft.reply}</p> : null}
          <div className="report-gemini-compare">
            <label className="field-label">
              {t(copy.reportGeminiPrevious)}
              <textarea className="field field-area" rows={6} readOnly value={draft.previous} />
            </label>
            <label className="field-label">
              {t(copy.reportGeminiNext)}
              <textarea className="field field-area" rows={6} value={draft.next} onChange={(event) => setDraft({ ...draft, next: event.target.value })} />
            </label>
          </div>
          <div className="row-actions">
            <button type="button" className="btn btn-primary btn-sm" onClick={apply}>{t(copy.reportGeminiApply)}</button>
            <button type="button" className="btn btn-sm" onClick={() => setDraft(null)}>{t(copy.reportGeminiKeep)}</button>
          </div>
        </div>
      ) : null}
      <fieldset className="report-picture">
        <legend>{t(copy.reportFont)}</legend>
        <p className="muted">{t(copy.reportFontHint)}</p>
        <label className="field-label">
          {t(copy.reportFontName)}
          <input className="field" value={fontName} onChange={(event) => setFontName(event.target.value)} maxLength={80} />
        </label>
        <label className="btn btn-sm">
          {t(copy.reportFontFile)}
          <input type="file" accept=".ttf,.otf,font/ttf,font/otf" hidden disabled={busy} onChange={(event) => { void installFont(event.target.files?.[0]); event.target.value = ""; }} />
        </label>
        {fontRows.length > 0 ? (
          <ul className="report-font-list">
            {fontRows.map((font) => <li key={font.family}>{font.family}</li>)}
          </ul>
        ) : null}
      </fieldset>
      <details className="report-memory">
        <summary>{t(copy.reportMemory)} <span className="count-badge">{memories.length}</span></summary>
        <label className="field-label">
          {t(copy.reportMemoryNote)}
          <input className="field" value={memoryNote} onChange={(event) => setMemoryNote(event.target.value)} maxLength={500} />
        </label>
        <button type="button" className="btn btn-sm" disabled={busy || memoryNote.trim() === ""} onClick={() => void remember()}>{t(copy.reportMemorySave)}</button>
        <ul className="report-memory-list">
          {memories.length === 0 ? <li className="muted">{t(copy.reportMemoryEmpty)}</li> : null}
          {memories.map((memory) => (
            <li key={memory.id}>
              <span>{memory.body}</span>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => void api.deleteReportMemory(memory.id).then((res) => setMemories(asMemories(res.data)))}>{t(copy.delete)}</button>
            </li>
          ))}
        </ul>
      </details>
    </div>
  );
}
