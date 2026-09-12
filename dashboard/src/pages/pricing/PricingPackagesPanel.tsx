import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PricingCategory, type PricingPackage, type PricingSubcategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { calcPrices, categoryLabel, featuresToText, subcategoryLabel, textToFeatures } from "./utils";

const emptyForm = {
  subcategory_id: "",
  slug: "",
  name_en: "",
  name_ar: "",
  subtitle_en: "",
  subtitle_ar: "",
  price_usd: "",
  monthly: "",
  quarterly: "",
  semiannual: "",
  yearly: "",
  features_en: "",
  features_ar: "",
  has_reach: false,
  ad_budget_usd: "",
  ad_credit_usd: "",
  reach_en: "",
  reach_ar: "",
  goal_en: "",
  goal_ar: "",
  featured: false,
  badge_en: "",
  badge_ar: "",
  is_published: true,
};

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
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [movingId, setMovingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [searchParams] = useSearchParams();

  const selectedSubcategory = useMemo(
    () => subcategories.find((item) => String(item.id) === form.subcategory_id),
    [subcategories, form.subcategory_id],
  );
  const oneTime = selectedSubcategory?.one_time ?? false;

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

  function resetForm() {
    setEditingId(null);
    setForm({
      ...emptyForm,
      subcategory_id: filterSubcategoryId || filteredSubcategories[0]?.id?.toString() || "",
    });
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setForm({
      ...emptyForm,
      subcategory_id: filterSubcategoryId || filteredSubcategories[0]?.id?.toString() || "",
    });
    setError("");
    setShowForm(true);
  }

  function startEdit(item: PricingPackage) {
    setEditingId(item.id);
    setForm({
      subcategory_id: String(item.subcategory_id),
      slug: item.slug,
      name_en: item.name_en,
      name_ar: item.name_ar,
      subtitle_en: item.subtitle_en,
      subtitle_ar: item.subtitle_ar,
      price_usd: item.price_usd != null ? String(item.price_usd) : "",
      monthly: item.prices?.monthly != null ? String(item.prices.monthly) : "",
      quarterly: item.prices?.quarterly != null ? String(item.prices.quarterly) : "",
      semiannual: item.prices?.semiannual != null ? String(item.prices.semiannual) : "",
      yearly: item.prices?.yearly != null ? String(item.prices.yearly) : "",
      features_en: featuresToText(item.features, "en"),
      features_ar: featuresToText(item.features, "ar"),
      has_reach: Boolean(item.reach),
      ad_budget_usd: item.reach?.adBudgetUsd != null ? String(item.reach.adBudgetUsd) : "",
      ad_credit_usd: item.reach?.adCreditUsd != null ? String(item.reach.adCreditUsd) : "",
      reach_en: item.reach?.estimatedReach?.en ?? "",
      reach_ar: item.reach?.estimatedReach?.ar ?? "",
      goal_en: item.reach?.goal?.en ?? "",
      goal_ar: item.reach?.goal?.ar ?? "",
      featured: item.featured,
      badge_en: item.badge_en ?? "",
      badge_ar: item.badge_ar ?? "",
      is_published: item.is_published,
    });
    setShowForm(true);
    setError("");
  }

  function applyAutoPrices() {
    const monthly = Number(form.monthly);
    if (!monthly || Number.isNaN(monthly)) return;
    const prices = calcPrices(monthly);
    setForm((prev) => ({
      ...prev,
      quarterly: String(prices.quarterly),
      semiannual: String(prices.semiannual),
      yearly: String(prices.yearly),
    }));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");

    const payload: Record<string, unknown> = {
      subcategory_id: Number(form.subcategory_id),
      slug: form.slug.trim(),
      name_en: form.name_en.trim(),
      name_ar: form.name_ar.trim(),
      subtitle_en: form.subtitle_en.trim(),
      subtitle_ar: form.subtitle_ar.trim(),
      features: textToFeatures(form.features_en, form.features_ar),
      featured: form.featured,
      badge_en: form.badge_en.trim() || null,
      badge_ar: form.badge_ar.trim() || null,
      is_published: form.is_published,
    };

    if (oneTime) {
      payload.price_usd = Number(form.price_usd);
      payload.prices = null;
    } else {
      payload.price_usd = null;
      payload.prices = {
        monthly: Number(form.monthly),
        quarterly: Number(form.quarterly),
        semiannual: Number(form.semiannual),
        yearly: Number(form.yearly),
      };
    }

    if (form.has_reach) {
      payload.reach = {
        adBudgetUsd: Number(form.ad_budget_usd),
        adCreditUsd: Number(form.ad_credit_usd),
        estimatedReach: { en: form.reach_en.trim(), ar: form.reach_ar.trim() },
        goal: { en: form.goal_en.trim(), ar: form.goal_ar.trim() },
      };
    } else {
      payload.reach = null;
    }

    try {
      if (editingId) await api.updatePricingPackage(editingId, payload);
      else await api.createPricingPackage(payload);
      resetForm();
      setNotice(t(copy.savePricingPackage));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deletePricingPackage(id);
      if (editingId === id) resetForm();
      load();
      setNotice(t(copy.deleted));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
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
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addPricingPackage)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addPricingPackage)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={t(copy.savePricingPackage)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
          size="lg"
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.pricingSubcategory)}
              <select className="field" value={form.subcategory_id} onChange={(e) => setForm((prev) => ({ ...prev, subcategory_id: e.target.value }))} required>
                <option value="">{t(copy.chooseSubcategory)}</option>
                {subcategories.map((sub) => (
                  <option key={sub.id} value={sub.id}>{subcategoryLabel(sub, locale)}</option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {t(copy.slug)}
              <input className="field" value={form.slug} onChange={(e) => setForm((prev) => ({ ...prev, slug: e.target.value }))} required pattern="[a-z0-9_-]+" />
            </label>
            <label className="field-label">
              {t(copy.titleEn)}
              <input className="field" value={form.name_en} onChange={(e) => setForm((prev) => ({ ...prev, name_en: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.titleAr)}
              <input className="field" value={form.name_ar} onChange={(e) => setForm((prev) => ({ ...prev, name_ar: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.pricingSubtitleEn)}
              <input className="field" value={form.subtitle_en} onChange={(e) => setForm((prev) => ({ ...prev, subtitle_en: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.pricingSubtitleAr)}
              <input className="field" value={form.subtitle_ar} onChange={(e) => setForm((prev) => ({ ...prev, subtitle_ar: e.target.value }))} required />
            </label>

            {oneTime ? (
              <label className="field-label">
                {t(copy.pricingOneTimePrice)}
                <input className="field" type="number" min={0} value={form.price_usd} onChange={(e) => setForm((prev) => ({ ...prev, price_usd: e.target.value }))} required />
              </label>
            ) : (
              <>
                <label className="field-label">
                  {t(copy.billingMonthly)}
                  <input className="field" type="number" min={0} value={form.monthly} onChange={(e) => setForm((prev) => ({ ...prev, monthly: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.billingQuarterly)}
                  <input className="field" type="number" min={0} value={form.quarterly} onChange={(e) => setForm((prev) => ({ ...prev, quarterly: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.billingSemiannual)}
                  <input className="field" type="number" min={0} value={form.semiannual} onChange={(e) => setForm((prev) => ({ ...prev, semiannual: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.billingYearly)}
                  <input className="field" type="number" min={0} value={form.yearly} onChange={(e) => setForm((prev) => ({ ...prev, yearly: e.target.value }))} required />
                </label>
                <div className="toolbar">
                  <button type="button" className="btn btn-ghost" onClick={applyAutoPrices}>{t(copy.pricingAutoCalc)}</button>
                </div>
              </>
            )}

            <label className="field-label">
              {t(copy.pricingFeaturesEn)}
              <textarea className="field" rows={5} value={form.features_en} onChange={(e) => setForm((prev) => ({ ...prev, features_en: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.pricingFeaturesAr)}
              <textarea className="field" rows={5} value={form.features_ar} onChange={(e) => setForm((prev) => ({ ...prev, features_ar: e.target.value }))} />
            </label>

            <label className="checkbox-row">
              <input type="checkbox" checked={form.has_reach} onChange={(e) => setForm((prev) => ({ ...prev, has_reach: e.target.checked }))} />
              {t(copy.pricingHasReach)}
            </label>

            {form.has_reach ? (
              <>
                <label className="field-label">
                  {t(copy.pricingAdBudget)}
                  <input className="field" type="number" min={0} value={form.ad_budget_usd} onChange={(e) => setForm((prev) => ({ ...prev, ad_budget_usd: e.target.value }))} />
                </label>
                <label className="field-label">
                  {t(copy.pricingAdCredit)}
                  <input className="field" type="number" min={0} value={form.ad_credit_usd} onChange={(e) => setForm((prev) => ({ ...prev, ad_credit_usd: e.target.value }))} />
                </label>
                <label className="field-label">
                  {t(copy.pricingReachEn)}
                  <input className="field" value={form.reach_en} onChange={(e) => setForm((prev) => ({ ...prev, reach_en: e.target.value }))} />
                </label>
                <label className="field-label">
                  {t(copy.pricingReachAr)}
                  <input className="field" value={form.reach_ar} onChange={(e) => setForm((prev) => ({ ...prev, reach_ar: e.target.value }))} />
                </label>
                <label className="field-label">
                  {t(copy.pricingGoalEn)}
                  <textarea className="field" rows={2} value={form.goal_en} onChange={(e) => setForm((prev) => ({ ...prev, goal_en: e.target.value }))} />
                </label>
                <label className="field-label">
                  {t(copy.pricingGoalAr)}
                  <textarea className="field" rows={2} value={form.goal_ar} onChange={(e) => setForm((prev) => ({ ...prev, goal_ar: e.target.value }))} />
                </label>
              </>
            ) : null}

            <label className="checkbox-row">
              <input type="checkbox" checked={form.featured} onChange={(e) => setForm((prev) => ({ ...prev, featured: e.target.checked }))} />
              {t(copy.featured)}
            </label>
            <label className="field-label">
              {t(copy.pricingBadgeEn)}
              <input className="field" value={form.badge_en} onChange={(e) => setForm((prev) => ({ ...prev, badge_en: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.pricingBadgeAr)}
              <input className="field" value={form.badge_ar} onChange={(e) => setForm((prev) => ({ ...prev, badge_ar: e.target.value }))} />
            </label>
            <label className="checkbox-row">
              <input type="checkbox" checked={form.is_published} onChange={(e) => setForm((prev) => ({ ...prev, is_published: e.target.checked }))} />
              {t(copy.published)}
            </label>
          </div>
        </FormDialog>
      ) : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.pricingSubcategory)}</th>
              <th>{t(copy.pricingPrice)}</th>
              <th>{t(copy.featured)}</th>
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
                  <td>{locale === "ar" ? item.name_ar : item.name_en}</td>
                  <td>{item.subcategory ? subcategoryLabel(item.subcategory, locale) : item.subcategory_id}</td>
                  <td>{priceSummary(item)}</td>
                  <td>{item.featured ? t(copy.featured) : "—"}</td>
                  <td>
                    <div className="order-actions">
                      <button type="button" className="btn btn-order" disabled={index === 0 || movingId === item.id} onClick={() => void move(item.id, "up")}>↑</button>
                      <button type="button" className="btn btn-order" disabled={index === items.length - 1 || movingId === item.id} onClick={() => void move(item.id, "down")}>↓</button>
                    </div>
                  </td>
                  <td className="actions-cell">
                    <button type="button" className="btn btn-ghost" onClick={() => startEdit(item)}>{t(copy.edit)}</button>
                    <button type="button" className="btn btn-ghost" onClick={() => void remove(item.id)}>{t(copy.delete)}</button>
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
