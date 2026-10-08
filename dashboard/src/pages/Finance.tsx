import { useEffect, useState, type FormEvent } from "react";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { StatCard } from "../components/StatCard";
import { api, type FinanceSummary } from "../api";
import { copy, type Locale } from "../i18n";

function usd(value: number) {
  return `${value.toLocaleString("en-US", { maximumFractionDigits: 2 })} USD`;
}

export function Finance({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [data, setData] = useState<FinanceSummary | null>(null);
  const [error, setError] = useState("");
  const [amount, setAmount] = useState("");
  const [category, setCategory] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [invoiceState, setInvoiceState] = useState("");
  const [client, setClient] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [expenseCategory, setExpenseCategory] = useState("");
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedQuery(query.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    let cancelled = false;
    function load() {
      api
        .finance({
          q: debouncedQuery || undefined,
          invoice_state: invoiceState || undefined,
          client: client || undefined,
          from: from || undefined,
          to: to || undefined,
          category: expenseCategory || undefined,
        })
        .then((res) => {
          if (cancelled) return;
          setData(res.data);
          setCategory((current) => current || res.data.categories[0] || "");
          setError("");
        })
        .catch((err) => {
          if (!cancelled) setError(err instanceof Error ? err.message : t(copy.saveFailed));
        });
    }
    load();
    const timer = window.setInterval(load, 15000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [debouncedQuery, invoiceState, client, from, to, expenseCategory, refresh, t]);

  async function addExpense(event: FormEvent) {
    event.preventDefault();
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0 || !category) {
      setError(t(copy.financeAmountInvalid));
      return;
    }
    setError("");
    setBusy(true);
    try {
      const res = await api.addExpense({ amount: value, category, note: note.trim() || undefined });
      setAmount("");
      setNote("");
      setRefresh((value) => value + 1);
      toast.success(res.message ?? t(copy.saveSuccess));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  const stateLabel: Record<string, { ar: string; en: string }> = {
    paid: copy.financeRevenuePaid,
    open: copy.payNotPaid,
    partial: copy.payPartial,
    cancelled: copy.quoteStateCancel,
  };
  const filtered = Boolean(query || invoiceState || client || from || to || expenseCategory);

  if (!data && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <>
      <PageHeader title={t(copy.financeTitle)} lede={t(copy.financeLede)} />
      {error ? <p className="error">{error}</p> : null}
      {data ? (
        <>
          <div className="cards">
            <StatCard tone="teal" label={t(copy.financeRevenuePaid)} value={usd(data.revenue_paid)} />
            <StatCard tone="orange" label={t(copy.financeRevenueOpen)} value={usd(data.revenue_open)} />
            <StatCard tone="danger" label={t(copy.financeExpenses)} value={usd(data.expenses)} />
            <StatCard label={t(copy.financeNet)} value={usd(data.net)} />
          </div>
          <form className="card form-grid" onSubmit={addExpense}>
            <h2 className="form-title field-span">{t(copy.financeAddExpense)}</h2>
            <label className="field-label">
              {t(copy.amount)}
              <input
                className="field"
                type="number"
                min="0.01"
                step="0.01"
                inputMode="decimal"
                dir="ltr"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                required
              />
            </label>
            <label className="field-label">
              {t(copy.financeCategory)}
              <select className="field" value={category} onChange={(event) => setCategory(event.target.value)}>
                {data.categories.map((item) => (
                  <option key={item} value={item}>
                    {item}
                  </option>
                ))}
              </select>
            </label>
            <label className="field-label field-span">
              {t(copy.financeNote)}
              <input className="field" maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} />
            </label>
            <p className="muted field-span" role="status">
              {Number(amount) > 0 ? `${usd(Number(amount))} · ${category}` : t(copy.financeConfirmHint)}
            </p>
            <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy}>
              {t(copy.financeSave)}
            </button>
          </form>
          <div className="toolbar filter-bar filter-grid">
            <label className="field-label">
              {t(copy.search)}
              <span className="search-bar">
                <Search size={16} aria-hidden="true" />
                <input type="search" className="field" placeholder={t(copy.search)} value={query} onChange={(e) => setQuery(e.target.value)} aria-label={t(copy.search)} />
              </span>
            </label>
            <label className="field-label">
              {t(copy.odooState)}
              <select className="field" value={invoiceState} onChange={(e) => setInvoiceState(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="paid">{t(copy.financeRevenuePaid)}</option>
                <option value="partial">{t(copy.payPartial)}</option>
                <option value="open">{t(copy.payNotPaid)}</option>
                <option value="cancelled">{t(copy.quoteStateCancel)}</option>
              </select>
            </label>
            <label className="field-label">
              {t(copy.financeClient)}
              <select className="field" value={client} onChange={(e) => setClient(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                {(data.clients ?? []).map((name) => (
                  <option key={name} value={name}>{name}</option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {t(copy.financeFrom)}
              <input className="field" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </label>
            <label className="field-label">
              {t(copy.financeTo)}
              <input className="field" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </label>
            <label className="field-label">
              {t(copy.financeCategory)}
              <select className="field" value={expenseCategory} onChange={(e) => setExpenseCategory(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                {data.categories.map((item) => (
                  <option key={item} value={item}>{item}</option>
                ))}
              </select>
            </label>
          </div>
          <section className="panel recent-panel">
            <div className="panel-head">
              <h2>{t(copy.financeInvoices)}</h2>
            </div>
            <div className="table-wrap table-flush">
              <table>
                <thead>
                  <tr>
                    <th>{t(copy.number)}</th>
                    <th>{t(copy.financeClient)}</th>
                    <th>{t(copy.amount)}</th>
                    <th>{t(copy.financeRevenuePaid)}</th>
                    <th>{t(copy.odooState)}</th>
                    <th>{t(copy.odooDate)}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.invoices.length === 0 ? (
                    <tr>
                      <td colSpan={6}>{filtered ? t(copy.noSearchResults) : t(copy.empty)}</td>
                    </tr>
                  ) : (
                    data.invoices.map((row) => (
                      <tr key={`${row.id}-${row.name ?? ""}`}>
                        <td dir="ltr">
                          {row.odoo_url ? (
                            <a className="table-link" href={row.odoo_url} target="_blank" rel="noreferrer">{row.name || row.request_number || row.id}</a>
                          ) : (
                            row.name || row.request_number || row.id
                          )}
                        </td>
                        <td>{row.partner_name ?? "—"}</td>
                        <td dir="ltr">{usd(row.amount)}</td>
                        <td dir="ltr">{usd(row.collected)}</td>
                        <td>{t(stateLabel[row.state] ?? { ar: row.state, en: row.state })}</td>
                        <td className="nowrap">{row.issued_at ?? "—"}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>
          <section className="panel recent-panel">
            <div className="panel-head">
              <h2>{t(copy.financeExpenses)}</h2>
            </div>
            <div className="table-wrap table-flush">
              <table>
                <thead>
                  <tr>
                    <th>{t(copy.amount)}</th>
                    <th>{t(copy.financeCategory)}</th>
                    <th>{t(copy.financeNote)}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.expense_rows.length === 0 ? (
                    <tr>
                      <td colSpan={3}>{filtered ? t(copy.noSearchResults) : t(copy.empty)}</td>
                    </tr>
                  ) : (
                    data.expense_rows.map((row) => (
                      <tr key={row.id}>
                        <td dir="ltr">{usd(row.amount)}</td>
                        <td>{row.category}</td>
                        <td>{row.note ?? "—"}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </>
      ) : null}
    </>
  );
}
