import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { api, type Client, type OdooQuotation } from "../api";
import { copy } from "../i18n";

type Line = { id: string; title: string; amount: string; units: string; notes: string };

function blankLine(): Line {
  return { id: crypto.randomUUID(), title: "", amount: "", units: "1", notes: "" };
}

function clientLabel(item: Client) {
  return item.company_name?.trim() || item.name;
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
  const clientListId = useId();
  const quoteListId = useId();
  const clientBox = useRef<HTMLLabelElement>(null);
  const quoteBox = useRef<HTMLLabelElement>(null);
  const [clients, setClients] = useState<Client[]>([]);
  const [clientQuery, setClientQuery] = useState("");
  const [clientId, setClientId] = useState("");
  const [clientOpen, setClientOpen] = useState(false);
  const [clientActive, setClientActive] = useState(0);
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [quotationId, setQuotationId] = useState(quotation ? String(quotation.id) : "");
  const [quoteQuery, setQuoteQuery] = useState(quotation?.name ?? "");
  const [quoteOpen, setQuoteOpen] = useState(false);
  const [quoteActive, setQuoteActive] = useState(0);
  const [quotations, setQuotations] = useState<OdooQuotation[]>(quotation ? [quotation] : []);
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const typedClient = useRef(false);

  useEffect(() => {
    let cancelled = false;
    const timer = window.setTimeout(() => {
      api.clients(1, { search: clientQuery.trim(), odoo: "linked", per_page: 50 })
        .then((res) => {
          if (cancelled) return;
          setClients(res.data.filter((item) => item.odoo_partner_id));
          setClientActive(0);
        })
        .catch(() => {
          if (!cancelled) setClients([]);
        });
    }, 250);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [clientQuery]);

  useEffect(() => {
    if (typedClient.current || clientId) return;
    const preset = partnerId
      ? clients.find((item) => item.odoo_partner_id === partnerId)
      : quotation?.partner_id
        ? clients.find((item) => item.odoo_partner_id === String(quotation.partner_id))
        : undefined;
    if (!preset) return;
    setClientId(String(preset.id));
    setClientQuery(clientLabel(preset));
  }, [clients, clientId, partnerId, quotation]);

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

  useEffect(() => {
    const onPointer = (event: PointerEvent) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      if (!clientBox.current?.contains(target)) setClientOpen(false);
      if (!quoteBox.current?.contains(target)) setQuoteOpen(false);
    };
    document.addEventListener("pointerdown", onPointer);
    return () => document.removeEventListener("pointerdown", onPointer);
  }, []);

  const visibleQuotes = quotations.filter((item) => {
    const needle = quoteQuery.trim().toLowerCase();
    if (!needle || (needle === item.name.toLowerCase() && String(item.id) === quotationId)) return true;
    return item.name.toLowerCase().includes(needle);
  });

  function pickClient(item: Client) {
    typedClient.current = true;
    setClientId(String(item.id));
    setClientQuery(clientLabel(item));
    setClientOpen(false);
    if (String(item.id) !== clientId) {
      setQuotationId(quotation && item.odoo_partner_id === String(quotation.partner_id) ? String(quotation.id) : "");
      setQuoteQuery(quotation && item.odoo_partner_id === String(quotation.partner_id) ? quotation.name : "");
    }
  }

  function onClientKey(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setClientOpen(true);
      setClientActive((index) => Math.min(index + 1, Math.max(clients.length - 1, 0)));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setClientActive((index) => Math.max(index - 1, 0));
    } else if (event.key === "Enter" && clientOpen && clients[clientActive]) {
      event.preventDefault();
      pickClient(clients[clientActive]);
    } else if (event.key === "Escape") {
      setClientOpen(false);
    }
  }

  function pickQuote(item: OdooQuotation | null) {
    setQuotationId(item ? String(item.id) : "");
    setQuoteQuery(item?.name ?? "");
    setQuoteOpen(false);
  }

  function onQuoteKey(event: KeyboardEvent<HTMLInputElement>) {
    const rows = visibleQuotes;
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setQuoteOpen(true);
      setQuoteActive((index) => Math.min(index + 1, Math.max(rows.length - 1, 0)));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setQuoteActive((index) => Math.max(index - 1, 0));
    } else if (event.key === "Enter" && quoteOpen) {
      event.preventDefault();
      pickQuote(rows[quoteActive] ?? null);
    } else if (event.key === "Escape") {
      setQuoteOpen(false);
    }
  }

  function updateLine(id: string, patch: Partial<Line>) {
    setLines((current) => current.map((line) => (line.id === id ? { ...line, ...patch } : line)));
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!selected) {
      setError(t(copy.paperChooseClient));
      setClientOpen(true);
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
        lines: !quotationId && filled.length
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

  const showLines = kind === "quotation" || !quotationId;

  return (
    <section className="card action-card odoo-paper-card">
      <h2 className="form-title">{t(kind === "quotation" ? copy.createQuotation : copy.createInvoice)}</h2>
      <form className="quotation-form" onSubmit={(event) => void onSubmit(event)}>
        <label className="field-label" ref={clientBox}>
          {t(copy.paperClient)}
          <span className="paper-suggest">
            <input
              className="field"
              role="combobox"
              aria-expanded={clientOpen}
              aria-controls={clientListId}
              aria-autocomplete="list"
              value={clientQuery}
              placeholder={t(copy.paperSearchClient)}
              onChange={(event) => {
                typedClient.current = true;
                setClientQuery(event.target.value);
                setClientId("");
                setClientOpen(true);
              }}
              onFocus={() => setClientOpen(true)}
              onKeyDown={onClientKey}
            />
            {clientOpen ? (
              <ul id={clientListId} className="paper-suggest-list" role="listbox">
                {clients.length === 0 ? (
                  <li className="paper-suggest-empty">{t(copy.paperNoLinkedClients)}</li>
                ) : clients.map((item, index) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={index === clientActive || String(item.id) === clientId}
                      onMouseEnter={() => setClientActive(index)}
                      onClick={() => pickClient(item)}
                    >
                      <span>{clientLabel(item)}</span>
                      {item.phone ? <span className="cell-detail">{item.phone}</span> : null}
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </span>
        </label>
        <label className="field-label">
          {t(copy.paperReference)}
          <input className="field" value={reference} onChange={(event) => setReference(event.target.value)} maxLength={64} />
        </label>
        {kind === "invoice" ? (
          <label className="field-label" ref={quoteBox}>
            {t(copy.paperQuotation)}
            <span className="paper-suggest">
              <input
                className="field"
                role="combobox"
                aria-expanded={quoteOpen}
                aria-controls={quoteListId}
                aria-autocomplete="list"
                value={quoteQuery}
                placeholder={t(copy.paperNoQuotation)}
                onChange={(event) => {
                  setQuoteQuery(event.target.value);
                  setQuotationId("");
                  setQuoteOpen(true);
                  setQuoteActive(0);
                }}
                onFocus={() => setQuoteOpen(true)}
                onKeyDown={onQuoteKey}
              />
              {quoteOpen ? (
                <ul id={quoteListId} className="paper-suggest-list" role="listbox">
                  <li>
                    <button type="button" role="option" aria-selected={quotationId === ""} onClick={() => pickQuote(null)}>
                      {t(copy.paperNoQuotation)}
                    </button>
                  </li>
                  {visibleQuotes.map((item, index) => (
                    <li key={item.id}>
                      <button
                        type="button"
                        role="option"
                        aria-selected={index === quoteActive || String(item.id) === quotationId}
                        onMouseEnter={() => setQuoteActive(index)}
                        onClick={() => pickQuote(item)}
                      >
                        <span>{item.name}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </span>
            {quotationId ? <p className="muted">{t(copy.paperFromQuotation)}</p> : null}
          </label>
        ) : (
          <label className="field-label">
            {t(copy.quotationNotes)}
            <input className="field" value={notes} onChange={(event) => setNotes(event.target.value)} />
          </label>
        )}
        {showLines ? (
          <div className="quotation-lines">
            {lines.map((line, index) => (
              <div className="quotation-line" key={line.id}>
                <span className="quotation-line-index">{index + 1}</span>
                <input
                  className="field"
                  value={line.title}
                  onChange={(event) => updateLine(line.id, { title: event.target.value })}
                  placeholder={t(copy.lineTitle)}
                  required
                />
                <input
                  className="field"
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={line.amount}
                  onChange={(event) => updateLine(line.id, { amount: event.target.value })}
                  placeholder={t(copy.amount)}
                  required
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
        ) : null}
        {error ? <p className="error">{error}</p> : null}
        <div className="quotation-form-actions">
          {showLines ? (
            <button type="button" className="btn btn-ghost" onClick={() => setLines((current) => [...current, blankLine()])} disabled={busy}>
              + {t(copy.addQuotationLine)}
            </button>
          ) : <span />}
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
