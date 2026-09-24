import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, type Client, type PageMeta } from "../api";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { copy, type Locale } from "../i18n";

export function Reports({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [items, setItems] = useState<Client[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setLoading(true);
    api.reportClients(page)
      .then((res) => {
        setItems(res.data);
        setMeta(res.meta);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [page, t]);

  return (
    <section>
      <PageHeader title={t(copy.reportsTitle)} lede={t(copy.reportsLede)} />
      {error ? <p className="error">{error}</p> : null}
      <div className="table-wrap card">
        <table className="table-flush">
          <thead>
            <tr>
              <th>{t(copy.employeeName)}</th>
              <th>{t(copy.company)}</th>
              <th>{t(copy.reportsTitle)}</th>
              <th>{t(copy.driveFolder)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? <LoadingTableRow colSpan={4} /> : null}
            {!loading && items.length === 0 ? (
              <tr>
                <td colSpan={4}>{t(copy.noReports)}</td>
              </tr>
            ) : null}
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <Link to={`/reports/clients/${item.id}`}>{item.name}</Link>
                </td>
                <td>{item.company_name || "—"}</td>
                <td>{item.reports_count ?? 0}</td>
                <td>{item.google_drive_folder_id ? t(copy.driveFolderExisting) : "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta ? <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} /> : null}
    </section>
  );
}
