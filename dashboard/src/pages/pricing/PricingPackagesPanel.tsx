import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PricingCategory, type PricingPackage, type PricingSubcategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel, subcategoryLabel } from "./utils";

export function PricingPackagesPanel({
  locale,
  t,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
}) {
  const [categories, setCategories] = useState<PricingCategory[]>([]);
  const [subcategories, setSubcategories] = useState<PricingSubcategory[]>([]);
  const [items, setItems] = useState<PricingPackage[]>([]);
  const [filterCategoryId, setFilterCategoryId] = useState("");
  const [filterSubcategoryId, setFilterSubcategoryId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);
  const [searchParams] = useSearchParams();

  function loadMeta() {
    api.pricingCategories().then((res) => setCategories(res.data)).catch(() => setCategories([]));
    api.pricingSubcategories().then((res) => setSubcategories(res.data)).catch(() => setSubcategories([]));
  }

  function load() {
    setLoading(true);
    api
      .pricingPackages({
        categoryId: filterCategoryId ? Number(filterCategoryId) : undefined,
        subcategoryId: filterSubcategoryId ? Number(filterSubcategoryId) : undefined,
      })
      .then((res) => setItems(res.data))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    loadMeta();
  }, []);

  useEffect(() => {
    setFilterCategoryId(searchParams.get("category_id") ?? "");
    setFilterSubcategoryId(searchParams.get("subcategory_id") ?? "");
  }, [searchParams]);

  useEffect(() => {
    load();
  }, [filterCategoryId, filterSubcategoryId]);

  const filteredSubcategories = useMemo(() => {
    if (!filterCategoryId) return subcategories;
    return subcategories.filter((item) => String(item.category_id) === filterCategoryId);
  }, [subcategories, filterCategoryId]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deletePricingPackage(id);
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
      const res = await api.movePricingPackage(id, direction);
      setItems(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setMovingId(null);
    }
  }

  function priceSummary(item: PricingPackage) {
    if (item.price_usd != null) return `$${item.price_usd}`;
    if (item.prices?.monthly != null) return `$${item.prices.monthly}/${t(copy.billingMonthly)}`;
    return "—";
  }

  const addHref = filterSubcategoryId ? `/pricing/packages/new?subcategory_id=${filterSubcategoryId}` : "/pricing/packages/new";

  return (
    <>
      <div className="toolbar">
        <label className="field-label">
          {t(copy.category)}
          <select className="field" value={filterCategoryId} onChange={(e) => { setFilterCategoryId(e.target.value); setFilterSubcategoryId(""); }}>
            <option value="">{t(copy.allCategories)}</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>{categoryLabel(cat, locale)}</option>
            ))}
          </select>
        </label>
        <label className="field-label">
          {t(copy.pricingSubcategory)}
          <select className="field" value={filterSubcategoryId} onChange={(e) => setFilterSubcategoryId(e.target.value)}>
            <option value="">{t(copy.allSubcategories)}</option>
            {filteredSubcategories.map((sub) => (
              <option key={sub.id} value={sub.id}>{subcategoryLabel(sub, locale)}</option>
            ))}
          </select>
        </label>
        <Link className="btn btn-primary" to={addHref}>
          {t(copy.addPricingPackage)}
        </Link>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.pricingSubcategory)}</th>
              <th>{t(copy.pricingPrice)}</th>
              <th>{t(copy.featured)}</th>
              <th>{t(copy.paymentPlan)}</th>
              <th>{t(copy.order)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={7} label={t(copy.loading)} />
            ) : items.length === 0 ? (
              <tr><td colSpan={7}>{t(copy.empty)}</td></tr>
            ) : (
              items.map((item, index) => (
                <tr key={item.id}>
                  <td>{locale === "ar" ? item.name_ar : item.name_en}</td>
                  <td>{item.subcategory ? subcategoryLabel(item.subcategory, locale) : item.subcategory_id}</td>
                  <td>{priceSummary(item)}</td>
                  <td>{item.featured ? t(copy.featured) : "—"}</td>
                  <td>
                    <span className={`pay-badge ${item.allows_partial_payment === false ? "pay-badge-full" : "pay-badge-partial"}`}>
                      {item.allows_partial_payment === false
                        ? t(copy.fullPayment)
                        : item.allows_partial_payment === true
                          ? t(copy.partialPayment)
                          : t(copy.inheritCategory)}
                    </span>
                  </td>
                  <td>
                    <div className="order-actions">
                      <button type="button" className="btn btn-order" disabled={index === 0 || movingId === item.id} onClick={() => void move(item.id, "up")}>↑</button>
                      <button type="button" className="btn btn-order" disabled={index === items.length - 1 || movingId === item.id} onClick={() => void move(item.id, "down")}>↓</button>
                    </div>
                  </td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/pricing/packages/${item.id}/edit`}>{t(copy.edit)}</Link>
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
