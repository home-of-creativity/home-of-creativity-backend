import { useEffect, useState, type FormEvent } from "react";
import { api, type Client, type OdooQuotation } from "../api";
import { copy } from "../i18n";

type Line = { id: string; title: string; amount: string; units: string; notes: string };

function blankLine(): Line {
  return { id: crypto.randomUUID(), title: "", amount: "", units: "1", notes: "" };
}

export function OdooPaperForm({
  kind,
  partnerId,
  quotation,
  t,
  onCancel,
  onCreated,
}: {
  kind: "quotation" | "invoice";
  partnerId?: string;
  quotation?: OdooQuotation | null;
  t: (c: { ar: string; en: string }) => string;
  onCancel: () => void;
  onCreated: (message: string) => void;
}) {
  const [clients, setClients] = useState<Client[]>([]);
  const [clientQuery, setClientQuery] = useState("");
  const [clientId, setClientId] = useState("");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [quotationId, setQuotationId] = useState(quotation ? String(quotation.id) : "");
  const [quotations, setQuotations] = useState<OdooQuotation[]>(quotation ? [quotation] : []);
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    const timer = window.setTimeout(() => {
      api.clients(1, { search: clientQuery.trim(), odoo: "linked", per_page: 50 })
        .then((res) => {
          if (cancelled) return;
          const linked = res.data.filter((item) => item.odoo_partner_id);
          setClients(linked);
          setClientId((current) => {
            if (current && linked.some((item) => String(item.id) === current)) return current;
            const preset = partnerId
              ? linked.find((item) => item.odoo_partner_id === partnerId)
              : quotation?.partner_id
                ? linked.find((item) => item.odoo_partner_id === String(quotation.partner_id))
                : undefined;
            return preset ? String(preset.id) : "";
          });
        })
        .catch(() => {
          if (!cancelled) setClients([]);
        });
    }, 250);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [clientQuery, partnerId, quotation]);

  const selected = clients.find((item) => String(item.id) === clientId);

  useEffect(() => {
    if (kind !== "invoice" || !selected?.odoo_partner_id) {
      setQuotations(quotation ? [quotation] : []);
      return;
    }
    let cancelled = false;
    api.odooQuotations(selected.odoo_partner_id)
      .then((res) => {
        if (cancelled) return;
        const rows = res.data;
        if (quotation && !rows.some((item) => item.id === quotation.id)) rows.unshift(quotation);
        setQuotations(rows);
      })
      .catch(() => {
        if (!cancelled) setQuotations(quotation ? [quotation] : []);
      });
    return () => {
      cancelled = true;
    };
  }, [kind, selected?.odoo_partner_id, quotation]);

  function updateLine(id: string, patch: Partial<Line>) {
    setLines((current) => current.map((line) => (line.id === id ? { ...line, ...patch } : line)));
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!selected) {
      setError(t(copy.paperChooseClient));
      return;
    }
    const filled = lines
      .map((line) => ({
        title: line.title.trim(),
        amount: Number(line.amount),
        units: Number(line.units || "1"),
        notes: line.notes.trim(),
      }))
      .filter((line) => line.title !== "");
    if (kind === "quotation" && filled.length === 0) {
      setError(t(copy.fieldRequired));
      return;
    }
    if (kind === "invoice" && filled.length === 0 && !quotationId) {
      setError(t(copy.fieldRequired));
      return;
    }
    setBusy(true);
    setError("");
    try {
      if (kind === "quotation") {
        const res = await api.createOdooQuotation({
          client_id: selected.id,
          reference: reference.trim() || undefined,
          notes: notes.trim() || undefined,
          lines: filled.map((line) => ({ ...line, notes: line.notes || undefined })),
        });
        onCreated(res.message || t(copy.quotationCreated));
        return;
      }
      const res = await api.createOdooInvoice({
        client_id: selected.id,
        quotation_id: quotationId ? Number(quotationId) : undefined,
        reference: reference.trim() || undefined,
        lines: filled.length
          ? filled.map((line) => ({ ...line, notes: line.notes || undefined }))
          : undefined,
      });
      onCreated(res.message || t(copy.invoiceCreated));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card action-card odoo-paper-card">
      <h2 className="form-title">{t(kind === "quotation" ? copy.createQuotation : copy.createInvoice)}</h2>
      <form className="quotation-form" onSubmit={(event) => void onSubmit(event)}>
        <label className="field-label">
          {t(copy.paperSearchClient)}
          <input
            className="field"
            value={clientQuery}
            onChange={(event) => setClientQuery(event.target.value)}
            placeholder={t(copy.paperSearchClient)}
          />
        </label>
        <label className="field-label">
          {t(copy.paperClient)}
          <select className="field" value={clientId} onChange={(event) => setClientId(event.target.value)} required>
            <option value="">{t(copy.paperChooseClient)}</option>
            {clients.map((item) => (
              <option key={item.id} value={item.id}>
                {item.company_name?.trim() || item.name}
              </option>
            ))}
          </select>
        </label>
        {clients.length === 0 ? <p className="muted">{t(copy.paperNoLinkedClients)}</p> : null}
        <label className="field-label">
          {t(copy.paperReference)}
          <input className="field" value={reference} onChange={(event) => setReference(event.target.value)} maxLength={64} />
        </label>
        {kind === "invoice" ? (
          <>
            <label className="field-label">
              {t(copy.paperQuotation)}
              <select className="field" value={quotationId} onChange={(event) => setQuotationId(event.target.value)}>
                <option value="">{t(copy.paperNoQuotation)}</option>
                {quotations.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
            {quotationId ? <p className="muted">{t(copy.paperFromQuotation)}</p> : null}
          </>
        ) : (
          <label className="field-label">
            {t(copy.quotationNotes)}
            <input className="field" value={notes} onChange={(event) => setNotes(event.target.value)} />
          </label>
        )}
        <div className="quotation-lines">
          {lines.map((line, index) => (
            <div className="quotation-line" key={line.id}>
              <span className="quotation-line-index">{index + 1}</span>
              <input
                className="field"
                value={line.title}
                onChange={(event) => updateLine(line.id, { title: event.target.value })}
                placeholder={t(copy.lineTitle)}
                required={kind === "quotation" || !quotationId}
              />
              <input
                className="field"
                type="number"
                step="0.01"
                min="0.01"
                value={line.amount}
                onChange={(event) => updateLine(line.id, { amount: event.target.value })}
                placeholder={t(copy.amount)}
                required={kind === "quotation" || !quotationId}
              />
              <input
                className="field quotation-line-units"
                type="number"
                step="0.01"
                min="0.01"
                value={line.units}
                onChange={(event) => updateLine(line.id, { units: event.target.value })}
                placeholder={t(copy.lineUnits)}
                aria-label={t(copy.lineUnits)}
              />
              <input
                className="field"
                value={line.notes}
                onChange={(event) => updateLine(line.id, { notes: event.target.value })}
                placeholder={t(copy.quotationNotes)}
              />
              {lines.length > 1 ? (
                <button
                  type="button"
                  className="btn btn-ghost quotation-line-remove"
                  aria-label={t(copy.removeQuotationLine)}
                  onClick={() => setLines((current) => current.filter((item) => item.id !== line.id))}
                >
                  ×
                </button>
              ) : (
                <span className="quotation-line-spacer" aria-hidden="true" />
              )}
            </div>
          ))}
        </div>
        {error ? <p className="error">{error}</p> : null}
        <div className="quotation-form-actions">
          <button type="button" className="btn btn-ghost" onClick={() => setLines((current) => [...current, blankLine()])} disabled={busy}>
            + {t(copy.addQuotationLine)}
          </button>
          <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>
            {t(copy.cancel)}
          </button>
          <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy}>
            {busy ? t(copy.creatingPaper) : t(kind === "quotation" ? copy.createQuotation : copy.createInvoice)}
          </button>
        </div>
      </form>
    </section>
  );
}
