import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { LoadingLottie } from "../components/LoadingLottie";
import { IconClients, IconRequests } from "../components/icons";
import { api, type ServiceRequest } from "../api";
import { copy, statuses, type Locale } from "../i18n";

const ORANGE_STATUSES = new Set(["quotation_sent", "ai_analyzing", "revision_requested"]);
const TEAL_STATUSES = new Set(["payment_confirmed", "completed", "approved", "in_progress"]);
const DANGER_STATUSES = new Set(["quotation_rejected", "cancelled"]);

function statusTone(status: string) {
  if (ORANGE_STATUSES.has(status)) return "orange";
  if (TEAL_STATUSES.has(status)) return "teal";
  if (DANGER_STATUSES.has(status)) return "danger";
  return "default";
}

export function Overview({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [data, setData] = useState<{ clients: number; requests: number; by_status: Record<string, number> } | null>(null);
  const [recent, setRecent] = useState<ServiceRequest[]>([]);

  useEffect(() => {
    api.overview().then((res) => setData(res.data)).catch(() => setData(null));
    api.requests().then((res) => setRecent(res.data.slice(0, 6))).catch(() => setRecent([]));
  }, []);

  if (!data) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <>
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.overview)}</h1>
          <p className="page-lede">{t(copy.overviewLede)}</p>
        </div>
      </header>
      <div className="cards">
        <article className="card card-accent">
          <div className="card-head">
            <span className="card-icon">
              <IconClients aria-hidden />
            </span>
            <span className="muted">{t(copy.clientsCount)}</span>
          </div>
          <strong>{data.clients}</strong>
        </article>
        <article className="card card-accent">
          <div className="card-head">
            <span className="card-icon">
              <IconRequests aria-hidden />
            </span>
            <span className="muted">{t(copy.requestsCount)}</span>
          </div>
          <strong>{data.requests}</strong>
        </article>
        {Object.entries(data.by_status).map(([status, total]) => (
          <article className="card" key={status}>
            <div className="card-head">
              <span className={`card-dot card-dot-${statusTone(status)}`} aria-hidden />
              <span className="muted">{t(statuses[status] ?? { ar: status, en: status })}</span>
            </div>
            <strong>{total}</strong>
          </article>
        ))}
      </div>
      <section className="panel recent-panel">
        <div className="panel-head">
          <h2>{t(copy.recent)}</h2>
          <Link className="btn btn-ghost" to="/requests">
            {t(copy.viewAll)}
          </Link>
        </div>
        <div className="table-wrap table-flush">
          <table>
            <thead>
              <tr>
                <th>{t(copy.number)}</th>
                <th>{t(copy.title)}</th>
                <th>{t(copy.status)}</th>
              </tr>
            </thead>
            <tbody>
              {recent.length === 0 ? (
                <tr>
                  <td colSpan={3}>{t(copy.empty)}</td>
                </tr>
              ) : (
                recent.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <Link className="table-link" to={`/requests/${item.id}`}>
                        {item.number}
                      </Link>
                    </td>
                    <td>{item.title}</td>
                    <td>
                      <span className={`status status-${item.status}`}>
                        {t(statuses[item.status] ?? { ar: item.status, en: item.status })}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  );
}
