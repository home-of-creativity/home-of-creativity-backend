import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ArrowDown, ArrowUp, ExternalLink, RefreshCw, Search, X } from "lucide-react";
import { toast } from "sonner";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { StatCard } from "../components/StatCard";
import { api, type FinanceFilters, type FinanceInvoiceState, type FinanceSummary } from "../api";
import { copy, type Copy, type Locale } from "../i18n";

const STATES: FinanceInvoiceState[] = ["paid", "partial", "open", "overdue", "draft", "cancelled"];

const STATE_COPY: Record<FinanceInvoiceState, Copy> = {
  paid: copy.financeStatePaid,
  partial: copy.financeStatePartial,
  open: copy.financeStateOpen,
  overdue: copy.financeStateOverdue,
  draft: copy.financeStateDraft,
  cancelled: copy.financeStateCancelled,
};

const STATE_CLASS: Record<FinanceInvoiceState, string> = {
  paid: "status status-paid",
  partial: "status status-partial",
  open: "status status-sent",
  overdue: "status status-cancel",
  draft: "status status-draft",
  cancelled: "status status-cancelled",
};

const FILTER_KEYS = ["q", "invoice_state", "client", "currency", "from", "to", "amount_min", "amount_max", "category"] as const;
type Period = "" | "month" | "last_month" | "quarter" | "year" | "custom";
type SortKey = "date" | "amount" | "residual" | "due" | "client";

function money(value: number, currency?: string | null) {
  return `${value.toLocaleString("en-US", { maximumFractionDigits: 2 })} ${currency || "USD"}`;
}

function isoDay(date: Date) {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
}

function periodRange(period: Period): { from: string; to: string } | null {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth();
  switch (period) {
    case "month":
      return { from: isoDay(new Date(year, month, 1)), to: isoDay(new Date(year, month + 1, 0)) };
    case "last_month":
      return { from: isoDay(new Date(year, month - 1, 1)), to: isoDay(new Date(year, month, 0)) };
    case "quarter":
      return { from: isoDay(new Date(year, month - 2, 1)), to: isoDay(new Date(year, month + 1, 0)) };
    case "year":
      return { from: `${year}-01-01`, to: `${year}-12-31` };
    default:
      return null;
  }
}

function periodFor(from: string, to: string): Period {
  if (!from && !to) return "";
  for (const period of ["month", "last_month", "quarter", "year"] as const) {
    const range = periodRange(period);
    if (range && range.from === from && range.to === to) return period;
  }
  return "custom";
}

function fill(template: string, vars: Record<string, string | number>) {
  return Object.entries(vars).reduce((text, [key, value]) => text.replaceAll(`{${key}}`, String(value)), template);
}

function syncedLabel(iso: string, locale: Locale) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleString(locale === "ar" ? "ar-SY-u-nu-latn" : "en-GB", {
    timeZone: "Asia/Damascus",
    dateStyle: "medium",
    timeStyle: "short",
  });
}

