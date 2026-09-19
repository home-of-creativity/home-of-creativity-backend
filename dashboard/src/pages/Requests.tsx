import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, type PageMeta, type ServiceRequest } from "../api";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { copy, sources, statuses, type Locale } from "../i18n";
import { useLive, useLiveStamp } from "../live";

export function Requests({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [items, setItems] = useState<ServiceRequest[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);

  const { requestsStamp } = useLive();

  const load = useCallback((silent = false) => {
    if (!silent) setLoading(true);
    api
      .requests(status || undefined, page)
      .then((res) => {
        setItems(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        if (!silent) {
          setItems([]);
          setMeta(null);
        }
      })
      .finally(() => {
        if (!silent) setLoading(false);
      });
  }, [status, page]);

  useEffect(() => {
    load();
  }, [load]);

  useLiveStamp(requestsStamp, () => load(true));

  return (
    <>
      <PageHeader eyebrow={t(copy.brandMark)} title={t(copy.requests)} lede={t(copy.requestsLede)} />
      <div className="toolbar filter-bar">
        <label className="filter-label" htmlFor="request-status-filter">
          {t(copy.status)}
        </label>
        <select
          id="request-status-filter"
          className="field"
          value={status}
          onChange={(e) => {
            setPage(1);
            setStatus(e.target.value);
          }}
        >
          <option value="">{t(copy.all)}</option>
          {Object.entries(statuses).map(([key, label]) => (
            <option key={key} value={key}>
              {t(label)}
            </option>
          ))}
        </select>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.number)}</th>
              <th>{t(copy.title)}</th>
              <th>{t(copy.client)}</th>
              <th>{t(copy.status)}</th>
              <th>{t(copy.source)}</th>
              <th>{t(copy.driveDeliveries)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={6} label={t(copy.loading)} />
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={6}>{t(copy.empty)}</td>
              </tr>
            ) : (
              items.map((item) => (
                <tr key={item.id}>
                  <td>
                    <Link className="table-link" to={`/requests/${item.id}`}>
                      {item.number}
                    </Link>
                  </td>
                  <td>{item.title}</td>
                  <td>{item.client?.name ?? "—"}</td>
                  <td>
                    <span className={`status status-${item.status}`}>
                      {t(statuses[item.status] ?? { ar: item.status, en: item.status })}
                    </span>
                  </td>
                  <td>
                    <span className={`source source-${item.source}`}>
                      {t(sources[item.source] ?? { ar: item.source, en: item.source })}
                    </span>
                  </td>
                  <td>
                    {item.drive_delivery_summary && item.drive_delivery_summary.total > 0 ? (
                      <span className={`status status-${item.drive_delivery_summary.failed ? "failed" : item.drive_delivery_summary.pending ? "pending" : "sent"}`}>
                        {item.drive_delivery_summary.sent}/{item.drive_delivery_summary.total} {t(copy.driveSent)}
                      </span>
                    ) : (
                      "—"
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </>
  );
}
