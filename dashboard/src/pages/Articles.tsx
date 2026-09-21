import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { api, type Article, type PageMeta } from "../api";
import { copy, type Locale } from "../i18n";

export function Articles({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [articles, setArticles] = useState<Article[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  function load() {
    setLoading(true);
    api
      .articles(page)
      .then((res) => {
        setArticles(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setArticles([]);
        setMeta(null);
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, [page]);

  function formatDate(value: string | null) {
    if (!value) return "—";
    try {
      return new Date(value).toLocaleDateString(locale === "ar" ? "ar" : "en", {
        year: "numeric",
        month: "short",
        day: "numeric",
      });
    } catch {
      return value.slice(0, 10);
    }
  }

  async function remove(id: number) {
    setError("");
    try {
      await api.deleteArticle(id);
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
      await api.deleteAllArticles();
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
        title={t(copy.articlesTitle)}
        lede={t(copy.articlesLede)}
        actions={
          <>
            <Link className="btn btn-primary" to="/articles/new">
              {t(copy.addArticle)}
            </Link>
            <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={articles.length === 0}>
              {t(copy.deleteAll)}
            </button>
          </>
        }
      />

      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.slug)}</th>
              <th>{t(copy.articleDate)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={5} label={t(copy.loading)} />
            ) : articles.length === 0 ? (
              <tr>
                <td colSpan={5}>{t(copy.empty)}</td>
              </tr>
            ) : (
              articles.map((item) => (
                <tr key={item.id}>
                  <td>{locale === "ar" ? item.title_ar : item.title_en}</td>
                  <td className="muted">{item.slug}</td>
                  <td>{formatDate(item.published_at)}</td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/articles/${item.id}/edit`}>
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

export default Articles;
