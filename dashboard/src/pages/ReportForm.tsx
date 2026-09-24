import { lazy, Suspense, useCallback, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate, useParams } from "react-router-dom";
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
import { buildDocxFromParagraphs, buildReportDocx, legacyHtmlToParagraphs, type ReportTemplateId } from "../components/report/docxTemplate";
import type { ReportDocHandle } from "../components/report/ReportDocEditor";
import { copy, type Locale } from "../i18n";

// The Word editor is large; load it only on this page.
const ReportDocEditor = lazy(() => import("../components/report/ReportDocEditor").then((module) => ({ default: module.ReportDocEditor })));

type Phase = "loading" | "template" | "editing" | "error";
type Busy = null | "saving" | "rendering" | "publishing";
type PanelTab = "gemini" | "files" | "info";

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
  const location = useLocation();
  const params = useParams();
  const clientId = params.clientId ? Number(params.clientId) : null;
  const reportId = params.reportId ? Number(params.reportId) : null;
  const handed = (location.state as { bytes?: Uint8Array } | null)?.bytes;

  const editor = useRef<ReportDocHandle>(null);
  const [phase, setPhase] = useState<Phase>("loading");
  const [report, setReport] = useState<ClientReport | null>(null);
  const [client, setClient] = useState<Client | null>(null);
  const [bytes, setBytes] = useState<Uint8Array | null>(null);
  const [title, setTitle] = useState("");
  const [dirty, setDirty] = useState(false);
  const [legacy, setLegacy] = useState(false);
  const [busy, setBusy] = useState<Busy>(null);
  const [progress, setProgress] = useState({ done: 0, total: 0 });
  const [files, setFiles] = useState<File[]>([]);
  const [existing, setExisting] = useState<ClientReportAttachment[]>([]);
  const [removeIds, setRemoveIds] = useState<number[]>([]);
  const [panel, setPanel] = useState<PanelTab | null>(() => (window.matchMedia("(min-width: 1180px)").matches ? "gemini" : null));
  const [folderOpen, setFolderOpen] = useState(false);
  const ownerId = report?.client_id ?? clientId;
  const BackIcon = locale === "ar" ? ArrowRight : ArrowLeft;

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        if (!reportId) {
          const res = await api.clientReports(Number(clientId));
          if (cancelled) return;
          setClient(res.client);
          setPhase("template");
          return;
        }
        const res = await api.clientReport(reportId);
        const [owner, document] = await Promise.all([
          api.clientReports(res.data.client_id).then((list) => list.client),
          handed ? Promise.resolve(handed) : res.data.has_document ? api.reportFile(reportId, "document") : Promise.resolve(null),
        ]);
        if (cancelled) return;
        setReport(res.data);
        setClient(owner);
        setTitle(res.data.title);
        setExisting(res.data.attachments ?? []);
        if (document) {
          setBytes(document);
        } else {
          // Saved before reports were Word files: start a document from its text.
          setBytes(buildDocxFromParagraphs(
            { title: res.data.title, header: res.data.header ?? "", footer: res.data.footer ?? "" },
            legacyHtmlToParagraphs(res.data.body ?? ""),
          ));
          setLegacy(true);
          setDirty(true);
        }
        setPhase("editing");
      } catch {
        if (!cancelled) setPhase("error");
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
    // `handed` is read once, when the page opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clientId, reportId]);

  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  function start(id: ReportTemplateId) {
    const company = client?.company_name || client?.name || "";
    const name = t(TEMPLATES.find((item) => item.id === id)?.name ?? copy.reportTemplateBlank);
    const docTitle = company ? `${name} — ${company}` : name;
    setTitle(docTitle);
    setBytes(buildReportDocx(id, {
      title: docTitle,
      header: company ? `${company} · دار الإبداع` : "دار الإبداع",
      footer: "Home of Creativity · hoc.agency",
      client: company,
      date: new Date().toLocaleDateString("ar-SA-u-nu-latn", { year: "numeric", month: "long", day: "numeric" }),
    }));
    setDirty(true);
    setPhase("editing");
  }

  async function importWord(file: File | undefined) {
    if (!file) return;
    setBytes(new Uint8Array(await file.arrayBuffer()));
    setTitle(file.name.replace(/\.docx$/i, ""));
    setDirty(true);
    setPhase("editing");
  }

  const save = useCallback(async (publish: boolean) => {
    const handle = editor.current;
    if (!handle || !ownerId || busy) return;
    if (publish && !client?.google_drive_folder_id) {
      toast.error(t(copy.reportNoFolder));
      setPanel("info");
      setFolderOpen(true);
      return;
    }
    try {
      setBusy("saving");
      const docx = await handle.save();
      const form = new FormData();
      form.set("title", title.trim() || t(copy.reportTemplateBlank));
      form.set("body", handle.text().slice(0, 190000));
      form.set("document", new File([docx as BlobPart], "report.docx", { type: "application/vnd.openxmlformats-officedocument.wordprocessingml.document" }));
      if (publish) {
        setBusy("rendering");
        const pdf = await handle.pdf((done, total) => setProgress({ done, total }));
        form.set("pdf", new File([pdf as BlobPart], "report.pdf", { type: "application/pdf" }));
        form.set("publish", "1");
        setBusy("publishing");
      }
      files.forEach((file) => form.append("attachments[]", file));
      removeIds.forEach((id) => form.append("remove_attachment_ids[]", String(id)));

      const res = await api.saveClientReport(ownerId, form, report?.id);
      setReport(res.data);
      setExisting(res.data.attachments ?? []);
      setFiles([]);
      setRemoveIds([]);
      setDirty(false);
      setLegacy(false);
      if (res.drive_error) toast.warning(fill(t(copy.reportDriveFailed), { message: res.drive_error }));
      else toast.success(publish ? t(copy.reportPublishedToast) : t(copy.saveSuccess));
      if (!report) navigate(`/reports/${res.data.id}/edit`, { replace: true, state: { bytes: docx } });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(null);
      setProgress({ done: 0, total: 0 });
    }
  }, [busy, client, files, navigate, ownerId, removeIds, report, t, title]);

  async function downloadWord() {
    const docx = await editor.current?.save();
    if (docx) download(docx, `${title || "report"}.docx`, "application/vnd.openxmlformats-officedocument.wordprocessingml.document");
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
  const status = busy === "saving"
    ? t(copy.reportSaving)
    : busy === "rendering"
      ? fill(t(copy.reportRendering), { done: progress.done, total: progress.total || "…" })
      : busy === "publishing"
        ? t(copy.reportPublishing)
        : dirty
          ? t(copy.reportUnsaved)
          : report?.published_at
            ? fill(t(copy.reportPublishedAt), { time: new Date(report.published_at).toLocaleString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" }) })
            : t(copy.reportSaved);

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
      {dirty ? (
        <ConfirmAction
          label={t(copy.reportBackToList)}
          confirmLabel={t(copy.reportLeaveWarning)}
          yesLabel={t(copy.reportBackToList)}
          noLabel={t(copy.cancel)}
          className="btn btn-ghost btn-sm"
          onConfirm={() => navigate(backTo)}
        />
      ) : (
        <Link className="btn btn-ghost btn-sm" to={backTo} title={t(copy.reportBackToList)}><BackIcon size={15} aria-hidden="true" /><span className="btn-label">{t(copy.reportBackToList)}</span></Link>
      )}
      <span className="report-bar-client">{client?.company_name || client?.name}</span>
    </div>
  );

  const titleBarEnd = () => (
    <div className="report-bar" dir={locale === "ar" ? "rtl" : "ltr"}>
      <span className={`status-dot${dirty ? " is-dirty" : report?.published_at ? " is-live" : ""}${busy ? " is-busy" : ""}`} role="status" aria-live="polite">{status}</span>
      <button type="button" className="btn btn-sm" disabled={busy !== null} onClick={() => void save(false)} title="Ctrl+S">
        <Save size={15} aria-hidden="true" /><span className="btn-label">{t(copy.reportSaveDraft)}</span>
      </button>
      <button type="button" className="btn btn-primary btn-sm" disabled={busy !== null} onClick={() => void save(true)} title={t(copy.reportPublish)}>
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
                ref={editor}
                document={bytes}
                title={title}
                locale={locale}
                onTitleChange={(value) => {
                  setTitle(value);
                  setDirty(true);
                }}
                onChange={() => setDirty(true)}
                onSave={() => void save(false)}
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
                  onApply={(text) => {
                    const done = editor.current?.replaceSelection(text) ?? false;
                    if (done) setDirty(true);
                    return done;
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
                      setDirty(true);
                    }}
                  />
                  <ul className="file-list">
                    {existing.filter((file) => !removeIds.includes(file.id)).map((file) => (
                      <li key={file.id}>
                        <Paperclip size={14} aria-hidden="true" />
                        <span className="file-name">{file.drive_url ? <a href={file.drive_url} target="_blank" rel="noreferrer">{file.name}</a> : file.name}</span>
                        <small>{Math.ceil(file.size / 1024)} KB</small>
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setRemoveIds((current) => [...current, file.id]); setDirty(true); }}>{t(copy.delete)}</button>
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
