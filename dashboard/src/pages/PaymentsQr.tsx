import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, type OpsSettings } from "../api";
import { FileDropzone } from "../components/FileDropzone";
import { copy, type Locale } from "../i18n";
import { formatWhen } from "./social/helpers";

function useShamCashQr(t: (c: { ar: string; en: string }) => string) {
  const [settings, setSettings] = useState<OpsSettings | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function refresh() {
    const res = await api.opsSettings();
    setSettings(res.data);
    if (!res.data.sham_cash_qr) {
      setPreview((current) => {
        if (current) URL.revokeObjectURL(current);
        return null;
      });
      return;
    }
    const blob = await api.shamCashQrBlob();
    const url = URL.createObjectURL(blob);
    setPreview((current) => {
      if (current) URL.revokeObjectURL(current);
      return url;
    });
  }

  useEffect(() => {
    refresh().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
    return () => {
      setPreview((current) => {
        if (current) URL.revokeObjectURL(current);
        return null;
      });
    };
  }, [t]);

  async function upload(files: File[]) {
    const file = files[0];
    if (!file) return;
    setBusy(true);
    setError("");
    try {
      await api.uploadShamCashQr(file);
      await refresh();
      toast.success(t(copy.qrSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  return { settings, preview, error, busy, upload };
}

export function ShamCashQrThumb({ alt }: { alt: string }) {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;
    api
      .opsSettings()
      .then(async (res) => {
        if (cancelled || !res.data.sham_cash_qr) return;
        const blob = await api.shamCashQrBlob();
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, []);

  if (!url) return null;
  return <img className="qr-thumb" src={url} alt={alt} />;
}

export function PaymentsQr({ locale, t }: { locale?: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { settings, preview, error, busy, upload } = useShamCashQr(t);
  const lang: Locale = locale ?? (document.documentElement.lang === "en" ? "en" : "ar");

  return (
    <div className="qr-studio">
      <section className="qr-phone-col">
        <p className="eyebrow">{t(copy.socialQrPreview)}</p>
        <div className="qr-phone">
          <div className="qr-phone-notch" aria-hidden />
          <div className="qr-chat" dir={lang === "ar" ? "rtl" : "ltr"}>
            <p className="qr-chat-from">Telegram</p>
            <p className="qr-chat-caption">{t(copy.qrClientCaption)}</p>
            {preview ? (
              <img className="qr-preview" src={preview} alt={t(copy.shamCashQr)} />
            ) : (
              <p className="muted qr-chat-empty">{t(copy.qrMissing)}</p>
            )}
          </div>
        </div>
        {settings?.sham_cash_qr_updated_at ? (
          <p className="muted">
            {t(copy.qrUpdatedAt)} · {formatWhen(settings.sham_cash_qr_updated_at, lang)}
          </p>
        ) : null}
      </section>
      <div className="qr-manage">
        <section className="card stack qr-panel">
          <h2 className="form-title">{t(copy.qrPreview)}</h2>
          <p className="muted">{t(copy.shamCashQrHelp)}</p>
          {error ? <p className="error">{error}</p> : null}
          <FileDropzone
            accept={{ "image/png": [], "image/jpeg": [], "image/webp": [] }}
            disabled={busy}
            hint={settings?.sham_cash_qr ? t(copy.replaceQr) : t(copy.uploadQr)}
            activeHint={t(copy.dropzoneActive)}
            onFiles={(files) => void upload(files)}
          />
        </section>
        <ol className="qr-steps" aria-label={t(copy.qrFlowTitle)}>
          <li>
            <h3>{t(copy.qrStep1)}</h3>
            <p>{t(copy.qrStep1Body)}</p>
          </li>
          <li>
            <h3>{t(copy.qrStep2)}</h3>
            <p>{t(copy.qrStep2Body)}</p>
          </li>
          <li>
            <h3>{t(copy.qrStep3)}</h3>
            <p>{t(copy.qrStep3Body)}</p>
          </li>
          <li>
            <h3>{t(copy.qrStep4)}</h3>
            <p>{t(copy.qrStep4Body)}</p>
            <Link className="btn btn-ghost" to="/requests">
              {t(copy.requests)}
            </Link>
          </li>
        </ol>
      </div>
    </div>
  );
}
