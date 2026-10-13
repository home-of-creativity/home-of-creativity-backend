import { useEffect, useState, type FormEvent } from "react";
import { X } from "lucide-react";
import { api, type OdooInvoice, type OdooPaperLine, type OdooQuotation } from "../api";
import { copy } from "../i18n";

export type InvoiceMethod = "delivered" | "percentage" | "fixed";

export type InvoiceDraft = OdooInvoice & { lines: OdooPaperLine[]; quotation_id: number };

function money(value: number) {
  return `$ ${value.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Odoo's "Create invoice" on a quotation: a regular invoice of what is left, or a down
 * payment by percentage or fixed amount. It makes a draft and hands it back for review.
 */
export function InvoiceWizardDialog({
  quotation,
  t,
  onClose,
  onDraft,
}: {
  quotation: OdooQuotation;
  t: (c: { ar: string; en: string }) => string;
  onClose: () => void;
  onDraft: (draft: InvoiceDraft, message: string) => void;
}) {
  const [method, setMethod] = useState<InvoiceMethod>("delivered");
  const [percent, setPercent] = useState("50");
  const [fixed, setFixed] = useState("");
  const [invoiced, setInvoiced] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    api.odooQuotation(quotation.id)
      .then((res) => {
        if (!cancelled) setInvoiced(res.data.invoiced ?? 0);
      })
      .catch(() => {
        if (!cancelled) setInvoiced(null);
      });
    return () => {
      cancelled = true;
    };
  }, [quotation.id]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const amount = method === "percentage" ? Number(percent) : method === "fixed" ? Number(fixed) : undefined;
    if (amount !== undefined && (!Number.isFinite(amount) || amount <= 0 || (method === "percentage" && amount > 100))) {
      setError(t(copy.invoiceWizardAmountInvalid));
      return;
    }
    setBusy(true);
    setError("");
    try {
      const res = await api.invoiceOdooQuotation(quotation.id, { method, amount });
      onDraft(res.data, res.message ?? t(copy.saved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const options: Array<{ id: InvoiceMethod; label: { ar: string; en: string } }> = [
    { id: "delivered", label: copy.invoiceWizardRegular },
    { id: "percentage", label: copy.invoiceWizardPercent },
    { id: "fixed", label: copy.invoiceWizardFixed },
  ];

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="invoice-wizard-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel invoice-wizard">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="invoice-wizard-title">
            {t(copy.invoiceWizardTitle)} <span className="muted" dir="ltr">{quotation.name}</span>
          </h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={t(copy.cancel)}>
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={(event) => void submit(event)}>
          <div className="form-dialog-body invoice-wizard-body">
            <fieldset className="invoice-wizard-row">
              <legend>{t(copy.invoiceWizardCreate)}</legend>
              <div className="invoice-wizard-options">
                {options.map((option) => (
                  <label key={option.id}>
                    <input type="radio" name="invoice-method" value={option.id} checked={method === option.id} onChange={() => setMethod(option.id)} />
                    {t(option.label)}
                  </label>
                ))}
              </div>
            </fieldset>
            {method === "percentage" ? (
              <label className="invoice-wizard-row">
                <span>{t(copy.invoiceWizardDownPayment)}</span>
                <span className="invoice-wizard-amount" dir="ltr">
                  <input className="field" type="number" min="0.01" max="100" step="0.01" value={percent} onChange={(event) => setPercent(event.target.value)} autoFocus />
                  <span>%</span>
                </span>
              </label>
            ) : null}
            {method === "fixed" ? (
              <label className="invoice-wizard-row">
                <span>{t(copy.invoiceWizardDownPayment)}</span>
                <span className="invoice-wizard-amount" dir="ltr">
                  <span>$</span>
                  <input className="field" type="number" min="0.01" step="0.01" value={fixed} onChange={(event) => setFixed(event.target.value)} autoFocus />
                </span>
              </label>
            ) : null}
            <div className="invoice-wizard-row is-muted">
              <span>{t(copy.invoiceWizardInvoiced)}</span>
              <strong dir="ltr">{invoiced === null ? "…" : money(invoiced)}</strong>
            </div>
            <p className="muted invoice-wizard-note">
              {method === "delivered" ? t(copy.invoiceWizardRegularNote) : t(copy.invoiceWizardDownNote)}
            </p>
            {error ? <p className="error">{error}</p> : null}
          </div>
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={busy} aria-busy={busy}>
              {busy ? t(copy.creatingPaper) : t(copy.invoiceWizardDraft)}
            </button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>{t(copy.cancel)}</button>
          </div>
        </form>
      </div>
    </div>
  );
}
