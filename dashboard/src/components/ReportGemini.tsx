import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api } from "../api";
import { copy } from "../i18n";

type Memory = { id: number; body: string };
type Scope = "selection" | "write";

function escapeHtml(text: string) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/** Gemini answers with report HTML; the Word document takes plain paragraphs. */
function htmlToText(html: string) {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body;
  const blocks = Array.from(root.querySelectorAll("p, h1, h2, h3, h4, li, blockquote"));
  const lines = blocks.length > 0 ? blocks.map((block) => block.textContent ?? "") : [root.textContent ?? ""];
  return lines.map((line) => line.trim()).filter(Boolean).join("\n");
}

export function ReportGemini({
  t,
  selectedText,
  onApply,
}: {
  t: (c: { ar: string; en: string }) => string;
  /** Text currently selected in the document. */
  selectedText: () => string;
  /** Put text into the document: replaces the selection, or inserts at the caret. */
  onApply: (text: string) => boolean;
}) {
  const [instruction, setInstruction] = useState("");
  const [scope, setScope] = useState<Scope>("selection");
  const [saveMemory, setSaveMemory] = useState(false);
  const [memoryNote, setMemoryNote] = useState("");
  const [memories, setMemories] = useState<Memory[]>([]);
  const [draft, setDraft] = useState<{ reply: string; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.reportMemories()
      .then((res) => setMemories(res.data))
      .catch(() => setMemories([]));
  }, []);

  async function ask(text = instruction) {
    const request = text.trim();
    if (request === "") return;
    const source = scope === "selection" ? selectedText().trim() : "";
    if (scope === "selection" && source === "") {
      toast.info(t(copy.reportGeminiSelectFirst));
      return;
    }
    setBusy(true);
    try {
      const body = source === ""
        ? "<p></p>"
        : source.split(/\n+/).map((line) => `<p>${escapeHtml(line)}</p>`).join("");
      const res = await api.editReportWithGemini({ instruction: request, body, scope: "all", save_memory: saveMemory });
      setMemories(res.data.memories);
      setDraft({ reply: res.data.reply, text: htmlToText(res.data.body) });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  function apply(requireSelection: boolean) {
    if (!draft) return;
    if (requireSelection && selectedText().trim() === "") {
      toast.info(t(copy.reportGeminiSelectFirst));
      return;
    }
    if (onApply(draft.text)) {
      toast.success(t(copy.reportGeminiApplied));
      setDraft(null);
    }
  }

  async function remember() {
    const text = memoryNote.trim();
    if (text === "") return;
    setBusy(true);
    try {
      const res = await api.saveReportMemory(text);
      setMemories(res.data);
      setMemoryNote("");
      toast.success(t(copy.reportMemorySaved));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const quick = [copy.reportGeminiProofread, copy.reportGeminiFormal, copy.reportGeminiShorten, copy.reportGeminiSummary, copy.reportGeminiTranslate];

  return (
    <div className="report-gemini" aria-label={t(copy.reportGemini)}>
      <div className="segmented" role="radiogroup">
        <button type="button" role="radio" aria-checked={scope === "selection"} className={scope === "selection" ? "is-active" : ""} onClick={() => setScope("selection")}>{t(copy.reportGeminiScopeSelection)}</button>
        <button type="button" role="radio" aria-checked={scope === "write"} className={scope === "write" ? "is-active" : ""} onClick={() => setScope("write")}>{t(copy.reportGeminiScopeWrite)}</button>
      </div>
      {scope === "selection" ? (
        <div className="chip-row" aria-label={t(copy.reportGeminiQuick)}>
          {quick.map((item) => (
            <button key={item.en} type="button" className="chip" disabled={busy} onClick={() => { setInstruction(t(item)); void ask(t(item)); }}>{t(item)}</button>
          ))}
        </div>
      ) : null}
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
          <textarea className="field field-area" rows={6} value={draft.text} onChange={(event) => setDraft({ ...draft, text: event.target.value })} />
          <div className="row-actions">
            {scope === "selection" ? (
              <button type="button" className="btn btn-primary btn-sm" onClick={() => apply(true)}>{t(copy.reportGeminiReplace)}</button>
            ) : null}
            <button type="button" className="btn btn-sm" onClick={() => apply(false)}>{t(copy.reportGeminiInsert)}</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => void navigator.clipboard?.writeText(draft.text)}>{t(copy.reportGeminiCopy)}</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setDraft(null)}>{t(copy.cancel)}</button>
          </div>
        </div>
      ) : null}
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
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => void api.deleteReportMemory(memory.id).then((res) => setMemories(res.data))}>{t(copy.delete)}</button>
            </li>
          ))}
        </ul>
      </details>
    </div>
  );
}
