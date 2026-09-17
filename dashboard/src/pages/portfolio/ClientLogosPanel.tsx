import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingLottie } from "../../components/LoadingLottie";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PageMeta, type ShowcaseClient } from "../../api";
import { Pagination } from "../../components/Pagination";
import { copy, type Locale } from "../../i18n";

export function ClientLogosPanel({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [clients, setClients] = useState<ShowcaseClient[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const publishedClients = useMemo(() => clients.filter((item) => item.is_published), [clients]);

  function load() {
    setLoading(true);
    api
      .showcaseClients(page)
      .then((res) => {
        setClients(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setClients([]);
        setMeta(null);
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, [page]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deleteShowcaseClient(id);
      setNotice(t(copy.deleted));
      toast.success(t(copy.deleteSuccess));
      load();
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    }
  }

  async function removeAll() {
    if (!window.confirm(t(copy.confirmDeleteAll))) return;
    setError("");
    try {
      await api.deleteAllShowcaseClients();
      setPage(1);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  return (
    <>
      <div className="toolbar">
        <Link className="btn btn-primary" to="/clients/logos/new">
          {t(copy.addShowcaseClient)}
        </Link>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={clients.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <section className="card logo-showcase-card">
        <div className="logo-showcase-head">
          <h2 className="section-title">{t(copy.logoPreview)}</h2>
          <p className="muted">
            {publishedClients.length} {t(copy.clientsCount)}
          </p>
        </div>
        <div className="logo-showcase-grid" aria-label={t(copy.logoPreview)}>
          {loading ? (
            <LoadingLottie label={t(copy.loading)} />
          ) : publishedClients.length === 0 ? (
            <p className="muted">{t(copy.empty)}</p>
          ) : (
            publishedClients.map((item) => (
              <div key={item.id} className="logo-showcase-item" aria-label={item.name}>
                {item.logo_url ? (
                  <img src={item.logo_url} alt={item.name} loading="lazy" />
                ) : (
                  <span className="logo-showcase-fallback">{item.name.slice(0, 2).toUpperCase()}</span>
                )}
              </div>
            ))
          )}
        </div>
      </section>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.client)}</th>
              <th>{t(copy.website)}</th>
              <th>{t(copy.sortOrder)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={5} label={t(copy.loading)} />
            ) : clients.length === 0 ? (
              <tr>
                <td colSpan={5}>{t(copy.empty)}</td>
              </tr>
            ) : (
              clients.map((item) => (
                <tr key={item.id}>
                  <td>
                    <div className="logo-row">
                      {item.logo_url ? <img src={item.logo_url} alt="" className="logo-row-thumb" /> : <span className="logo-row-fallback">{t(copy.noLogo)}</span>}
                      <span>{item.name}</span>
                    </div>
                  </td>
                  <td>{item.website_url ? <a href={item.website_url} target="_blank" rel="noreferrer">{item.website_url}</a> : "—"}</td>
                  <td>{item.sort_order}</td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/clients/logos/${item.id}/edit`}>
                      {t(copy.edit)}
                    </Link>
                    <ConfirmAction
                      label={t(copy.delete)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      onConfirm={() => void remove(item.id)}
                    />
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
