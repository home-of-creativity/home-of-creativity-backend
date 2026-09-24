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
  const [files, setFiles] = useState<File[]>([]);
  const [existing, setExisting] = useState<ClientReportAttachment[]>([]);
  const [removeIds, setRemoveIds] = useState<number[]>([]);
  const [zoom, setZoom] = useState(100);
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
      setExisting(res.data.attachments ?? []);
      setOwnerId(res.data.client_id);
    }).catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [reportId, t]);

  function addFiles(list: FileList | null) {
    if (!list) return;
    setFiles((current) => [...current, ...Array.from(list)]);
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
        <label className="field-label field-span">
          {t(copy.reportTitle)}
          <input className="field" value={title} onChange={(event) => setTitle(event.target.value)} required />
        </label>
        <label className="field-label">
          {t(copy.reportHeader)}
          <input className="field" value={header} onChange={(event) => setHeader(event.target.value)} />
        </label>
        <label className="field-label">
          {t(copy.reportFooter)}
          <input className="field" value={footer} onChange={(event) => setFooter(event.target.value)} />
        </label>
        <label className="field-label field-span">
          {t(copy.reportCover)}
          <input className="field" type="file" accept="image/*" onChange={(event) => setCover(event.target.files?.[0] ?? null)} />
          {coverUrl ? <img src={coverUrl} alt="" className="report-cover-preview" /> : null}
        </label>
        <div className="field-span">
          <div className="row-actions">
            <button type="button" className="btn" onClick={() => setZoom((value) => Math.max(80, value - 10))}>{t(copy.reportZoomOut)}</button>
            <button type="button" className="btn" onClick={() => setZoom((value) => Math.min(160, value + 10))}>{t(copy.reportZoomIn)}</button>
          </div>
          <ReportEditor
            value={body}
            onChange={setBody}
            zoom={zoom}
            labels={{
              zoomIn: t(copy.reportZoomIn),
              zoomOut: t(copy.reportZoomOut),
              bold: "B",
              italic: "I",
              underline: "U",
              heading: "H",
              list: "•",
              align: "↔",
              image: t(copy.reportCover),
              table: t(copy.reportBody),
            }}
          />
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
          <input type="file" multiple onChange={(event) => addFiles(event.target.files)} />
          <ul className="plain-list">
            {existing.filter((file) => !removeIds.includes(file.id)).map((file) => (
              <li key={file.id}>
                {file.name}
                <small> · {Math.ceil(file.size / 1024)} KB</small>
                <button type="button" className="btn btn-ghost" onClick={() => setRemoveIds((current) => [...current, file.id])}>{t(copy.delete)}</button>
              </li>
            ))}
            {files.map((file, index) => (
              <li key={`${file.name}-${index}`}>
                {file.name}
                <small> · {Math.ceil(file.size / 1024)} KB</small>
                <button type="button" className="btn btn-ghost" onClick={() => setFiles((current) => current.filter((_, item) => item !== index))}>{t(copy.delete)}</button>
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
