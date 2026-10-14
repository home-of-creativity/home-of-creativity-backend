import { Send } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { api } from "../api";
import { copy } from "../i18n";
import { OdooPaperPreview } from "./OdooPaperPreview";

type Deliver = "both" | "email" | "whatsapp";

/** The order's pro-forma invoice: Odoo's PDF to print, and sending it to the client. */
export function ProformaDialog({
  id,
  name,
  t,
  onClose,
}: {
  id: number;
  name: string;
  t: (c: { ar: string; en: string }) => string;
  onClose: () => void;
}) {
  const [deliver, setDeliver] = useState<Deliver>("both");
  const [busy, setBusy] = useState(false);

  async function send() {
    setBusy(true);
    try {
      const res = await api.sendOdooProforma(id, deliver);
      toast.success(res.message || t(copy.paperProformaSent));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  return (
    <OdooPaperPreview
      kind="quotations"
      id={id}
      name={name}
      proforma
      t={t}
      onClose={onClose}
      actions={(
        <span className="proforma-send">
          <select className="field" value={deliver} onChange={(event) => setDeliver(event.target.value as Deliver)} aria-label={t(copy.paperDeliver)} disabled={busy}>
            <option value="both">{t(copy.paperDeliverBoth)}</option>
            <option value="email">{t(copy.paperDeliverEmail)}</option>
            <option value="whatsapp">{t(copy.paperDeliverWhatsApp)}</option>
          </select>
          <button type="button" className="btn btn-primary" onClick={() => void send()} disabled={busy} aria-busy={busy}>
            <Send size={16} aria-hidden /> {busy ? t(copy.paperProformaSending) : t(copy.paperProformaSend)}
          </button>
        </span>
      )}
    />
  );
}
