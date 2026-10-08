import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { api, type Client, type OdooProduct, type OdooQuotation } from "../api";
import { copy } from "../i18n";

type LineKind = "product" | "section" | "note";
type Line = { id: string; kind: LineKind; title: string; amount: string; units: string; discount: string; productId?: number };
type SendMode = "draft" | "send";

function blankLine(kind: LineKind = "product"): Line {
  return { id: crypto.randomUUID(), kind, title: "", amount: "", units: "1", discount: "0" };
}

function isoDate(offsetDays = 0) {
  const date = new Date();
  date.setDate(date.getDate() + offsetDays);
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${day}`;
}

function clientLabel(item: Client) {
  return item.company_name?.trim() || item.name;
}

function lineTotal(line: Line) {
  if (line.kind !== "product") return 0;
  const price = Number(line.amount);
  const units = Number(line.units || "1");
  const discount = Math.min(100, Math.max(0, Number(line.discount || "0")));
  if (!Number.isFinite(price) || !Number.isFinite(units)) return 0;
  return price * units * (1 - discount / 100);
}

function money(value: number) {
  return `$ ${value.toFixed(2)}`;
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
  const sendMode = useRef<SendMode>("draft");
  const [clients, setClients] = useState<Client[]>([]);
  const [clientQuery, setClientQuery] = useState("");
  const [clientId, setClientId] = useState("");
  const [clientOpen, setClientOpen] = useState(false);
  const [clientActive, setClientActive] = useState(0);
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [emailOn, setEmailOn] = useState(true);
  const [whatsappOn, setWhatsappOn] = useState(true);
  const [products, setProducts] = useState<OdooProduct[]>([]);
  const [productsReady, setProductsReady] = useState(false);
  const [quoteDate, setQuoteDate] = useState(isoDate());
  const [validityDate, setValidityDate] = useState(isoDate(7));
  const [invoiceDate, setInvoiceDate] = useState(isoDate());
  const [dueDate, setDueDate] = useState(isoDate());
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
    api.odooProducts()
      .then((res) => {
        if (!cancelled) setProducts(res.data);
      })
      .catch(() => {
        if (!cancelled) setProducts([]);
      })
      .finally(() => {
        if (!cancelled) setProductsReady(true);
      });
    return () => {
      cancelled = true;
    };
  }, []);

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

  function addProduct(product: OdooProduct) {
    setLines((current) => {
      if (current.some((line) => line.productId === product.id)) return current;
      const next: Line = {
        id: `odoo-${product.id}`,
        kind: "product",
        productId: product.id,
        title: product.name,
        amount: String(product.price),
        units: "1",
        discount: "0",
      };
      const kept = current.filter((line) => line.title.trim() !== "" || line.kind !== "product" || line.productId);
      return [...kept, next];
    });
  }

  function setProductUnits(productId: number, units: number) {
    const nextUnits = Math.max(0, Math.round(units));
    setLines((current) => {
      if (nextUnits <= 0) {
        const next = current.filter((line) => line.productId !== productId);
        return next.length > 0 ? next : [blankLine()];
      }
      return current.map((line) => (line.productId === productId ? { ...line, units: String(nextUnits) } : line));
    });
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    const mode = sendMode.current;
    if (!selected) {
      setError(t(copy.paperChooseClient));
      setClientOpen(true);
      return;
    }
    if (mode === "send" && !emailOn && !whatsappOn) {
      setError(t(copy.paperChooseChannel));
      return;
    }
    const deliver = emailOn && whatsappOn ? "both" : emailOn ? "email" : "whatsapp";
    const filled: Array<{ title: string; amount?: number; units?: number; discount?: number; product_id?: number; display_type?: "line_section" | "line_note" }> = [];
    for (const line of lines) {
      const title = line.title.trim();
      if (title === "") continue;
      if (line.kind === "section" || line.kind === "note") {
        filled.push({ title, display_type: line.kind === "section" ? "line_section" : "line_note" });
        continue;
      }
      const amount = Number(line.amount);
      if (!Number.isFinite(amount) || amount <= 0) continue;
      const discount = Number(line.discount || "0");
      filled.push({
        title,
        amount,
        units: Number(line.units || "1"),
        discount: Number.isFinite(discount) && discount > 0 ? discount : undefined,
        product_id: line.productId,
      });
    }
    const products = filled.filter((line) => line.display_type === undefined);
    if (kind === "quotation" && products.length === 0) {
      setError(t(copy.fieldRequired));
      return;
    }
    if (kind === "invoice" && products.length === 0 && !quotationId) {
      setError(t(copy.fieldRequired));
      return;
    }
    setBusy(true);
    setError("");
    try {
      if (kind === "quotation") {
        const res = await api.createOdooQuotation({
          client_id: selected.id,
          action: mode === "send" ? "send" : "draft",
          deliver: mode === "send" ? deliver : undefined,
          reference: reference.trim() || undefined,
          notes: notes.trim() || undefined,
          date_order: quoteDate || undefined,
          validity_date: validityDate || undefined,
          lines: filled,
        });
        onCreated(res.message || t(copy.quotationCreated));
        return;
      }
      const res = await api.createOdooInvoice({
        client_id: selected.id,
        action: mode === "send" ? "post" : "draft",
        deliver: mode === "send" ? deliver : undefined,
        quotation_id: quotationId ? Number(quotationId) : undefined,
        reference: reference.trim() || undefined,
        invoice_date: invoiceDate || undefined,
        due_date: dueDate || undefined,
        lines: !quotationId && filled.length ? filled : undefined,
      });
      onCreated(res.message || t(copy.invoiceCreated));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const showLines = kind === "quotation" || !quotationId;
  const steps = kind === "quotation"
    ? [copy.paperStatusQuotation, copy.paperStatusSent]
    : [copy.paperStatusDraft, copy.paperStatusPosted];
  const total = lines.reduce((sum, line) => sum + lineTotal(line), 0);

  return (
    <section className="card action-card odoo-paper-card">
      <div className="odoo-paper-top">
        <nav className="odoo-statusbar" aria-label={t(copy.odooState)}>
          {steps.map((step, index) => (
            <span key={step.ar} className={index === 0 ? "is-current" : undefined}>{t(step)}</span>
          ))}
        </nav>
        <h2 className="form-title">{t(kind === "quotation" ? copy.createQuotation : copy.createInvoice)}</h2>
      </div>
      <form className="quotation-form" onSubmit={(event) => void onSubmit(event)}>
        <div className="odoo-paper-head">
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
          {kind === "quotation" ? (
            <>
              <label className="field-label">
                {t(copy.paperValidity)}
                <input className="field" type="date" value={validityDate} onChange={(event) => setValidityDate(event.target.value)} />
              </label>
              <label className="field-label">
                {t(copy.paperQuoteDate)}
                <input className="field" type="date" value={quoteDate} onChange={(event) => setQuoteDate(event.target.value)} />
              </label>
            </>
          ) : (
            <>
              <label className="field-label">
                {t(copy.paperInvoiceDate)}
                <input className="field" type="date" value={invoiceDate} onChange={(event) => setInvoiceDate(event.target.value)} />
              </label>
              <label className="field-label">
                {t(copy.paperDueDate)}
                <input className="field" type="date" value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
              </label>
              <label className="field-label">
                {t(copy.paperJournal)}
                <input className="field" value={t(copy.paperSalesJournal)} readOnly />
              </label>
            </>
          )}
          <label className="field-label">
            {t(copy.paperPaymentTerms)}
            <input className="field" value={t(copy.paperImmediate)} readOnly />
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
          ) : null}
        </div>

        <fieldset className="odoo-deliver">
          <legend>{t(copy.paperDeliver)}</legend>
          <label>
            <input type="checkbox" checked={emailOn} onChange={(event) => setEmailOn(event.target.checked)} />
            {t(copy.paperDeliverEmail)}
            {selected?.email ? <span dir="ltr">{selected.email}</span> : null}
          </label>
          <label>
            <input type="checkbox" checked={whatsappOn} onChange={(event) => setWhatsappOn(event.target.checked)} />
            {t(copy.paperDeliverWhatsApp)}
            {selected?.phone ? <span dir="ltr">{selected.phone}</span> : null}
          </label>
        </fieldset>

        {showLines ? (
          <div className="odoo-product-grid">
            {!productsReady ? null : products.length === 0 ? (
              <p className="muted">{t(copy.paperNoProducts)}</p>
            ) : products.map((product) => {
              const qty = Number(lines.find((line) => line.productId === product.id)?.units || "0");
              return (
                <article className="odoo-product-card" key={product.id}>
                  <strong>{product.name}</strong>
                  <span className="odoo-product-price" dir="ltr">{money(product.price)}</span>
                  {qty > 0 ? (
                    <div className="odoo-qty" role="group" aria-label={t(copy.paperQty)}>
                      <button type="button" onClick={() => setProductUnits(product.id, qty - 1)} disabled={busy}>−</button>
                      <span>{qty}</span>
                      <button type="button" onClick={() => setProductUnits(product.id, qty + 1)} disabled={busy}>+</button>
                    </div>
                  ) : (
                    <button type="button" className="btn btn-ghost" onClick={() => addProduct(product)} disabled={busy}>
                      {t(copy.paperAdd)}
                    </button>
                  )}
                </article>
              );
            })}
          </div>
        ) : null}

        {showLines ? (
          <div className="table-wrap">
            <table className="odoo-table odoo-line-table">
              <thead>
                <tr>
                  <th>{t(copy.paperProduct)}</th>
                  {kind === "invoice" ? <th>{t(copy.paperAccount)}</th> : null}
                  <th>{t(copy.paperQty)}</th>
                  <th>{t(copy.paperUnitPrice)}</th>
                  <th>{t(copy.paperTax)}</th>
                  <th>{t(copy.paperDiscount)}</th>
                  <th className="money">{t(copy.paperLineAmount)}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => (
                  <tr key={line.id}>
                    <td>
                      <input
                        className="field"
                        value={line.title}
                        onChange={(event) => updateLine(line.id, { title: event.target.value })}
                        placeholder={line.kind === "product" ? t(copy.paperProduct) : line.kind === "section" ? t(copy.paperAddSection) : t(copy.paperAddNote)}
                        aria-label={t(copy.paperProduct)}
                      />
                    </td>
                    {kind === "invoice" ? <td>{line.kind === "product" ? t(copy.paperSalesJournal) : "—"}</td> : null}
                    <td>
                      {line.kind === "product" ? (
                        <input className="field" type="number" step="0.01" min="0.01" value={line.units} onChange={(event) => updateLine(line.id, { units: event.target.value })} aria-label={t(copy.paperQty)} />
                      ) : "—"}
                    </td>
                    <td>
                      {line.kind === "product" ? (
                        <input className="field" type="number" step="0.01" min="0.01" value={line.amount} onChange={(event) => updateLine(line.id, { amount: event.target.value })} aria-label={t(copy.paperUnitPrice)} />
                      ) : "—"}
                    </td>
                    <td>{line.kind === "product" ? "—" : ""}</td>
                    <td>
                      {line.kind === "product" ? (
                        <input className="field" type="number" step="0.01" min="0" max="100" value={line.discount} onChange={(event) => updateLine(line.id, { discount: event.target.value })} aria-label={t(copy.paperDiscount)} />
                      ) : ""}
                    </td>
                    <td className="money" dir="ltr">{line.kind === "product" ? money(lineTotal(line)) : ""}</td>
                    <td>
                      {lines.length > 1 ? (
                        <button type="button" className="btn btn-ghost" aria-label={t(copy.removeQuotationLine)} onClick={() => setLines((current) => current.filter((item) => item.id !== line.id))}>×</button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        <div className="odoo-paper-foot">
          <label className="field-label">
            {t(copy.paperTerms)}
            <textarea className="field" rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} />
          </label>
          <dl className="odoo-totals">
            <div><dt>{t(copy.paperUntaxed)}</dt><dd dir="ltr">{money(total)}</dd></div>
            <div><dt>{t(copy.paperTotal)}</dt><dd dir="ltr">{money(total)}</dd></div>
            {kind === "invoice" ? <div><dt>{t(copy.paperAmountDue)}</dt><dd dir="ltr">{money(total)}</dd></div> : null}
          </dl>
        </div>

        {error ? <p className="error">{error}</p> : null}
        <div className="quotation-form-actions">
          {showLines ? (
            <div className="odoo-line-adds">
              <button type="button" className="btn btn-ghost" onClick={() => setLines((current) => [...current, blankLine()])} disabled={busy}>
                {t(copy.paperAddProduct)}
              </button>
              <button type="button" className="btn btn-ghost" onClick={() => setLines((current) => [...current, blankLine("section")])} disabled={busy}>
                {t(copy.paperAddSection)}
              </button>
              <button type="button" className="btn btn-ghost" onClick={() => setLines((current) => [...current, blankLine("note")])} disabled={busy}>
                {t(copy.paperAddNote)}
              </button>
            </div>
          ) : <span />}
          <div className="odoo-line-adds">
            <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>
              {t(copy.cancel)}
            </button>
            <button className="btn btn-ghost" type="submit" disabled={busy} onClick={() => { sendMode.current = "draft"; }}>
              {busy ? t(copy.creatingPaper) : t(copy.paperSaveDraft)}
            </button>
            <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy} onClick={() => { sendMode.current = "send"; }}>
              {busy ? t(copy.creatingPaper) : t(kind === "quotation" ? copy.paperSend : copy.paperConfirm)}
            </button>
          </div>
        </div>
      </form>
    </section>
  );
}
