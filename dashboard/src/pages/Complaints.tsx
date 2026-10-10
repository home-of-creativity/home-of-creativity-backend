import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { api, type ComplaintRow } from "../api";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

function when(iso: string | null) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso.replace("T", " ").slice(0, 16);
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function ComplaintsPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [rows, setRows] = useState<ComplaintRow[] | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState("");
  const [images, setImages] = useState<Record<number, string>>({});
  const [audio, setAudio] = useState<Record<number, string>>({});
  const urls = useRef<string[]>([]);

  async function load() {
    const res = await api.complaints();
    setRows(res.data);
  }

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  useEffect(() => {
    return () => {
      urls.current.forEach((url) => URL.revokeObjectURL(url));
    };
  }, []);

  async function mark(row: ComplaintRow) {
    const next = row.status === "reviewed" ? "open" : "reviewed";
    setBusy(String(row.id));
    try {
      await api.updateComplaint(row.id, next);
      toast.success(t(copy.saved));
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  async function openFile(row: ComplaintRow, kind: "image" | "audio") {
    const current = kind === "image" ? images[row.id] : audio[row.id];
    if (current) return;
    setBusy(`${kind}:${row.id}`);
    try {
      const blob = await api.complaintFile(row.id, kind);
      const url = URL.createObjectURL(blob);
      urls.current.push(url);
      if (kind === "image") setImages((prev) => ({ ...prev, [row.id]: url }));
      else setAudio((prev) => ({ ...prev, [row.id]: url }));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  return (
    <section>
      <PageHeader title={t(copy.navComplaints)} lede={t(copy.complaintsLede)} />
      {error ? <p className="form-error">{error}</p> : null}
      {rows === null && !error ? <LoadingLottie variant="page" label={t(copy.loading)} /> : null}
      {rows && rows.length === 0 ? <p>{locale === "ar" ? "ما في شكاوى بعد." : "No complaints yet."}</p> : null}
      {rows && rows.length > 0 ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{locale === "ar" ? "العميل" : "Client"}</th>
                <th>{locale === "ar" ? "العنوان" : "Title"}</th>
                <th>{locale === "ar" ? "الوصف" : "Description"}</th>
                <th>{locale === "ar" ? "الملفات" : "Files"}</th>
                <th>{locale === "ar" ? "الحالة" : "Status"}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td>
                    <div>{row.company_name || row.client_name || "—"}</div>
                    <div dir="ltr">{row.phone || ""}</div>
                    <div>{when(row.created_at)}</div>
                  </td>
                  <td>{row.title}</td>
                  <td>{row.description}</td>
                  <td>
                    {row.has_image ? (
                      <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => openFile(row, "image")}>
                        {locale === "ar" ? "صورة" : "Photo"}
                      </button>
                    ) : null}
                    {images[row.id] ? <img src={images[row.id]} alt="" style={{ display: "block", marginTop: 8, maxWidth: 160 }} /> : null}
                    {row.has_audio ? (
                      <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => openFile(row, "audio")}>
                        {locale === "ar" ? "تسجيل" : "Voice"}
                      </button>
                    ) : null}
                    {audio[row.id] ? <audio controls src={audio[row.id]} style={{ display: "block", marginTop: 8 }} /> : null}
                  </td>
                  <td>
                    <button type="button" className="btn btn-primary" disabled={busy !== ""} onClick={() => mark(row)}>
                      {row.status === "reviewed"
                        ? locale === "ar" ? "إرجاعها جديدة" : "Mark open"
                        : locale === "ar" ? "تمت المراجعة" : "Mark reviewed"}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
