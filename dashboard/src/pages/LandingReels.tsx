import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { api, type LandingReel, type PageMeta } from "../api";
import { copy, type Locale } from "../i18n";

export function LandingReels({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [reels, setReels] = useState<LandingReel[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  function load() {
    setLoading(true);
    api
      .landingReels(page)
      .then((res) => {
        setReels(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setReels([]);
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
      await api.deleteLandingReel(id);
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
      await api.deleteAllLandingReels();
      setPage(1);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  return (
    <>
      <PageHeader
        eyebrow={t(copy.brandMark)}
        title={t(copy.reelsTitle)}
        lede={t(copy.reelsLede)}
        actions={
          <>
            <Link className="btn btn-primary" to="/reels/new">
              {t(copy.addReel)}
            </Link>
            <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={reels.length === 0}>
              {t(copy.deleteAll)}
            </button>
          </>
        }
      />

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.sortOrder)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={4} label={t(copy.loading)} />
            ) : reels.length === 0 ? (
              <tr>
                <td colSpan={4}>{t(copy.empty)}</td>
              </tr>
            ) : (
              reels.map((item) => (
                <tr key={item.id}>
                  <td>
                    <div className="logo-row">
                      {item.poster_url ? (
                        <img src={item.poster_url} alt="" className="logo-row-thumb project-thumb" />
                      ) : item.video_url ? (
                        <video src={item.video_url} className="logo-row-thumb project-thumb" muted playsInline preload="none" />
                      ) : null}
                      <span>{locale === "ar" ? item.title_ar : item.title_en}</span>
                    </div>
                  </td>
                  <td>{item.sort_order}</td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/reels/${item.id}/edit`}>
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

export default LandingReels;
