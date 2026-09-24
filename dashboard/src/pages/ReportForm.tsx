import { useEffect, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { api, type ClientReportAttachment } from "../api";
import { ReportEditor } from "../components/ReportEditor";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

export function ReportForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const clientId = params.clientId ? Number(params.clientId) : null;
  const reportId = params.reportId ? Number(params.reportId) : null;
  const [title, setTitle] = useState("");
  const [header, setHeader] = useState("");
  const [footer, setFooter] = useState("");
  const [body, setBody] = useState("");
  const [cover, setCover] = useState<File | null>(null);
  const [coverUrl, setCoverUrl] = useState<string | null>(null);
  const [removeCover, setRemoveCover] = useState(false);
  const [watermark, setWatermark] = useState<File | null>(null);
  const [watermarkUrl, setWatermarkUrl] = useState<string | null>(null);
  const [removeWatermark, setRemoveWatermark] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [existing, setExisting] = useState<ClientReportAttachment[]>([]);
  const [removeIds, setRemoveIds] = useState<number[]>([]);
  const [zoom, setZoom] = useState(100);
  const [loaded, setLoaded] = useState(!reportId);
  const [filePast, setFilePast] = useState<Array<{ files: File[]; removeIds: number[] }>>([]);
  const [ownerId, setOwnerId] = useState<number | null>(clientId);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!reportId) return;
    api.clientReport(reportId).then((res) => {
      setTitle(res.data.title);
      setHeader(res.data.header ?? "");
      setFooter(res.data.footer ?? "");
      setBody(res.data.body);
      setCoverUrl(res.data.cover_url);
      setWatermarkUrl(res.data.watermark_url ?? null);
      setExisting(res.data.attachments ?? []);
      setOwnerId(res.data.client_id);
      setLoaded(true);
    }).catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [reportId, t]);

  function rememberFiles() {
    setFilePast((past) => [...past, { files: [...files], removeIds: [...removeIds] }].slice(-20));
  }

  function addFiles(list: FileList | null) {
    if (!list) return;
    rememberFiles();
    setFiles((current) => [...current, ...Array.from(list)]);
  }

  function undoFiles() {
    const previous = filePast[filePast.length - 1];
    if (!previous) return;
    setFilePast((past) => past.slice(0, -1));
    setFiles(previous.files);
    setRemoveIds(previous.removeIds);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!ownerId) return;
    setBusy(true);
    setError("");
    const form = new FormData();
    form.set("title", title);
    form.set("header", header);
    form.set("footer", footer);
    form.set("body", body);
    if (cover) form.set("cover", cover);
    else if (removeCover) form.set("remove_cover", "1");
    if (watermark) form.set("watermark", watermark);
    else if (removeWatermark) form.set("remove_watermark", "1");
    files.forEach((file) => form.append("attachments[]", file));
    removeIds.forEach((id) => form.append("remove_attachment_ids[]", String(id)));
    try {
      await api.saveClientReport(ownerId, form, reportId ?? undefined);
      toast.success(t(copy.saveSuccess));
      navigate(`/reports/clients/${ownerId}`);
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  return (
    <section>
      <PageHeader title={reportId ? t(copy.edit) : t(copy.addReport)} lede={t(copy.reportBody)} />
      {error ? <p className="error">{error}</p> : null}
      <form className="card form-grid" onSubmit={(event) => void save(event)}>
        <div className="field-span">
          <div className="row-actions">
            <button type="button" className="btn" onClick={() => setZoom((value) => Math.max(70, value - 10))}>{t(copy.reportZoomOut)}</button>
            <button type="button" className="btn" onClick={() => setZoom((value) => Math.min(140, value + 10))}>{t(copy.reportZoomIn)}</button>
          </div>
          {loaded ? (
            <ReportEditor
              title={title}
              header={header}
              footer={footer}
              value={body}
              coverUrl={coverUrl}
              watermarkUrl={watermarkUrl}
              zoom={zoom}
              onTitle={setTitle}
              onHeader={setHeader}
              onFooter={setFooter}
              onChange={setBody}
              onCoverFile={(file) => {
                setCover(file);
                setRemoveCover(file === null);
                setCoverUrl(file ? URL.createObjectURL(file) : null);
              }}
              onWatermarkFile={(file) => {
                setWatermark(file);
                setRemoveWatermark(file === null);
                setWatermarkUrl(file ? URL.createObjectURL(file) : null);
              }}
              labels={{
                bold: "B",
                italic: "I",
                underline: "U",
                strike: "S",
                heading: "H",
                paragraph: "¶",
                list: "•",
                numbered: "1.",
                alignRight: "⇤",
                alignCenter: "↔",
                alignLeft: "⇥",
                image: t(copy.reportImage),
                table: t(copy.reportTable),
                undo: t(copy.reportUndo),
                redo: t(copy.reportRedo),
                delete: t(copy.delete),
                clear: t(copy.reportClear),
                pageBreak: t(copy.reportPage),
                title: t(copy.reportTitle),
                header: t(copy.reportHeader),
                footer: t(copy.reportFooter),
                page: t(copy.reportPage),
                cover: t(copy.reportCover),
                uploadImage: t(copy.reportUploadImage),
                pickImage: t(copy.reportPickImage),
                coverContent: t(copy.reportCoverContent),
                hideChrome: t(copy.reportHideChrome),
                showChrome: t(copy.reportShowChrome),
                deletePage: t(copy.reportDeletePage),
                imageSize: t(copy.reportImageSize),
                useAsCover: t(copy.reportUseAsCover),
                rows: t(copy.reportRows),
                columns: t(copy.reportColumns),
                addRow: t(copy.reportAddRow),
                addColumn: t(copy.reportAddColumn),
                deleteRow: t(copy.reportDeleteRow),
                deleteColumn: t(copy.reportDeleteColumn),
                headerRow: t(copy.reportHeaderRow),
                tableWidth: t(copy.reportTableWidth),
                borders: t(copy.reportBorders),
                noBorders: t(copy.reportNoBorders),
                watermark: t(copy.reportWatermark),
                watermarkOpacity: t(copy.reportWatermarkOpacity),
                removeWatermark: t(copy.reportRemoveWatermark),
              }}
            />
          ) : null}
        </div>
        <div
          className="field-span report-drop"
          onDragOver={(event) => event.preventDefault()}
          onDrop={(event) => {
            event.preventDefault();
            addFiles(event.dataTransfer.files);
          }}
        >
          <strong>{t(copy.reportAttachments)}</strong>
          <p>{t(copy.reportDrop)}</p>
          <button type="button" className="btn" disabled={filePast.length === 0} onClick={undoFiles}>{t(copy.reportUndo)}</button>
          <input type="file" multiple onChange={(event) => addFiles(event.target.files)} />
          <ul className="plain-list">
            {existing.filter((file) => !removeIds.includes(file.id)).map((file) => (
              <li key={file.id}>
                {file.name}
                <small> · {Math.ceil(file.size / 1024)} KB</small>
                <button type="button" className="btn btn-ghost" onClick={() => { rememberFiles(); setRemoveIds((current) => [...current, file.id]); }}>{t(copy.delete)}</button>
              </li>
            ))}
            {files.map((file, index) => (
              <li key={`${file.name}-${index}`}>
                {file.name}
                <small> · {Math.ceil(file.size / 1024)} KB</small>
                <button type="button" className="btn btn-ghost" onClick={() => { rememberFiles(); setFiles((current) => current.filter((_, item) => item !== index)); }}>{t(copy.delete)}</button>
              </li>
            ))}
          </ul>
        </div>
        <div className="row-actions field-span">
          <button className="btn btn-primary" type="submit" disabled={busy}>{t(copy.save)}</button>
        </div>
      </form>
    </section>
  );
}
