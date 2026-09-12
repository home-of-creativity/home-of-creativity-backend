import { useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { LoadingLottie } from "../components/LoadingLottie";
import { api, type ServiceRequest } from "../api";
import { copy, sources, statuses, type Locale } from "../i18n";

type QuotationLine = {
  id: string;
  title: string;
  amount: string;
  units: string;
  notes: string;
};

function createQuotationLine(): QuotationLine {
  return {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    title: "",
    amount: "",
    units: "1",
    notes: "",
  };
}

export function RequestDetail({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { id } = useParams();
  const [item, setItem] = useState<ServiceRequest | null>(null);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [quotationLines, setQuotationLines] = useState<QuotationLine[]>([createQuotationLine()]);
  const [sendingQuotation, setSendingQuotation] = useState(false);
  const [error, setError] = useState("");
  const [receiptUrl, setReceiptUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!receiptUrl) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setReceiptUrl(null);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [receiptUrl]);

  useEffect(() => {
    if (!id) return;
    setLoading(true);
    api
      .request(id)
      .then((res) => {
        setItem(res.data);
        setStatus(res.data.status);
      })
      .catch(() => setItem(null))
      .finally(() => setLoading(false));
  }, [id]);

  async function saveStatus(next: string) {
    if (!item) return;
    setError("");
    try {
      const res = await api.updateStatus(item.id, next);
      setItem(res.data);
      setStatus(res.data.status);
    } catch (err) {
      const message = err instanceof Error ? err.message : "";
      setError(message.includes("quotation") || message.includes("payment") ? t(copy.paymentBlocked) : t(copy.saveFailed));
    }
  }

  async function onSave(event: FormEvent) {
    event.preventDefault();
    await saveStatus(status);
  }

  function updateQuotationLine(lineId: string, patch: Partial<QuotationLine>) {
    setQuotationLines((current) => current.map((line) => (line.id === lineId ? { ...line, ...patch } : line)));
  }

  function addQuotationLine() {
    setQuotationLines((current) => [...current, createQuotationLine()]);
  }

  function removeQuotationLine(lineId: string) {
    setQuotationLines((current) => (current.length === 1 ? current : current.filter((line) => line.id !== lineId)));
  }

  async function sendQuotation(event: FormEvent) {
    event.preventDefault();
    if (!item || sendingQuotation) return;

    const lines = quotationLines
      .map((line) => ({
        title: line.title.trim(),
        amount: Number(line.amount),
        units: Number(line.units || 1),
        notes: line.notes.trim() || undefined,
      }))
      .filter(
        (line) =>
          line.title &&
          Number.isFinite(line.amount) &&
          line.amount > 0 &&
          Number.isFinite(line.units) &&
          line.units > 0,
      );

    if (!lines.length) {
      setError(t(copy.saveFailed));
      return;
    }

    setError("");
    setSendingQuotation(true);
    try {
      const res = await api.sendQuotation(item.id, { lines });
      setItem(res.data);
      setStatus(res.data.status);
      setQuotationLines([createQuotationLine()]);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setSendingQuotation(false);
    }
  }

  async function confirmPayment(method: "receipt" | "cash") {
    if (!item) return;
    setError("");
    try {
      const res = await api.confirmPayment(item.id, method);
      setItem(res.data);
      setStatus(res.data.status);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;
  if (!item) return <p className="muted">{t(copy.empty)}</p>;

  const canSendQuotation = ["submitted", "quotation_rejected"].includes(item.status);
  const canConfirmPayment = item.status === "awaiting_payment";
  const receipts = item.files?.filter((file) => file.kind === "payment_receipt") ?? [];
  const attachments = item.files?.filter((file) => file.kind === "brief_attachment") ?? [];

  return (
    <div className="detail">
      <Link className="back-link" to="/requests">
        <span aria-hidden="true">←</span>
        {t(copy.back)}
      </Link>
      <header className="page-head">
        <div>
          <p className="eyebrow">{item.client?.name ?? t(copy.client)}</p>
          <h1 className="page-title">{item.number}</h1>
          <p className="page-lede">{item.title}</p>
        </div>
        <p className={`status status-${item.status}`} data-testid="request-status">
          {t(statuses[item.status] ?? { ar: item.status, en: item.status })}
        </p>
      </header>
      <section className="card detail-card">
        <p>{item.description}</p>
        <dl className="meta-grid">
          <div>
            <dt>{t(copy.client)}</dt>
            <dd>{item.client?.name ?? "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.source)}</dt>
            <dd>{t(sources[item.source] ?? { ar: item.source, en: item.source })}</dd>
          </div>
          <div>
            <dt>{t(copy.workType)}</dt>
            <dd>{item.work_type ?? "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.executionStatus)}</dt>
            <dd>{item.execution_status_label ?? item.execution_status ?? "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.geminiStatus)}</dt>
            <dd>{item.gemini_status ?? "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.telegram)}</dt>
            <dd dir="ltr">{item.client?.telegram_user_id ?? "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.odooPartner)}</dt>
            <dd dir="ltr">
              {item.client?.odoo_url ? (
                <a href={item.client.odoo_url} target="_blank" rel="noreferrer">
                  {item.client.odoo_partner_id}
                </a>
              ) : (
                item.client?.odoo_partner_id ?? "—"
              )}
            </dd>
          </div>
          <div>
            <dt>{t(copy.odooQuote)}</dt>
            <dd dir="ltr">
              {item.odoo_quotation_url ? (
                <a href={item.odoo_quotation_url} target="_blank" rel="noreferrer">
                  {item.odoo_quotation_id}
                </a>
              ) : (
                item.odoo_quotation_id ?? "—"
              )}
            </dd>
          </div>
          <div>
            <dt>{t(copy.odooInvoice)}</dt>
            <dd dir="ltr">
              {item.odoo_invoice_url ? (
                <a href={item.odoo_invoice_url} target="_blank" rel="noreferrer">
                  {item.odoo_invoice_id}
                </a>
              ) : (
                item.odoo_invoice_id ?? "—"
              )}
            </dd>
          </div>
        </dl>
        {item.quotations?.length ? (
          <div className="briefs">
            <h3>{t(copy.sendQuotation)}</h3>
            <ul>
              {item.quotations.map((quote) => (
                <li key={quote.id}>
                  v{quote.version} · {quote.amount}
                  {quote.sent_at ? ` · ${quote.sent_at}` : ""}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {item.clickup_tasks?.length ? (
          <div className="briefs">
            <h3>{t(copy.clickupTasks)}</h3>
            <ul>
              {item.clickup_tasks.map((task) => (
                <li key={task.integration_key}>
                  <strong>{task.task_type}</strong>
                  {task.clickup_task_id ? <span dir="ltr"> · {task.clickup_task_id}</span> : null}
                  {task.clickup_url ? (
                    <a href={task.clickup_url} target="_blank" rel="noreferrer">
                      {" "}
                      · Open
                    </a>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {attachments.length ? (
          <div className="briefs">
            <h3>{t(copy.attachments)}</h3>
            <ul className="file-list">
              {attachments.map((file) => (
                <li key={file.id}>
                  <span>{file.original_name}</span>
                  <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => void api.receiptBlob(item.id, file.id).then((blob) => setReceiptUrl(URL.createObjectURL(blob)))}
                  >
                    {t(copy.viewAttachment)}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {receipts.length ? (
          <div className="briefs">
            <h3>{t(copy.receipts)}</h3>
            <ul className="file-list">
              {receipts.map((file) => (
                <li key={file.id}>
                  <span>{file.original_name}</span>
                  <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => void api.receiptBlob(item.id, file.id).then((blob) => setReceiptUrl(URL.createObjectURL(blob)))}
                  >
                    {t(copy.viewReceipt)}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </section>
      {canSendQuotation ? (
        <section className="card action-card">
          <h2 className="form-title">{t(copy.sendQuotation)}</h2>
          <form className="quotation-form" onSubmit={sendQuotation}>
            <div className="quotation-lines">
              {quotationLines.map((line, index) => (
                <div className="quotation-line" key={line.id}>
                  <span className="quotation-line-index">{index + 1}</span>
                  <input
                    className="field"
                    value={line.title}
                    onChange={(e) => updateQuotationLine(line.id, { title: e.target.value })}
                    placeholder={t(copy.lineTitle)}
                    required
                  />
                  <input
                    className="field"
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={line.amount}
                    onChange={(e) => updateQuotationLine(line.id, { amount: e.target.value })}
                    placeholder={t(copy.amount)}
                    required
                  />
                  <input
                    className="field quotation-line-units"
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={line.units}
                    onChange={(e) => updateQuotationLine(line.id, { units: e.target.value })}
                    placeholder={t(copy.lineUnits)}
                    required
                  />
                  <input
                    className="field"
                    value={line.notes}
                    onChange={(e) => updateQuotationLine(line.id, { notes: e.target.value })}
                    placeholder={t(copy.quotationNotes)}
                  />
                  {quotationLines.length > 1 ? (
                    <button
                      type="button"
                      className="btn btn-ghost quotation-line-remove"
                      aria-label={t(copy.removeQuotationLine)}
                      onClick={() => removeQuotationLine(line.id)}
                    >
                      ×
                    </button>
                  ) : (
                    <span className="quotation-line-spacer" aria-hidden="true" />
                  )}
                </div>
              ))}
            </div>
            <div className="quotation-form-actions">
              <button type="button" className="btn btn-ghost" onClick={addQuotationLine} disabled={sendingQuotation}>
                + {t(copy.addQuotationLine)}
              </button>
              <button className="btn btn-primary" type="submit" disabled={sendingQuotation} aria-busy={sendingQuotation}>
                {sendingQuotation ? t(copy.sendingQuotation) : t(copy.sendQuotation)}
              </button>
            </div>
          </form>
        </section>
      ) : null}
      <section className="card action-card">
        <h2 className="form-title">{t(copy.status)}</h2>
        <form className="toolbar" onSubmit={onSave}>
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={t(copy.status)}>
            {Object.entries(statuses).map(([key, label]) => (
              <option key={key} value={key}>
                {t(label)}
              </option>
            ))}
          </select>
          <button className="btn btn-primary" type="submit">
            {t(copy.save)}
          </button>
          {canConfirmPayment ? (
            <>
              <button className="btn btn-teal" type="button" onClick={() => void confirmPayment("receipt")}>
                {t(copy.markPaid)}
              </button>
              <button className="btn btn-teal" type="button" onClick={() => void confirmPayment("cash")}>
                {t(copy.markCash)}
              </button>
            </>
          ) : null}
          {item.gemini_status === "failed" ? (
            <button className="btn" type="button" onClick={() => void api.retryGemini(item.id).then((res) => setItem(res.data))}>
              {t(copy.retryGemini)}
            </button>
          ) : null}
        </form>
      </section>
      {error ? <p className="error">{error}</p> : null}
      {receiptUrl ? (
        <div className="modal" role="dialog" aria-modal="true" onClick={() => setReceiptUrl(null)}>
          <button
            type="button"
            className="modal-close"
            aria-label={t(copy.cancel)}
            onClick={(event) => {
              event.stopPropagation();
              setReceiptUrl(null);
            }}
          >
            ×
          </button>
          <img src={receiptUrl} alt="" onClick={(event) => event.stopPropagation()} />
        </div>
      ) : null}
    </div>
  );
}
