import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PricingCategory, type PricingSubcategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel, subcategoryLabel } from "./utils";

export function PricingSubcategoriesPanel({
  locale,
  t,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
}) {
  const [categories, setCategories] = useState<PricingCategory[]>([]);
  const [items, setItems] = useState<PricingSubcategory[]>([]);
  const [filterCategoryId, setFilterCategoryId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);
  const [, setSearchParams] = useSearchParams();

  function viewPackages(item: PricingSubcategory) {
    setSearchParams({
      tab: "packages",
      category_id: String(item.category_id),
      subcategory_id: String(item.id),
    });
  }

  function loadCategories() {
    api.pricingCategories().then((res) => setCategories(res.data)).catch(() => setCategories([]));
  }

  function load() {
    setLoading(true);
    api
      .pricingSubcategories(filterCategoryId ? Number(filterCategoryId) : undefined)
      .then((res) => setItems(res.data))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadCategories();
  }, []);

  useEffect(() => {
    load();
  }, [filterCategoryId]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deletePricingSubcategory(id);
      load();
      setNotice(t(copy.deleted));
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    }
  }

  async function move(id: number, direction: "up" | "down") {
    setError("");
    setMovingId(id);
    try {
      const res = await api.movePricingSubcategory(id, direction);
      setItems(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setMovingId(null);
    }
  }

  return (
    <>
      <div className="toolbar">
        <label className="field-label">
          {t(copy.category)}
          <select className="field" value={filterCategoryId} onChange={(e) => setFilterCategoryId(e.target.value)}>
            <option value="">{t(copy.allCategories)}</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {categoryLabel(cat, locale)}
              </option>
            ))}
          </select>
        </label>
        <Link className="btn btn-primary" to="/pricing/subcategories/new">
          {t(copy.addPricingSubcategory)}
        </Link>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.category)}</th>
              <th>{t(copy.pricingPackagesCount)}</th>
              <th>{t(copy.pricingBillingType)}</th>
              <th>{t(copy.order)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={6} label={t(copy.loading)} />
            ) : items.length === 0 ? (
              <tr><td colSpan={6}>{t(copy.empty)}</td></tr>
            ) : (
              items.map((item, index) => (
                <tr key={item.id}>
                  <td>{subcategoryLabel(item, locale)}</td>
                  <td>{item.category ? categoryLabel(item.category, locale) : item.category_id}</td>
                  <td>
                    <div className="package-link-cell">
                      <span>{item.packages_count ?? 0}</span>
                      <button type="button" className="btn btn-ghost" onClick={() => viewPackages(item)}>
                        {t(copy.pricingViewPackages)}
                      </button>
                    </div>
                  </td>
                  <td>{item.one_time ? t(copy.pricingOneTime) : t(copy.pricingSubscription)}</td>
                  <td>
                    <div className="order-actions">
                      <button type="button" className="btn btn-order" disabled={index === 0 || movingId === item.id} onClick={() => void move(item.id, "up")}>↑</button>
                      <button type="button" className="btn btn-order" disabled={index === items.length - 1 || movingId === item.id} onClick={() => void move(item.id, "down")}>↓</button>
                    </div>
                  </td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/pricing/subcategories/${item.id}/edit`}>{t(copy.edit)}</Link>
                    <ConfirmAction label={t(copy.delete)} yesLabel={t(copy.delete)} noLabel={t(copy.cancel)} onConfirm={() => void remove(item.id)} />
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
