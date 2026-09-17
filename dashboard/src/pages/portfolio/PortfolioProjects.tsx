import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { PageHeader } from "../../components/PageHeader";
import { api, type PageMeta, type PortfolioCategory, type PortfolioProject } from "../../api";
import { Pagination } from "../../components/Pagination";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

export function PortfolioProjects({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [projects, setProjects] = useState<PortfolioProject[]>([]);
  const [, setCategories] = useState<PortfolioCategory[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  function loadCategories() {
    api.portfolioCategories().then((res) => setCategories(res.data)).catch(() => setCategories([]));
  }

  function load() {
    setLoading(true);
    api
      .portfolioProjects(page)
      .then((res) => {
        setProjects(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setProjects([]);
        setMeta(null);
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadCategories();
  }, []);

  useEffect(() => {
    load();
  }, [page]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deletePortfolioProject(id);
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
      await api.deleteAllPortfolioProjects();
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
        title={t(copy.portfolioTabProjects)}
        lede={t(copy.projectsLede)}
        actions={
          <>
            <Link className="btn btn-primary" to="/projects/new">
              {t(copy.addPortfolioProject)}
            </Link>
            <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={projects.length === 0}>
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
              <th>{t(copy.category)}</th>
              <th>{t(copy.featured)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={5} label={t(copy.loading)} />
            ) : projects.length === 0 ? (
              <tr>
                <td colSpan={5}>{t(copy.empty)}</td>
              </tr>
            ) : (
              projects.map((item) => (
                <tr key={item.id}>
                  <td>
                    <div className="logo-row">
                      {item.image_url ? <img src={item.image_url} alt="" referrerPolicy="no-referrer" className="logo-row-thumb project-thumb" /> : null}
                      <span>{locale === "ar" ? item.title_ar : item.title_en}</span>
                    </div>
                  </td>
                  <td>{categoryLabel(item.category, locale)}</td>
                  <td>{item.featured ? "✓" : "—"}</td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/projects/${item.id}/edit`}>
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
