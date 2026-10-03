import { useEffect, useState, type FormEvent } from "react";
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

  useEffect(() => {
    api
      .finance()
      .then((res) => {
        setData(res.data);
        setCategory(res.data.categories[0] ?? "");
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

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
      setData(res.data);
      setAmount("");
      setNote("");
      toast.success(res.message ?? t(copy.saveSuccess));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

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
                      <td colSpan={3}>{t(copy.empty)}</td>
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
