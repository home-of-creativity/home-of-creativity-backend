import { useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { api, type ServiceRequest } from "../api";
import { copy, sources, statuses, type Locale } from "../i18n";
import { ShamCashQrThumb } from "./PaymentsQr";

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
  const [notice, setNotice] = useState("");
  const [receiptUrl, setReceiptUrl] = useState<string | null>(null);
  const [receiptReason, setReceiptReason] = useState("");
  const [receivedAmount, setReceivedAmount] = useState("");
  const [requiresFullPayment, setRequiresFullPayment] = useState(false);
  const [creatingDrive, setCreatingDrive] = useState(false);

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
    const requestId = id;
    let cancelled = false;

    function load(silent = false) {
      if (!silent) setLoading(true);
      api
        .request(requestId)
        .then((res) => {
          if (cancelled) return;
          setItem(res.data);
          if (!silent) {
            setStatus(res.data.status);
            setReceivedAmount(res.data.expected_due != null ? String(res.data.expected_due) : "");
            setRequiresFullPayment(Boolean(res.data.requires_full_payment));
          }
        })
        .catch(() => {
          if (!cancelled && !silent) setItem(null);
        })
        .finally(() => {
          if (!cancelled && !silent) setLoading(false);
        });
    }

    load();
    const timer = window.setInterval(() => load(true), 15000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
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
      const res = await api.sendQuotation(item.id, { lines, requires_full_payment: requiresFullPayment });
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
    const amount = Number(receivedAmount);
    if (!Number.isFinite(amount) || amount <= 0) {
      setError(t(copy.receivedAmount));
      return;
    }
    setError("");
    setNotice("");
    try {
      const res = await api.confirmPayment(item.id, method, amount);
      setItem(res.data);
      setStatus(res.data.status);
      setNotice(res.message ?? "");
      setReceivedAmount(res.data.expected_due != null ? String(res.data.expected_due) : "");
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function reRequestReceipt() {
    if (!item) return;
    setError("");
    setNotice("");
    try {
      const res = await api.reRequestReceipt(item.id, receiptReason.trim() || undefined);
      setItem(res.data);
      setNotice(res.message ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function ensureDriveFolder() {
    if (!item || creatingDrive) return;
    setError("");
    setNotice("");
    setCreatingDrive(true);
    try {
      const res = await api.ensureDriveFolder(item.id);
      setItem(res.data);
      setNotice(res.message ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setCreatingDrive(false);
    }
  }

  async function renew() {
    if (!item) return;
    setError("");
    setNotice("");
    try {
      const res = await api.renewRequest(item.id);
      setItem(res.data);
      setStatus(res.data.status);
      setNotice(res.message ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;
  if (!item) return <p className="muted">{t(copy.empty)}</p>;

  const canSendQuotation = ["submitted", "quotation_rejected"].includes(item.status);
  const remaining = Number(item.amount_remaining ?? 0);
  const canConfirmPayment = item.status === "awaiting_payment";
  const canConfirmRemaining = remaining > 0.009 && Boolean(item.paid_at);
  const showReceipts = item.status === "awaiting_payment";
  const receipts = item.files?.filter((file) => file.kind === "payment_receipt") ?? [];
  const attachments = item.files?.filter((file) => file.kind === "brief_attachment") ?? [];
  const quotationTotal = quotationLines.reduce((sum, line) => {
    const amount = Number(line.amount);
    const units = Number(line.units || 1);
    if (!Number.isFinite(amount) || !Number.isFinite(units) || amount <= 0 || units <= 0) {
      return sum;
    }
    return sum + amount * units;
  }, 0);
  const firstPaymentAmount = requiresFullPayment ? quotationTotal : Math.round(quotationTotal * 50) / 100;

  return (
    <div className="detail">
      <PageHeader
        breadcrumbs={[{ label: t(copy.requests), to: "/requests" }, { label: item.number }]}
        title={item.number}
        lede={item.title}
        actions={
          <p className={`status status-${item.status}`} data-testid="request-status">
            {t(statuses[item.status] ?? { ar: item.status, en: item.status })}
          </p>
        }
      />
      <p className="badge-row">
        <span className={`pay-badge ${item.requires_full_payment ? "pay-badge-full" : "pay-badge-partial"}`}>
          {item.requires_full_payment ? t(copy.fullPayment) : t(copy.partialPayment)}
        </span>
        <span className={`renew-badge ${item.allows_renewal ? "renew-badge-on" : "renew-badge-off"}`}>
          {item.allows_renewal ? t(copy.renewalOn) : t(copy.renewalOff)}
        </span>
      </p>
      <div className="detail-layout">
      <div className="detail-main">
      <section className="card detail-card">
        <p>{item.description}</p>
        <dl className="meta-grid">
          <div>
            <dt>{t(copy.client)}</dt>
            <dd>
              {item.client?.name ?? "—"}
              {item.client?.company_name ? ` · ${item.client.company_name}` : ""}
            </dd>
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
            <dd dir="ltr">
              {item.client?.telegram_url ? (
                <a href={item.client.telegram_url} rel="noreferrer">
                  {t(copy.contactTelegram)}
                </a>
              ) : (
                item.client?.telegram_user_id ?? "—"
              )}
            </dd>
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
              {item.odoo_quotation_live?.state ? ` · ${item.odoo_quotation_live.state}` : ""}
              {item.odoo_quotation_live?.amount_total != null ? ` · ${item.odoo_quotation_live.amount_total}` : ""}
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
              {item.odoo_invoice_live?.state ? ` · ${item.odoo_invoice_live.state}` : ""}
              {item.odoo_invoice_live?.payment_state ? ` / ${item.odoo_invoice_live.payment_state}` : ""}
              {item.odoo_invoice_live?.amount_total != null ? ` · ${item.odoo_invoice_live.amount_total}` : ""}
            </dd>
          </div>
          <div>
            <dt>{t(copy.paymentPlan)}</dt>
            <dd>{item.requires_full_payment ? t(copy.fullPayment) : t(copy.partialPayment)}</dd>
          </div>
          <div>
            <dt>{t(copy.expectedDue)}</dt>
            <dd>{item.expected_due != null ? `${item.expected_due} USD` : "—"}</dd>
          </div>
          <div>
            <dt>{t(copy.paidAmount)}</dt>
            <dd>
              {item.amount_paid != null ? `${item.amount_paid} USD` : "—"}
              {item.paid_percent != null ? ` (${item.paid_percent}%)` : ""}
            </dd>
          </div>
          <div>
            <dt>{t(copy.remainingBalance)}</dt>
            <dd>
              {item.amount_remaining != null ? `${item.amount_remaining} USD` : "—"}
              {item.remaining_percent != null ? ` (${item.remaining_percent}%)` : ""}
            </dd>
          </div>
          <div>
            <dt>{t(copy.driveFolder)}</dt>
            <dd>
              {item.google_drive_folder_url ? (
                <a href={item.google_drive_folder_url} target="_blank" rel="noreferrer">
                  {t(copy.openDriveFolder)}
                </a>
              ) : (
                "—"
              )}
              {!item.google_drive_folder_ready &&
              (item.paid_at || item.status === "payment_confirmed" || Number(item.amount_paid ?? 0) > 0) ? (
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={creatingDrive}
                  onClick={() => void ensureDriveFolder()}
                >
                  {t(copy.createDriveFolder)}
                </button>
              ) : null}
            </dd>
          </div>
        </dl>
        {(item.quotations?.length ||
          item.quotation_decisions?.length ||
          item.clickup_tasks?.length ||
          attachments.length ||
          (showReceipts && receipts.length)) ? (
          <div className="detail-briefs">
            {item.quotations?.length ? (
              <div className="briefs">
                <h3>{t(copy.sendQuotation)}</h3>
                <ul>
                  {item.quotations.map((quote) => (
                    <li key={quote.id}>
                      v{quote.version} · {quote.amount} USD
                      {quote.sent_at ? ` · ${quote.sent_at}` : ""}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            {item.quotation_decisions?.length ? (
              <div className="briefs">
                <h3>{t(copy.quotationDecision)}</h3>
                <ul>
                  {item.quotation_decisions.map((decision) => (
                    <li key={decision.id}>
                      {decision.decision === "approved" ? t(copy.approved) : t(copy.rejected)}
                      {item.client?.name ? ` · ${item.client.name}` : ""}
                      {decision.reason ? ` · ${decision.reason}` : ""}
                      {item.client?.telegram_url ? (
                        <>
                          {" · "}
                          <a href={item.client.telegram_url} rel="noreferrer">
                            {t(copy.contactTelegram)}
                          </a>
                        </>
                      ) : null}
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
            {showReceipts && receipts.length ? (
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
          </div>
        ) : null}
      </section>
      {canSendQuotation ? (
        <section className="card action-card">
          <h2 className="form-title">{t(copy.sendQuotation)}</h2>
          <form className="quotation-form" onSubmit={sendQuotation}>
            <label className="field-label" htmlFor="quotation-payment-plan">
              {t(copy.paymentPlan)}
              <select
                id="quotation-payment-plan"
                className="field"
                value={requiresFullPayment ? "full" : "partial"}
                onChange={(e) => setRequiresFullPayment(e.target.value === "full")}
              >
                <option value="partial">{t(copy.partialPayment)}</option>
                <option value="full">{t(copy.fullPayment)}</option>
              </select>
            </label>
            <p className="muted" role="status">
              {t(copy.quotationTotal)}: {quotationTotal ? `${quotationTotal} USD` : "—"}
              {" · "}
              {t(copy.firstPaymentAmount)}: {quotationTotal ? `${firstPaymentAmount} USD` : "—"}
            </p>
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
                    aria-label={t(copy.lineUnits)}
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
      </div>
      <aside className="detail-aside">
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
          {canConfirmPayment || canConfirmRemaining ? (
            <label className="field-label" htmlFor="received-amount">
              {t(copy.receivedAmount)}
              <input
                id="received-amount"
                className="field"
                type="number"
                min="0.01"
                step="0.01"
                inputMode="decimal"
                value={receivedAmount}
                onChange={(e) => setReceivedAmount(e.target.value)}
                placeholder={item.expected_due != null ? String(item.expected_due) : t(copy.expectedDue)}
                aria-label={t(copy.receivedAmount)}
              />
            </label>
          ) : null}
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
          {canConfirmRemaining ? (
            <button className="btn btn-teal" type="button" onClick={() => void confirmPayment("cash")}>
              {t(copy.confirmRemaining)}
            </button>
          ) : null}
          {item.can_renew ? (
            <button className="btn" type="button" onClick={() => void renew()}>
              {t(copy.renewSubscription)}
            </button>
          ) : null}
          {item.gemini_status === "failed" ? (
            <button className="btn" type="button" onClick={() => void api.retryGemini(item.id).then((res) => setItem(res.data))}>
              {t(copy.retryGemini)}
            </button>
          ) : null}
        </form>
      </section>
      {showReceipts ? (
        <section className="card action-card">
          <h2 className="form-title">{t(copy.receipts)}</h2>
          <div className="toolbar">
            <input
              className="field"
              value={receiptReason}
              onChange={(e) => setReceiptReason(e.target.value)}
              placeholder={t(copy.receiptReason)}
            />
            <button className="btn btn-ghost" type="button" onClick={() => void reRequestReceipt()}>
              {t(copy.reRequestReceipt)}
            </button>
          </div>
        </section>
      ) : null}
      <section className="card action-card qr-request-card">
        <h2 className="form-title">{t(copy.shamCashQr)}</h2>
        <p className="muted">{t(copy.shamCashQrHelp)}</p>
        <ShamCashQrThumb alt={t(copy.shamCashQr)} />
        <Link className="btn btn-primary" to="/payments">
          {t(copy.qrManage)}
        </Link>
      </section>
      </aside>
      </div>
      {notice ? <p className="notice notice-info">{notice}</p> : null}
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
