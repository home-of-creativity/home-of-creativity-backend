import { lazy, Suspense, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
  ArrowLeft,
  ArrowRight,
  CloudUpload,
  Download,
  ExternalLink,
  FileText,
  FileUp,
  Info,
  PanelLeftClose,
  PanelLeftOpen,
  Paperclip,
  Save,
  Sparkles,
} from "lucide-react";
import { toast } from "sonner";
import { api, type Client, type ClientReport, type ClientReportAttachment } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { DriveFolderPicker } from "../components/DriveFolderPicker";
import { FileDropzone } from "../components/FileDropzone";
import { LoadingLottie } from "../components/LoadingLottie";
import { ReportGemini } from "../components/ReportGemini";
import { addParagraphFontStyle, buildDocxFromParagraphs, buildReportDocx, legacyHtmlToParagraphs, type ReportTemplateId } from "../components/report/docxTemplate";
import { clearReportDraft, readReportDraft, writeReportDraft } from "../components/report/draftStore";
import { loadReportFonts, rememberReportFont, type StoredReportFont } from "../components/report/fontStore";
import type { ReportDocHandle } from "../components/report/ReportDocEditor";
import { copy, type Locale } from "../i18n";

// The Word editor is large; load it only on this page.
const ReportDocEditor = lazy(() => import("../components/report/ReportDocEditor").then((module) => ({ default: module.ReportDocEditor })));

type Phase = "loading" | "template" | "editing" | "error";
type Busy = null | "saving" | "rendering" | "publishing";
type PanelTab = "gemini" | "files" | "info";
/**
 * auto: the timer after an edit; skipped when the server is current, retried when it fails.
 * leave: leaving the editor; skipped when the server is current.
 * manual: the Save button or Ctrl+S. publish: save, render the PDF, and upload both to Drive.
 */
type SaveMode = "auto" | "leave" | "manual" | "publish";
/** The document read at one moment, before an await lets the editor change or unmount. */
type Capture = { docx: Promise<Uint8Array>; text: string };

const DOCX_MIME = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
/** Autosave this long after the last edit, and at least this often while typing. */
const AUTOSAVE_DELAY_MS = 1500;
const AUTOSAVE_MAX_WAIT_MS = 15000;
const AUTOSAVE_RETRY_MS = 8000;

const TEMPLATES: Array<{ id: ReportTemplateId; name: { ar: string; en: string }; hint: { ar: string; en: string } }> = [
  { id: "social", name: copy.reportTemplateSocial, hint: copy.reportTemplateSocialHint },
  { id: "campaign", name: copy.reportTemplateCampaign, hint: copy.reportTemplateCampaignHint },
  { id: "minutes", name: copy.reportTemplateMinutes, hint: copy.reportTemplateMinutesHint },
  { id: "blank", name: copy.reportTemplateBlank, hint: copy.reportTemplateBlankHint },
];

function download(bytes: Uint8Array, name: string, type: string) {
  const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type }));
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

function fill(template: string, values: Record<string, string | number>) {
  return template.replace(/\{(\w+)\}/g, (_, key: string) => String(values[key] ?? ""));
}

