import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { DonutStat } from "../components/DonutStat";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { StatCard } from "../components/StatCard";
import { IconClients, IconRequests } from "../components/icons";
import { api, type ServiceRequest } from "../api";
import { copy, statuses, type Locale } from "../i18n";

const ORANGE_STATUSES = new Set(["quotation_sent", "ai_analyzing", "revision_requested"]);
const TEAL_STATUSES = new Set(["payment_confirmed", "completed", "approved", "in_progress"]);
const DANGER_STATUSES = new Set(["quotation_rejected", "cancelled"]);

const TONE_COLOR: Record<string, string> = {
  orange: "var(--brand-orange)",
  teal: "var(--brand-teal)",
  danger: "#c0392b",
  default: "var(--brand-purple)",
};

function statusTone(status: string) {
  if (ORANGE_STATUSES.has(status)) return "orange";
  if (TEAL_STATUSES.has(status)) return "teal";
  if (DANGER_STATUSES.has(status)) return "danger";
  return "default";
}

export function Overview({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [data, setData] = useState<{
    clients: number;
    requests: number;
    by_status: Record<string, number>;
    recent?: ServiceRequest[];
  } | null>(null);
  const [recent, setRecent] = useState<ServiceRequest[]>([]);

  useEffect(() => {
    api
      .overview()
      .then((res) => {
        setData(res.data);
        setRecent(res.data.recent ?? []);
      })
      .catch(() => {
        setData(null);
        setRecent([]);
      });
  }, []);

  if (!data) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const statusTotal = Object.values(data.by_status).reduce((sum, value) => sum + value, 0);
  const slices = Object.entries(data.by_status).map(([status, value]) => ({
    key: status,
    value,
    color: TONE_COLOR[statusTone(status)],
    label: t(statuses[status] ?? { ar: status, en: status }),
  }));

  return (
    <>
      <PageHeader eyebrow={t(copy.brandMark)} title={t(copy.overview)} lede={t(copy.overviewLede)} />

      <div className="overview-layout">
        <div className="overview-side">
          <div className="cards">
            <StatCard icon={<IconClients aria-hidden />} label={t(copy.clientsCount)} value={data.clients} />
            <StatCard icon={<IconRequests aria-hidden />} label={t(copy.requestsCount)} value={data.requests} />
          </div>
          <section className="panel recent-panel">
            <div className="panel-head">
              <h2>{t(copy.status)}</h2>
            </div>
            <div style={{ padding: "0.5rem 1.15rem 1.25rem" }}>
              <DonutStat slices={slices} total={statusTotal} centerLabel={t(copy.status)} emptyLabel={t(copy.empty)} />
            </div>
          </section>
        </div>
        <section className="panel recent-panel overview-recent">
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
      </div>
    </>
  );
}
