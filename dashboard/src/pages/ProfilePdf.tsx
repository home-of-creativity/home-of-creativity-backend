import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type ProfilePdf as ProfilePdfPayload } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { FileDropzone } from "../components/FileDropzone";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";
import { formatWhen } from "./social/helpers";

function previewSrc(pdf: ProfilePdfPayload | null) {
  if (!pdf?.url) return null;
  const stamp = pdf.updated_at ? encodeURIComponent(pdf.updated_at) : String(Date.now());
  return `${pdf.url}${pdf.url.includes("?") ? "&" : "?"}t=${stamp}`;
}

export function ProfilePdf({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [pdf, setPdf] = useState<ProfilePdfPayload | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function refresh() {
    const res = await api.profilePdf();
    setPdf(res.data);
  }

  useEffect(() => {
    refresh().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  async function upload(files: File[]) {
    const file = files[0];
    if (!file) return;
    setBusy(true);
    setError("");
    try {
      const res = await api.uploadProfilePdf(file);
      setPdf(res.data);
      toast.success(t(copy.profilePdfSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    setError("");
    try {
      const res = await api.deleteProfilePdf();
      setPdf(res.data);
      toast.success(t(copy.deleted));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const src = previewSrc(pdf);

  return (
    <>
      <PageHeader title={t(copy.profilePdfTitle)} lede={t(copy.profilePdfLede)} />
      <div className="pdf-studio">
        <section className="card stack pdf-preview-panel">
          <h2 className="form-title">{t(copy.profilePdfPreview)}</h2>
          {src ? (
            <iframe className="pdf-preview" title={pdf?.name ?? t(copy.profilePdfTitle)} src={src} />
          ) : (
            <p className="muted">{t(copy.profilePdfMissing)}</p>
          )}
          {pdf?.name ? <p className="muted">{pdf.name}</p> : null}
          {pdf?.updated_at ? (
            <p className="muted">
              {t(copy.qrUpdatedAt)} · {formatWhen(pdf.updated_at, locale)}
            </p>
          ) : null}
          {src ? (
            <a className="btn btn-ghost" href={src} target="_blank" rel="noopener noreferrer">
              {t(copy.profilePdfOpen)}
            </a>
          ) : null}
        </section>
        <section className="card stack pdf-manage">
          <h2 className="form-title">{t(copy.profilePdfManage)}</h2>
          <p className="muted">{t(copy.profilePdfHelp)}</p>
          {error ? <p className="error">{error}</p> : null}
          <FileDropzone
            accept={{ "application/pdf": [".pdf"] }}
            disabled={busy}
            hint={pdf?.url ? t(copy.replacePdf) : t(copy.uploadPdf)}
            activeHint={t(copy.dropzoneActive)}
            onFiles={(files) => void upload(files)}
          />
          {pdf?.url ? (
            <ConfirmAction
              label={t(copy.delete)}
              confirmLabel={t(copy.confirmDelete)}
              yesLabel={t(copy.delete)}
              noLabel={t(copy.cancel)}
              disabled={busy}
              onConfirm={() => void remove()}
            />
          ) : null}
        </section>
      </div>
    </>
  );
}
