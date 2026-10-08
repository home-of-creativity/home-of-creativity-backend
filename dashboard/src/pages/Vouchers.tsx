import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { FileText, Search } from "lucide-react";
import { toast } from "sonner";
import { api, canAbility, type FinancialVoucherSummary, type VoucherKind } from "../api";
import { useAuth } from "../auth";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";
import { voucherKindCopy } from "./VoucherStudio";

function money(amount: number, currency: string) {
  return `${amount.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export function Vouchers({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [rows, setRows] = useState<FinancialVoucherSummary[] | null>(null);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const [kind, setKind] = useState<"" | VoucherKind>("");
  const canCreate = canAbility(user, "ops.vouchers.create");
  const canDelete = canAbility(user, "ops.vouchers.delete");

  useEffect(() => {
    api
      .vouchers()
      .then((res) => setRows(res.data))
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return (rows ?? []).filter((row) => {
      if (kind && row.kind !== kind) return false;
      if (!needle) return true;
      return row.serial.toLowerCase().includes(needle) || row.party_name.toLowerCase().includes(needle);
    });
  }, [rows, query, kind]);

  async function remove(row: FinancialVoucherSummary) {
    if (!window.confirm(t(copy.voucherDeleteConfirm))) return;
    try {
      await api.deleteVoucher(row.id);
      setRows((current) => (current ?? []).filter((item) => item.id !== row.id));
      toast.success(t(copy.voucherDeleted));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  if (!rows && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <>
      <PageHeader
        title={t(copy.vouchersTitle)}
        lede={t(copy.vouchersLede)}
        actions={canCreate ? <Link className="btn btn-primary" to="/vouchers/new">{t(copy.voucherNew)}</Link> : null}
      />
      {error ? <p className="error">{error}</p> : null}
      <div className="toolbar filter-bar filter-grid voucher-filters">
        <label className="field-label">
          {t(copy.search)}
          <span className="search-bar">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              className="field"
              placeholder={t(copy.voucherSearch)}
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              aria-label={t(copy.search)}
            />
          </span>
        </label>
        <label className="field-label">
          {t(copy.voucherKind)}
          <select className="field" value={kind} onChange={(event) => setKind(event.target.value as "" | VoucherKind)}>
            <option value="">{t(copy.all)}</option>
            {(["receipt", "payment", "journal", "settlement"] as const).map((item) => (
              <option key={item} value={item}>{t(voucherKindCopy[item].name)}</option>
            ))}
          </select>
        </label>
      </div>
      {rows && rows.length === 0 ? (
        <section className="empty-state">
          <FileText size={28} aria-hidden="true" />
          <h2>{t(copy.voucherEmpty)}</h2>
          {canCreate ? <Link className="btn btn-primary" to="/vouchers/new">{t(copy.voucherNew)}</Link> : null}
        </section>
      ) : (
        <section className="panel recent-panel">
          <div className="table-wrap table-flush">
            <table>
              <thead>
                <tr>
                  <th>{t(copy.voucherSerial)}</th>
                  <th>{t(copy.voucherKind)}</th>
                  <th>{t(copy.voucherParty)}</th>
                  <th>{t(copy.voucherAmount)}</th>
                  <th>{t(copy.voucherDate)}</th>
                  <th>{t(copy.status)}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {visible.length === 0 ? (
                  <tr>
                    <td colSpan={7}>{t(copy.noSearchResults)}</td>
                  </tr>
                ) : visible.map((row) => (
                  <tr key={row.id}>
                    <td dir="ltr">{row.serial}</td>
                    <td>{t(voucherKindCopy[row.kind].name)}</td>
                    <td>{row.party_name}</td>
                    <td dir="ltr">{money(row.amount, row.currency)}</td>
                    <td>{row.issued_on ? new Date(row.issued_on).toLocaleDateString(locale === "ar" ? "ar-SY" : "en-GB") : "—"}</td>
                    <td>
                      <span className={row.signed ? "status-dot is-live" : "status-dot"}>{row.signed ? t(copy.voucherSigned) : t(copy.voucherDraft)}</span>
                    </td>
                    <td className="row-actions">
                      <Link className="btn btn-ghost btn-sm" to={`/vouchers/${row.id}`}>{t(copy.voucherOpen)}</Link>
                      {canDelete ? (
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => void remove(row)}>{t(copy.delete)}</button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </>
  );
}
