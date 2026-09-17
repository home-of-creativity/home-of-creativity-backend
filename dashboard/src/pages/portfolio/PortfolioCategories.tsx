import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { PageHeader } from "../../components/PageHeader";
import { api, type PortfolioCategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

export function PortfolioCategories({
  locale,
  t,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
}) {
  const [categories, setCategories] = useState<PortfolioCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);

  function load() {
    setLoading(true);
    api
      .portfolioCategories()
      .then((res) => setCategories(res.data))
      .catch(() => setCategories([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  async function remove(id: number) {
    setError("");
    try {
      await api.deletePortfolioCategory(id);
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
      await api.deleteAllPortfolioCategories();
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function move(id: number, direction: "up" | "down") {
    setError("");
    setNotice("");
    setMovingId(id);
    try {
      const res = await api.movePortfolioCategory(id, direction);
      setCategories(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setMovingId(null);
    }
  }

  return (
    <>
      <PageHeader
        eyebrow={t(copy.brandMark)}
        title={t(copy.portfolioTabCategories)}
        lede={t(copy.categoriesLede)}
        actions={
          <>
            <Link className="btn btn-primary" to="/categories/new">
              {t(copy.addPortfolioCategory)}
            </Link>
            <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={categories.length === 0}>
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
              <th>{t(copy.slug)}</th>
              <th>{t(copy.projectsCount)}</th>
              <th>{t(copy.order)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={6} label={t(copy.loading)} />
            ) : categories.length === 0 ? (
              <tr>
                <td colSpan={6}>{t(copy.empty)}</td>
              </tr>
            ) : (
              categories.map((item, index) => (
                <tr key={item.id}>
                  <td>{categoryLabel(item, locale)}</td>
                  <td>{item.slug}</td>
                  <td>{item.projects_count ?? 0}</td>
                  <td>
                    <div className="order-actions">
                      <button
                        type="button"
                        className="btn btn-order"
                        aria-label={t(copy.moveUp)}
                        disabled={index === 0 || movingId === item.id}
                        onClick={() => void move(item.id, "up")}
                      >
                        ↑
                      </button>
                      <button
                        type="button"
                        className="btn btn-order"
                        aria-label={t(copy.moveDown)}
                        disabled={index === categories.length - 1 || movingId === item.id}
                        onClick={() => void move(item.id, "down")}
                      >
                        ↓
                      </button>
                    </div>
                  </td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/categories/${item.id}/edit`}>
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
    </>
  );
}
