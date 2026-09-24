import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api } from "../api";
import { copy } from "../i18n";

type Memory = { id: number; body: string };

function pageTotal(html: string) {
  const count = html.match(/class="hoc-page"/g)?.length ?? 0;
  return Math.max(1, count);
}

export function ReportGemini({
  body,
  t,
  onApply,
}: {
  body: string;
  t: (c: { ar: string; en: string }) => string;
  onApply: (html: string) => void;
}) {
  const [instruction, setInstruction] = useState("");
  const [scope, setScope] = useState<"all" | "page">("all");
  const [page, setPage] = useState(1);
  const [mode, setMode] = useState<"apply" | "draft">("draft");
  const [saveMemory, setSaveMemory] = useState(false);
  const [memoryNote, setMemoryNote] = useState("");
  const [memories, setMemories] = useState<Memory[]>([]);
  const [draft, setDraft] = useState<{ reply: string; body: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const pages = pageTotal(body);

  useEffect(() => {
    api.reportMemories()
      .then((res) => setMemories(res.data))
      .catch(() => setMemories([]));
  }, []);

  async function ask() {
    const text = instruction.trim();
    if (text === "") return;
    setBusy(true);
    try {
      const res = await api.editReportWithGemini({
        instruction: text,
        body: body || "<p></p>",
        scope,
        page: scope === "page" ? page : undefined,
        save_memory: saveMemory,
      });
      setMemories(res.data.memories);
      if (mode === "apply") {
        onApply(res.data.body);
        setDraft(null);
        toast.success(res.data.reply);
      } else {
        setDraft({ reply: res.data.reply, body: res.data.body });
      }
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
      setMemories(res.data);
      setMemoryNote("");
      toast.success(t(copy.reportMemorySaved));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  return (
    <aside className="report-gemini card" aria-label={t(copy.reportGemini)}>
      <h2 className="section-title">{t(copy.reportGemini)}</h2>
      <div className="row-actions">
        <button type="button" className={`btn${scope === "all" ? " btn-primary" : ""}`} onClick={() => setScope("all")}>{t(copy.reportGeminiAll)}</button>
        <button type="button" className={`btn${scope === "page" ? " btn-primary" : ""}`} onClick={() => setScope("page")}>{t(copy.reportGeminiPage)}</button>
        {scope === "page" ? (
          <label className="report-mini">
            {t(copy.reportPage)}
            <input className="field" type="number" min={1} max={pages} value={page} onChange={(event) => setPage(Number(event.target.value))} />
          </label>
        ) : null}
      </div>
      <div className="row-actions">
        <button type="button" className={`btn${mode === "apply" ? " btn-primary" : ""}`} onClick={() => setMode("apply")}>{t(copy.reportGeminiApply)}</button>
        <button type="button" className={`btn${mode === "draft" ? " btn-primary" : ""}`} onClick={() => setMode("draft")}>{t(copy.reportGeminiDraft)}</button>
      </div>
      <label className="field-label">
        {t(copy.reportGeminiAsk)}
        <textarea className="field field-area" value={instruction} onChange={(event) => setInstruction(event.target.value)} />
      </label>
      <label className="check-row">
        <input type="checkbox" checked={saveMemory} onChange={(event) => setSaveMemory(event.target.checked)} />
        {t(copy.reportGeminiRemember)}
      </label>
      <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void ask()}>{busy ? t(copy.loading) : t(copy.reportGeminiSend)}</button>
      {draft ? (
        <div className="report-gemini-draft">
          <p>{draft.reply}</p>
          <div className="row-actions">
            <button type="button" className="btn btn-primary" onClick={() => { onApply(draft.body); setDraft(null); }}>{t(copy.reportGeminiUse)}</button>
            <button type="button" className="btn" onClick={() => setDraft(null)}>{t(copy.cancel)}</button>
          </div>
        </div>
      ) : null}
      <h3 className="section-title">{t(copy.reportMemory)}</h3>
      <label className="field-label">
        {t(copy.reportMemoryNote)}
        <input className="field" value={memoryNote} onChange={(event) => setMemoryNote(event.target.value)} maxLength={500} />
      </label>
      <button type="button" className="btn" disabled={busy} onClick={() => void remember()}>{t(copy.reportMemorySave)}</button>
      <ul className="plain-list">
        {memories.length === 0 ? <li>{t(copy.reportMemoryEmpty)}</li> : null}
        {memories.map((memory) => (
          <li key={memory.id}>
            {memory.body}
            <button type="button" className="btn btn-ghost" onClick={() => void api.deleteReportMemory(memory.id).then((res) => setMemories(res.data))}>{t(copy.delete)}</button>
          </li>
        ))}
      </ul>
    </aside>
  );
}
