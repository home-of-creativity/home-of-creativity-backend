import { Download, Printer, X } from "lucide-react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, type OdooPaperKind } from "../api";
import { copy } from "../i18n";

/** The quotation, its pro-forma invoice, or the invoice PDF as Odoo prints it, with print and download. */
export function OdooPaperPreview({
  kind,
  id,
  name,
  proforma = false,
  actions,
  t,
  onClose,
}: {
  kind: OdooPaperKind;
  id: number;
  name: string;
  proforma?: boolean;
  /** Extra controls in the header, e.g. sending the pro-forma. */
  actions?: ReactNode;
  t: (c: { ar: string; en: string }) => string;
  onClose: () => void;
}) {
  const frame = useRef<HTMLIFrameElement>(null);
  const [url, setUrl] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    let objectUrl = "";
    api.odooPaperPdf(kind, id, proforma)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof Error ? err.message : String(err));
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [kind, id, proforma]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  function print() {
    const view = frame.current?.contentWindow;
    if (!view) return;
    view.focus();
    view.print();
  }

  const fileName = `${proforma ? "proforma-" : ""}${name.replace(/[\\/:*?"<>|]+/g, "-") || id}.pdf`;

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="odoo-preview-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg odoo-preview-panel">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="odoo-preview-title">{t(proforma ? copy.paperProforma : copy.paperPreviewTitle)} {name}</h2>
          <div className="odoo-preview-actions">
            {actions}
            <button type="button" className="btn btn-ghost" onClick={print} disabled={!url}>
              <Printer size={16} aria-hidden /> {t(copy.paperPrint)}
            </button>
            {url ? (
              <a className="btn btn-ghost" href={url} download={fileName}>
                <Download size={16} aria-hidden /> {t(copy.paperDownload)}
              </a>
            ) : null}
            <button type="button" className="form-dialog-close" onClick={onClose} aria-label={t(copy.cancel)}>
              <X aria-hidden />
            </button>
          </div>
        </div>
        <div className="odoo-preview-body">
          {error ? <p className="error">{error}</p> : url ? (
            <iframe ref={frame} className="odoo-preview-frame" src={url} title={name} />
          ) : (
            <p className="muted">{t(copy.paperPreviewLoading)}</p>
          )}
        </div>
      </div>
    </div>
  );
}