export function Finance({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [params, setParams] = useSearchParams();
  const [data, setData] = useState<FinanceSummary | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [amount, setAmount] = useState("");
  const [category, setCategory] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [refresh, setRefresh] = useState(0);
  const [query, setQuery] = useState(params.get("q") ?? "");

  const filters = useMemo(() => {
    const values: Record<string, string> = {};
    for (const key of FILTER_KEYS) values[key] = params.get(key) ?? "";
    return values as Record<(typeof FILTER_KEYS)[number], string>;
  }, [params]);
  const sort = (params.get("sort") as SortKey | null) ?? "date";
  const dir = params.get("dir") === "asc" ? "asc" : "desc";
  const page = Math.max(1, Number(params.get("page") ?? "1") || 1);
  const [period, setPeriod] = useState<Period>(() => periodFor(filters.from, filters.to));

  function update(next: Partial<Record<string, string>>, keepPage = false) {
    setParams(
      (current) => {
        const copyParams = new URLSearchParams(current);
        for (const [key, value] of Object.entries(next)) {
          if (value) copyParams.set(key, value);
          else copyParams.delete(key);
        }
        if (!keepPage) copyParams.delete("page");
        return copyParams;
      },
      { replace: true },
    );
  }

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (query.trim() !== filters.q) update({ q: query.trim() });
    }, 300);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  useEffect(() => {
    let cancelled = false;
    const request: FinanceFilters = { ...filters, sort, dir, page, per_page: 25 };
    function load(quiet: boolean) {
      if (!quiet) setLoading(true);
      api
        .finance(request)
        .then((res) => {
          if (cancelled) return;
          setData(res.data);
          setCategory((current) => current || res.data.categories[0] || "");
          setError("");
        })
        .catch((err) => {
          if (!cancelled) setError(err instanceof Error ? err.message : t(copy.saveFailed));
        })
        .finally(() => {
          if (!cancelled) setLoading(false);
        });
    }
    load(false);
    const timer = window.setInterval(() => load(true), 15000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [filters, sort, dir, page, refresh, t]);

  async function syncNow() {
    setSyncing(true);
    try {
      const res = await api.syncFinance();
      if (res.data.synced) toast.success(fill(t(copy.financeSyncDone), { n: res.data.changed }));
      else toast.error(res.message ?? t(copy.saveFailed));
      setRefresh((value) => value + 1);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      setRefresh((value) => value + 1);
    } finally {
      setSyncing(false);
    }
  }

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
      setRefresh((current) => current + 1);
      toast.success(res.message ?? t(copy.saveSuccess));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  function choosePeriod(next: Period) {
    setPeriod(next);
    if (next === "custom") return;
    const range = periodRange(next);
    update({ from: range?.from ?? "", to: range?.to ?? "" });
  }

  function sortBy(key: SortKey) {
    if (sort === key) update({ sort: key, dir: dir === "asc" ? "desc" : "asc" });
    else update({ sort: key, dir: key === "client" ? "asc" : "desc" });
  }

  function clearFilters() {
    setQuery("");
    setPeriod("");
    setParams(new URLSearchParams(), { replace: true });
  }

  const activeFilters = FILTER_KEYS.filter((key) => filters[key] !== "").length;

  if (!data && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const sync = data?.sync;
  const counts = data?.state_counts ?? {};
  const allCount = STATES.reduce((sum, state) => sum + (counts[state] ?? 0), 0);

  function sortHeader(label: string, column: SortKey) {
    const active = sort === column;
    return (
      <th key={column} aria-sort={active ? (dir === "asc" ? "ascending" : "descending") : "none"}>
        <button type="button" className="th-sort" onClick={() => sortBy(column)} title={t(copy.financeSortHint)}>
          {label}
          {active ? dir === "asc" ? <ArrowUp size={13} aria-hidden="true" /> : <ArrowDown size={13} aria-hidden="true" /> : null}
        </button>
      </th>
    );
  }

  return (
    <>
      <PageHeader
        title={t(copy.financeTitle)}
        lede={t(copy.financeLede)}
        actions={
          sync?.configured ? (
            <button type="button" className="btn" onClick={syncNow} disabled={syncing} aria-busy={syncing}>
              <RefreshCw size={16} aria-hidden="true" className={syncing ? "spin" : undefined} />
              {syncing ? t(copy.financeSyncing) : t(copy.financeSyncNow)}
            </button>
          ) : null
        }
      />
      {sync ? (
        <p className="finance-sync" role="status">
          <span className={`status-dot ${sync.error ? "is-dirty" : sync.synced_at ? "is-live" : ""}`}>
            {!sync.configured
              ? t(copy.financeSyncOff)
              : sync.synced_at
                ? fill(t(copy.financeSyncedAt), { time: syncedLabel(sync.synced_at, locale) })
                : t(copy.financeSyncNever)}
          </span>
          {sync.configured && sync.error ? <span className="error-inline">{fill(t(copy.financeSyncError), { error: sync.error })}</span> : null}
        </p>
      ) : null}
      {error ? <p className="error">{error}</p> : null}
      {data ? (
        <>
          <div className="cards">
            <StatCard tone="teal" label={t(copy.financeRevenuePaid)} value={money(data.revenue_paid)} />
            <StatCard tone="orange" label={t(copy.financeRevenueOpen)} value={money(data.revenue_open)} />
            <StatCard tone="danger" label={t(copy.financeRevenueOverdue)} value={money(data.revenue_overdue ?? 0)} />
            <StatCard tone="danger" label={t(copy.financeExpenses)} value={money(data.expenses)} />
            <StatCard label={t(copy.financeNet)} value={money(data.net)} />
          </div>

          <section className="toolbar filter-bar filter-grid finance-filters" aria-label={t(copy.search)}>
            <label className="field-label finance-search">
              {t(copy.search)}
              <span className="search-bar">
                <Search size={16} aria-hidden="true" />
                <input
                  type="search"
                  className="field"
                  placeholder={t(copy.search)}
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  aria-label={t(copy.search)}
                />
              </span>
            </label>
            <label className="field-label">
              {t(copy.financeClient)}
              <select className="field" value={filters.client} onChange={(event) => update({ client: event.target.value })}>
                <option value="">{t(copy.all)}</option>
                {(data.clients ?? []).map((name) => (
                  <option key={name} value={name}>
                    {name}
                  </option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {t(copy.financePeriod)}
              <select className="field" value={period} onChange={(event) => choosePeriod(event.target.value as Period)}>
                <option value="">{t(copy.financePeriodAll)}</option>
                <option value="month">{t(copy.financePeriodThisMonth)}</option>
                <option value="last_month">{t(copy.financePeriodLastMonth)}</option>
                <option value="quarter">{t(copy.financePeriodQuarter)}</option>
                <option value="year">{t(copy.financePeriodYear)}</option>
                <option value="custom">{t(copy.financePeriodCustom)}</option>
              </select>
            </label>
            {period === "custom" ? (
              <>
                <label className="field-label">
                  {t(copy.financeFrom)}
                  <input className="field" type="date" value={filters.from} onChange={(event) => update({ from: event.target.value })} />
                </label>
                <label className="field-label">
                  {t(copy.financeTo)}
                  <input className="field" type="date" value={filters.to} onChange={(event) => update({ to: event.target.value })} />
                </label>
              </>
            ) : null}
            <label className="field-label">
              {t(copy.financeAmountMin)}
              <input
                className="field"
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                dir="ltr"
                value={filters.amount_min}
                onChange={(event) => update({ amount_min: event.target.value })}
              />
            </label>
            <label className="field-label">
              {t(copy.financeAmountMax)}
              <input
                className="field"
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                dir="ltr"
                value={filters.amount_max}
                onChange={(event) => update({ amount_max: event.target.value })}
              />
            </label>
            {(data.currencies ?? []).length > 1 ? (
              <label className="field-label">
                {t(copy.financeCurrency)}
                <select className="field" value={filters.currency} onChange={(event) => update({ currency: event.target.value })}>
                  <option value="">{t(copy.all)}</option>
                  {(data.currencies ?? []).map((item) => (
                    <option key={item} value={item}>
                      {item}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="field-label finance-narrow">
              {t(copy.financeExpenseCategory)}
              <select className="field" value={filters.category} onChange={(event) => update({ category: event.target.value })}>
                <option value="">{t(copy.all)}</option>
                {data.categories.map((item) => (
                  <option key={item} value={item}>
                    {item}
                  </option>
                ))}
              </select>
            </label>
            <div className="chip-row finance-states field-span" role="group" aria-label={t(copy.odooState)}>
              <button
                type="button"
                className={`chip${filters.invoice_state === "" ? " is-active" : ""}`}
                aria-pressed={filters.invoice_state === ""}
                onClick={() => update({ invoice_state: "" })}
              >
                {t(copy.all)} <span className="count-badge">{allCount}</span>
              </button>
              {STATES.map((state) => (
                <button
                  key={state}
                  type="button"
                  className={`chip${filters.invoice_state === state ? " is-active" : ""}`}
                  aria-pressed={filters.invoice_state === state}
                  onClick={() => update({ invoice_state: filters.invoice_state === state ? "" : state })}
                >
                  {t(STATE_COPY[state])} <span className="count-badge">{counts[state] ?? 0}</span>
                </button>
              ))}
              {activeFilters > 0 ? (
                <button type="button" className="chip chip-clear" onClick={clearFilters}>
                  <X size={14} aria-hidden="true" />
                  {fill(t(copy.financeClearFilters), { n: activeFilters })}
                </button>
              ) : null}
            </div>
          </section>

          <section className="panel recent-panel">
            <div className="panel-head">
              <h2>{t(copy.financeInvoices)}</h2>
              {data.invoiced !== undefined ? (
                <span className="muted">
                  {t(copy.financeInvoiced)}: <span dir="ltr">{money(data.invoiced)}</span>
                </span>
              ) : null}
            </div>
            <div className="table-wrap table-flush" aria-busy={loading}>
              <table>
                <thead>
                  <tr>
                    <th>{t(copy.number)}</th>
                    {sortHeader(t(copy.financeClient), "client")}
                    <th>{t(copy.financeRequest)}</th>
                    {sortHeader(t(copy.odooDate), "date")}
                    {sortHeader(t(copy.financeDue), "due")}
                    {sortHeader(t(copy.financeTotal), "amount")}
                    <th>{t(copy.financeRevenuePaid)}</th>
                    {sortHeader(t(copy.financeResidual), "residual")}
                    <th>{t(copy.odooState)}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.invoices.length === 0 ? (
                    <tr>
                      <td colSpan={9}>{activeFilters > 0 ? t(copy.noSearchResults) : t(copy.empty)}</td>
                    </tr>
                  ) : (
                    data.invoices.map((row) => {
                      const state = (STATES as string[]).includes(row.state) ? (row.state as FinanceInvoiceState) : null;
                      return (
                        <tr key={`${row.source}-${row.id}`}>
                          <td dir="ltr" className="nowrap">
                            {row.odoo_url ? (
                              <a className="table-link" href={row.odoo_url} target="_blank" rel="noreferrer">
                                {row.name || row.id} <ExternalLink size={12} aria-hidden="true" />
                              </a>
                            ) : (
                              <span title={t(copy.financeLocalRow)}>{row.name || row.id}</span>
                            )}
                          </td>
                          <td>{row.partner_name ?? "—"}</td>
                          <td dir="ltr" className="nowrap">
                            {row.request_id ? (
                              <Link className="table-link" to={`/requests/${row.request_id}`} title={row.title ?? undefined}>
                                {row.request_number}
                              </Link>
                            ) : (
                              row.request_number ?? "—"
                            )}
                          </td>
                          <td className="nowrap">{row.issued_at ?? "—"}</td>
                          <td className="nowrap">{row.due_at ?? "—"}</td>
                          <td dir="ltr" className="nowrap">{money(row.amount, row.currency)}</td>
                          <td dir="ltr" className="nowrap">{money(row.collected, row.currency)}</td>
                          <td dir="ltr" className="nowrap">{money(row.residual, row.currency)}</td>
                          <td>
                            <span className={state ? STATE_CLASS[state] : "status status-draft"}>
                              {state ? t(STATE_COPY[state]) : row.state}
                            </span>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
            <Pagination meta={data.meta ?? null} disabled={loading} onPage={(next) => update({ page: String(next) }, true)} t={t} />
          </section>

          <div className="finance-expenses">
            <section className="panel recent-panel">
              <div className="panel-head">
                <h2>{t(copy.financeExpenses)}</h2>
              </div>
              <div className="table-wrap table-flush">
                <table>
                  <thead>
                    <tr>
                      <th>{t(copy.financeTotal)}</th>
                      <th>{t(copy.financeCategory)}</th>
                      <th>{t(copy.financeNote)}</th>
                      <th>{t(copy.financeExpenseDate)}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.expense_rows.length === 0 ? (
                      <tr>
                        <td colSpan={4}>{activeFilters > 0 ? t(copy.noSearchResults) : t(copy.empty)}</td>
                      </tr>
                    ) : (
                      data.expense_rows.map((row) => (
                        <tr key={row.id}>
                          <td dir="ltr" className="nowrap">{money(row.amount)}</td>
                          <td>{row.category}</td>
                          <td>{row.note ?? "—"}</td>
                          <td className="nowrap">{row.spent_at ?? "—"}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </section>
            <form className="card form-grid" onSubmit={addExpense}>
              <h2 className="form-title field-span">{t(copy.financeAddExpense)}</h2>
              <label className="field-label">
                {t(copy.financeTotal)}
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
                {Number(amount) > 0 ? `${money(Number(amount))} · ${category}` : t(copy.financeConfirmHint)}
              </p>
              <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy}>
                {t(copy.financeSave)}
              </button>
            </form>
          </div>
        </>
      ) : null}
    </>
  );
}