export function ReportForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const clientId = params.clientId ? Number(params.clientId) : null;
  const reportId = params.reportId ? Number(params.reportId) : null;

  const editor = useRef<ReportDocHandle>(null);
  const [phase, setPhase] = useState<Phase>("loading");
  const [report, setReport] = useState<ClientReport | null>(null);
  const [client, setClient] = useState<Client | null>(null);
  const [bytes, setBytes] = useState<Uint8Array | null>(null);
  const [title, setTitle] = useState("");
  const [dirty, setDirty] = useState(false);
  const [autoSaving, setAutoSaving] = useState(false);
  const [autoFailed, setAutoFailed] = useState(false);
  const [legacy, setLegacy] = useState(false);
  const [busy, setBusy] = useState<Busy>(null);
  const [progress, setProgress] = useState({ done: 0, total: 0 });
  const [files, setFiles] = useState<File[]>([]);
  const [existing, setExisting] = useState<ClientReportAttachment[]>([]);
  const [removeIds, setRemoveIds] = useState<number[]>([]);
  const [panel, setPanel] = useState<PanelTab | null>(() => (window.matchMedia("(min-width: 1180px)").matches ? "gemini" : null));
  const [folderOpen, setFolderOpen] = useState(false);
  const [extraFonts, setExtraFonts] = useState<StoredReportFont[]>([]);
  const ownerId = report?.client_id ?? clientId;
  const BackIcon = locale === "ar" ? ArrowRight : ArrowLeft;

  // Autosave runs from timers and from the leave-page flush, so it reads the newest values here.
  const latest = useRef({ report, title, files, removeIds, ownerId, t });
  /** Bumps on every edit; `savedSeq` is the newest edit the server has. */
  const changeSeq = useRef(0);
  const savedSeq = useRef(0);
  const timer = useRef<number | null>(null);
  const pendingSince = useRef<number | null>(null);
  const queue = useRef<Promise<unknown>>(Promise.resolve());
  const loadedId = useRef<number | null>(null);
  const mounted = useRef(true);
  const failureShown = useRef(false);

  /** Save to the server; one save at a time, in order. Resolves true when the server has the document. */
  function save(mode: SaveMode, captured?: Capture): Promise<boolean> {
    const run = queue.current.then(() => upload(mode, captured));
    queue.current = run.catch(() => undefined);
    return run;
  }
  const saveRef = useRef(save);

  useLayoutEffect(() => {
    latest.current = { report, title, files, removeIds, ownerId, t };
    saveRef.current = save;
  });

  function schedule(delay: number) {
    if (timer.current !== null) window.clearTimeout(timer.current);
    const now = Date.now();
    pendingSince.current ??= now;
    const wait = Math.min(delay, Math.max(0, pendingSince.current + AUTOSAVE_MAX_WAIT_MS - now));
    timer.current = window.setTimeout(() => {
      timer.current = null;
      void saveRef.current("auto");
    }, wait);
  }

  function markChanged() {
    changeSeq.current += 1;
    setDirty(true);
    schedule(AUTOSAVE_DELAY_MS);
  }

  async function upload(mode: SaveMode, captured?: Capture): Promise<boolean> {
    const { report: current, title: name, files: added, removeIds: removed, ownerId: ownerKey, t: tr } = latest.current;
    const background = mode === "auto" || mode === "leave";
    const seq = changeSeq.current;
    if (background && seq === savedSeq.current) return true;

    const handle = editor.current;
    let snapshot = captured;
    if (!snapshot) {
      // Never save before the document is on the page: that would store an empty file.
      if (!handle?.ready()) {
        if (mode === "auto" && mounted.current) schedule(AUTOSAVE_DELAY_MS);
        if (mode === "manual" || mode === "publish") toast.error(tr(copy.loading));
        return false;
      }
      snapshot = { docx: handle.save(), text: handle.text() };
    }
    if (!ownerKey) return false;
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
      timer.current = null;
    }
    pendingSince.current = null;
    if (background) setAutoSaving(true);
    else setBusy("saving");

    try {
      const docx = await snapshot.docx;
      if (current) {
        void writeReportDraft({ reportId: current.id, bytes: docx, title: name, base: current.updated_at ?? null, savedAt: Date.now() });
      }
      const form = new FormData();
      form.set("title", name.trim() || tr(copy.reportTemplateBlank));
      form.set("body", snapshot.text.slice(0, 190000));
      form.set("document", new File([docx as BlobPart], "report.docx", { type: DOCX_MIME }));
      if (mode === "publish" && handle) {
        setBusy("rendering");
        const pdf = await handle.pdf((done, total) => setProgress({ done, total }));
        form.set("pdf", new File([pdf as BlobPart], "report.pdf", { type: "application/pdf" }));
        form.set("publish", "1");
        setBusy("publishing");
      }
      added.forEach((file) => form.append("attachments[]", file));
      removed.forEach((id) => form.append("remove_attachment_ids[]", String(id)));

      const res = await api.saveClientReport(ownerKey, form, current?.id);
      savedSeq.current = Math.max(savedSeq.current, seq);
      latest.current = { ...latest.current, report: res.data };
      failureShown.current = false;
      setReport(res.data);
      setExisting(res.data.attachments ?? []);
      setFiles((list) => list.filter((file) => !added.includes(file)));
      setRemoveIds((list) => list.filter((id) => !removed.includes(id)));
      setLegacy(false);
      setAutoFailed(false);
      if (changeSeq.current === seq) {
        setDirty(false);
        void clearReportDraft(res.data.id);
      }
      if (!current) {
        loadedId.current = res.data.id;
        // Point the address at the saved report without remounting this page (pages are keyed
        // by path), so a reload or Back opens it instead of a new blank report.
        if (mounted.current) {
          const path = window.location.pathname.replace(/\/reports\/clients\/\d+\/new\/?$/, `/reports/${res.data.id}/edit`);
          window.history.replaceState(window.history.state, "", path);
        }
      }
      if (mode === "publish") {
        if (res.drive_error) {
          toast.warning(fill(tr(copy.reportDriveFailed), { message: res.drive_error }));
        } else {
          toast.success(tr(copy.reportPublishedToast));
        }
      } else if (mode === "manual") {
        toast.success(tr(copy.saveSuccess));
      }
      return true;
    } catch (err) {
      const message = err instanceof Error ? err.message : tr(copy.saveFailed);
      if (mode === "auto") {
        setAutoFailed(true);
        if (!failureShown.current) toast.error(message);
        failureShown.current = true;
        if (mounted.current) schedule(AUTOSAVE_RETRY_MS);
      } else {
        toast.error(message);
      }
      return false;
    } finally {
      if (background) setAutoSaving(false);
      else setBusy(null);
      setProgress({ done: 0, total: 0 });
    }
  }

  useEffect(() => {
    // After the first save of a new report this page keeps editing it; nothing to reload.
    if (reportId !== null && loadedId.current === reportId) return;
    let cancelled = false;
    async function load() {
      try {
        const fonts = await loadReportFonts();
        if (cancelled) return;
        setExtraFonts(fonts);
        if (!reportId) {
          const res = await api.clientReports(Number(clientId));
          if (cancelled) return;
          setClient(res.client);
          setPhase("template");
          return;
        }
        const res = await api.clientReport(reportId);
        const [owner, document, draft] = await Promise.all([
          api.clientReports(res.data.client_id).then((list) => list.client),
          res.data.has_document ? api.reportFile(reportId, "document") : Promise.resolve(null),
          readReportDraft(reportId),
        ]);
        if (cancelled) return;
        // A copy from this browser that never reached the server wins while the server has not
        // been saved since that copy was made.
        const restore = draft !== null && draft.base === (res.data.updated_at ?? null);
        if (draft && !restore) void clearReportDraft(reportId);
        loadedId.current = reportId;
        setReport(res.data);
        setClient(owner);
        setTitle(restore ? draft.title : res.data.title);
        setExisting(res.data.attachments ?? []);
        const source = restore ? draft.bytes : document;
        if (source) {
          setBytes(fonts.reduce((file, font) => addParagraphFontStyle(file, font.family), source));
        } else {
          // Saved before reports were Word files: start a document from its text.
          setBytes(buildDocxFromParagraphs(
            { title: res.data.title, header: res.data.header ?? "", footer: res.data.footer ?? "" },
            legacyHtmlToParagraphs(res.data.body ?? ""),
          ));
          setLegacy(true);
        }
        setPhase("editing");
        if (restore) toast.info(t(copy.reportRecovered));
        if (restore || !source) markChanged();
      } catch {
        if (!cancelled) setPhase("error");
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
    // Loads when the address names another report; `t` and `markChanged` are read once here.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clientId, reportId]);

  // Leaving the editor (sidebar, command palette): send what the server does not have yet.
  // This runs before the editor below unmounts, so the document can still be read here.
  useLayoutEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      if (timer.current !== null) window.clearTimeout(timer.current);
      const handle = editor.current;
      if (!handle || changeSeq.current === savedSeq.current || !handle.ready()) return;
      try {
        const docx = handle.save();
        // It may reject before its turn in the queue; the upload reports that failure.
        docx.catch(() => undefined);
        void saveRef.current("leave", { docx, text: handle.text() });
      } catch {
        // The local draft and the leave warning cover this case.
      }
    };
  }, []);

  // Switching tab or window saves right away instead of waiting for the timer.
  useEffect(() => {
    const onHide = () => {
      if (document.visibilityState === "hidden" && changeSeq.current !== savedSeq.current) void saveRef.current("auto");
    };
    document.addEventListener("visibilitychange", onHide);
    return () => document.removeEventListener("visibilitychange", onHide);
  }, []);

  useEffect(() => {
    if (!dirty && !autoSaving) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty, autoSaving]);

  function start(id: ReportTemplateId) {
    const company = client?.company_name || client?.name || "";
    const name = t(TEMPLATES.find((item) => item.id === id)?.name ?? copy.reportTemplateBlank);
    const docTitle = company ? `${name} — ${company}` : name;
    setTitle(docTitle);
    // Stored on the first edit, so an untouched template does not create a report.
    setBytes(buildReportDocx(id, {
      title: docTitle,
      header: company ? `${company} · دار الإبداع` : "دار الإبداع",
      footer: "Home of Creativity · hoc.agency",
      client: company,
      date: new Date().toLocaleDateString("ar-SA-u-nu-latn", { year: "numeric", month: "long", day: "numeric" }),
    }));
    setPhase("editing");
  }

  async function importWord(file: File | undefined) {
    if (!file) return;
    setBytes(new Uint8Array(await file.arrayBuffer()));
    setTitle(file.name.replace(/\.docx$/i, ""));
    setPhase("editing");
    markChanged();
  }

  function publish() {
    if (!client?.google_drive_folder_id) {
      toast.error(t(copy.reportNoFolder));
      setPanel("info");
      setFolderOpen(true);
      return;
    }
    void save("publish");
  }

  async function leave() {
    if (changeSeq.current !== savedSeq.current && !(await save("leave"))) return;
    navigate(backTo);
  }

  async function downloadWord() {
    const docx = await editor.current?.save();
    if (docx) download(docx, `${title || "report"}.docx`, DOCX_MIME);
  }

  async function downloadPdf() {
    if (!editor.current || busy) return;
    setBusy("rendering");
    try {
      const pdf = await editor.current.pdf((done, total) => setProgress({ done, total }));
      download(pdf, `${title || "report"}.pdf`, "application/pdf");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(null);
      setProgress({ done: 0, total: 0 });
    }
  }

  const backTo = ownerId ? `/reports/clients/${ownerId}` : "/reports";
  const saving = busy === "saving" || autoSaving;
  // Publishing saves first, so a publish time within a moment of the last save is the same version.
  const editedSincePublish = report?.published_at && report.updated_at
    ? Date.parse(report.updated_at) - Date.parse(report.published_at) > 2000
    : false;
  const status = busy === "rendering"
    ? fill(t(copy.reportRendering), { done: progress.done, total: progress.total || "…" })
    : busy === "publishing"
      ? t(copy.reportPublishing)
      : saving
        ? t(copy.reportSaving)
        : autoFailed
          ? t(copy.reportSaveRetry)
          : dirty
            ? t(copy.reportUnsaved)
            : !report
              ? t(copy.reportDraftStatus)
              : report.published_at && !editedSincePublish
                ? fill(t(copy.reportPublishedAt), { time: new Date(report.published_at).toLocaleString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" }) })
                : t(copy.reportAllSaved);

  if (phase === "loading") {
    return <LoadingLottie variant="page" label={t(copy.loading)} />;
  }

  if (phase === "error") {
    return (
      <section className="empty-state">
        <FileText size={28} aria-hidden="true" />
        <h1>{t(copy.reportLoadFailed)}</h1>
        <Link className="btn" to={backTo}>{t(copy.reportBackToList)}</Link>
      </section>
    );
  }

  if (phase === "template") {
    return (
      <section className="report-start">
        <Link className="back-link" to={backTo}><BackIcon size={16} aria-hidden="true" />{client?.company_name || client?.name || t(copy.reportBackToList)}</Link>
        <header className="report-start-head">
          <h1>{t(copy.reportTemplateTitle)}</h1>
          <p>{t(copy.reportTemplateLede)}</p>
          {!client?.google_drive_folder_id ? <p className="notice">{t(copy.reportNoFolderPublish)}</p> : null}
        </header>
        <div className="template-grid">
          {TEMPLATES.map((template) => (
            <button key={template.id} type="button" className={`template-card is-${template.id}`} onClick={() => start(template.id)}>
              <span className="template-sheet" aria-hidden="true"><i /><i /><i /><i /></span>
              <strong>{t(template.name)}</strong>
              <span>{t(template.hint)}</span>
            </button>
          ))}
          <label className="template-card is-import">
            <span className="template-sheet" aria-hidden="true"><FileUp size={28} /></span>
            <strong>{t(copy.reportTemplateImport)}</strong>
            <span>{t(copy.reportTemplateImportHint)}</span>
            <input type="file" accept=".docx" hidden onChange={(event) => void importWord(event.target.files?.[0])} />
          </label>
        </div>
      </section>
    );
  }

  const titleBarStart = () => (
    <div className="report-bar" dir={locale === "ar" ? "rtl" : "ltr"}>
      <button type="button" className="btn btn-ghost btn-sm" title={t(copy.reportBackToList)} onClick={() => void leave()}>
        <BackIcon size={15} aria-hidden="true" /><span className="btn-label">{t(copy.reportBackToList)}</span>
      </button>
      <span className="report-bar-client">{client?.company_name || client?.name}</span>
    </div>
  );

  const titleBarEnd = () => (
    <div className="report-bar" dir={locale === "ar" ? "rtl" : "ltr"}>
      <span className={`status-dot${dirty || autoFailed ? " is-dirty" : report?.published_at && !editedSincePublish ? " is-live" : ""}${busy || autoSaving ? " is-busy" : ""}`} role="status" aria-live="polite">{status}</span>
      <button type="button" className="btn btn-sm" disabled={busy !== null} onClick={() => void save("manual")} title="Ctrl+S">
        <Save size={15} aria-hidden="true" /><span className="btn-label">{t(copy.reportSaveDraft)}</span>
      </button>
      <button type="button" className="btn btn-primary btn-sm" disabled={busy !== null} onClick={publish} title={t(copy.reportPublish)}>
        <CloudUpload size={15} aria-hidden="true" /><span className="btn-label">{t(copy.reportPublish)}</span>
      </button>
      <button
        type="button"
        className="btn btn-ghost btn-icon"
        aria-label={panel ? t(copy.reportPanelHide) : t(copy.reportPanelShow)}
        title={panel ? t(copy.reportPanelHide) : t(copy.reportPanelShow)}
        onClick={() => setPanel((current) => (current ? null : "gemini"))}
      >
        {panel ? <PanelLeftClose size={17} aria-hidden="true" /> : <PanelLeftOpen size={17} aria-hidden="true" />}
      </button>
    </div>
  );

  const tabs: Array<{ id: PanelTab; label: string; icon: typeof Sparkles }> = [
    { id: "gemini", label: t(copy.reportGemini), icon: Sparkles },
    { id: "files", label: `${t(copy.reportPanelFiles)}${existing.length - removeIds.length + files.length > 0 ? ` (${existing.length - removeIds.length + files.length})` : ""}`, icon: Paperclip },
    { id: "info", label: t(copy.reportPanelInfo), icon: Info },
  ];

  return (
    <section className={`report-studio${panel ? " has-panel" : ""}`}>
      {legacy ? <p className="report-studio-notice" role="status">{t(copy.reportLegacyNotice)}</p> : null}
      <div className="report-studio-body">
        <div className="report-studio-doc">
          {bytes ? (
            <Suspense fallback={<LoadingLottie variant="page" label={t(copy.loading)} />}>
              <ReportDocEditor
                key={extraFonts.map((font) => font.family).join("|")}
                ref={editor}
                document={bytes}
                extraFonts={extraFonts}
                title={title}
                locale={locale}
                onTitleChange={(value) => {
                  setTitle(value);
                  markChanged();
                }}
                onChange={markChanged}
                onSave={() => void save("manual")}
                titleBarStart={titleBarStart}
                titleBarEnd={titleBarEnd}
              />
            </Suspense>
          ) : null}
        </div>
        {panel ? (
          <aside className="report-panel" aria-label={t(copy.reportPanelInfo)}>
            <div className="report-panel-tabs" role="tablist">
              {tabs.map(({ id, label, icon: Icon }) => (
                <button key={id} type="button" role="tab" aria-selected={panel === id} className={panel === id ? "is-active" : ""} onClick={() => setPanel(id)}>
                  <Icon size={15} aria-hidden="true" />
                  <span>{label}</span>
                </button>
              ))}
            </div>
            <div className="report-panel-body" role="tabpanel">
              {panel === "gemini" ? (
                <ReportGemini
                  t={t}
                  selectedText={() => editor.current?.selectedText() ?? ""}
                  pageCount={() => editor.current?.pageCount() ?? 1}
                  pageText={(page) => editor.current?.pageText(page) ?? ""}
                  onApply={async (text, page) => {
                    const done = page === null
                      ? editor.current?.replaceSelection(text) ?? false
                      : await (editor.current?.insertOnPage(page, text) ?? false);
                    if (done) markChanged();
                    return done;
                  }}
                  onInsertImage={async (bytes, widthPercent, page, wrap) => {
                    const result = await editor.current?.insertImage(bytes, widthPercent, page, wrap) ?? "refused";
                    if (result === "ok") markChanged();
                    return result;
                  }}
                  fonts={extraFonts}
                  onInstallFont={async (family, file) => {
                    if (file.size > 4 * 1024 * 1024) throw new Error(t(copy.reportFontTooBig));
                    const faces = await rememberReportFont(family, await file.arrayBuffer());
                    const saved = await editor.current?.save();
                    if (!saved) throw new Error(t(copy.saveFailed));
                    setExtraFonts(faces);
                    setBytes(addParagraphFontStyle(saved, family));
                    markChanged();
                  }}
                />
              ) : null}
              {panel === "files" ? (
                <div className="report-files">
                  <FileDropzone
                    multiple
                    hint={t(copy.reportDrop)}
                    onFiles={(list) => {
                      setFiles((current) => [...current, ...list]);
                      markChanged();
                    }}
                  />
                  <ul className="file-list">
                    {existing.filter((file) => !removeIds.includes(file.id)).map((file) => (
                      <li key={file.id}>
                        <Paperclip size={14} aria-hidden="true" />
                        <span className="file-name">{file.drive_url ? <a href={file.drive_url} target="_blank" rel="noreferrer">{file.name}</a> : file.name}</span>
                        <small>{Math.ceil(file.size / 1024)} KB</small>
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setRemoveIds((current) => [...current, file.id]); markChanged(); }}>{t(copy.delete)}</button>
                      </li>
                    ))}
                    {files.map((file, index) => (
                      <li key={`${file.name}-${index}`} className="is-new">
                        <Paperclip size={14} aria-hidden="true" />
                        <span className="file-name">{file.name}</span>
                        <small>{Math.ceil(file.size / 1024)} KB</small>
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setFiles((current) => current.filter((_, item) => item !== index))}>{t(copy.delete)}</button>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
              {panel === "info" ? (
                <div className="report-info">
                  <dl className="meta-list">
                    <div><dt>{t(copy.company)}</dt><dd>{client?.company_name || client?.name || "—"}</dd></div>
                    <div><dt>{t(copy.reportLastEdited)}</dt><dd>{report?.updated_at ? new Date(report.updated_at).toLocaleString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" }) : "—"}</dd></div>
                    <div><dt>{t(copy.driveFolder)}</dt><dd>{client?.google_drive_folder_id ? t(copy.driveFolderExisting) : "—"}</dd></div>
                  </dl>
                  <div className="stack-actions">
                    <button type="button" className="btn btn-sm" onClick={() => void downloadWord()}><Download size={15} aria-hidden="true" />{t(copy.reportDownloadDocx)}</button>
                    <button type="button" className="btn btn-sm" disabled={busy !== null} onClick={() => void downloadPdf()}><Download size={15} aria-hidden="true" />{t(copy.reportDownloadPdf)}</button>
                    {report?.drive_document_url ? <a className="btn btn-ghost btn-sm" href={report.drive_document_url} target="_blank" rel="noreferrer"><ExternalLink size={15} aria-hidden="true" />{t(copy.reportOpenDriveDocx)}</a> : null}
                    {report?.drive_url ? <a className="btn btn-ghost btn-sm" href={report.drive_url} target="_blank" rel="noreferrer"><ExternalLink size={15} aria-hidden="true" />{t(copy.reportOpenDrivePdf)}</a> : null}
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => setFolderOpen((open) => !open)}>{t(copy.driveFolder)}</button>
                  </div>
                  {folderOpen && client ? (
                    <DriveFolderPicker
                      client={client}
                      t={t}
                      onClose={() => setFolderOpen(false)}
                      onSaved={(saved) => {
                        setClient(saved);
                        setFolderOpen(false);
                      }}
                    />
                  ) : null}
                  {report ? (
                    <ConfirmAction
                      label={t(copy.delete)}
                      confirmLabel={t(copy.confirmDelete)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      onConfirm={() => {
                        void api.deleteClientReport(report.id).then(() => {
                          // Nothing is left to save once the report is gone.
                          savedSeq.current = changeSeq.current;
                          if (timer.current !== null) window.clearTimeout(timer.current);
                          void clearReportDraft(report.id);
                          setDirty(false);
                          navigate(backTo, { replace: true });
                        });
                      }}
                    />
                  ) : null}
                </div>
              ) : null}
            </div>
          </aside>
        ) : null}
      </div>
    </section>
  );
}
