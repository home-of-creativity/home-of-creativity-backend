import { useEffect, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PricingCategory, type PricingSubcategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel, subcategoryLabel } from "./utils";

const emptyForm = {
  category_id: "",
  slug: "",
  name_en: "",
  name_ar: "",
  lead_en: "",
  lead_ar: "",
  one_time: false,
  lead_in_box: false,
  lead_note_en: "",
  lead_note_ar: "",
  is_published: true,
};

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
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [movingId, setMovingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyForm);
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

  function resetForm() {
    setEditingId(null);
    setForm({ ...emptyForm, category_id: filterCategoryId || categories[0]?.id?.toString() || "" });
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setForm({ ...emptyForm, category_id: filterCategoryId || categories[0]?.id?.toString() || "" });
    setError("");
    setShowForm(true);
  }

  function startEdit(item: PricingSubcategory) {
    setEditingId(item.id);
    setForm({
      category_id: String(item.category_id),
      slug: item.slug,
      name_en: item.name_en,
      name_ar: item.name_ar,
      lead_en: item.lead_en ?? "",
      lead_ar: item.lead_ar ?? "",
      one_time: item.one_time,
      lead_in_box: item.lead_in_box,
      lead_note_en: item.lead_note_en ?? "",
      lead_note_ar: item.lead_note_ar ?? "",
      is_published: item.is_published,
    });
    setShowForm(true);
    setError("");
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    const payload = {
      category_id: Number(form.category_id),
      slug: form.slug.trim(),
      name_en: form.name_en.trim(),
      name_ar: form.name_ar.trim(),
      lead_en: form.lead_en.trim() || null,
      lead_ar: form.lead_ar.trim() || null,
      one_time: form.one_time,
      lead_in_box: form.lead_in_box,
      lead_note_en: form.lead_note_en.trim() || null,
      lead_note_ar: form.lead_note_ar.trim() || null,
      is_published: form.is_published,
    };

    try {
      if (editingId) await api.updatePricingSubcategory(editingId, payload);
      else await api.createPricingSubcategory(payload);
      resetForm();
      setNotice(t(copy.savePricingSubcategory));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deletePricingSubcategory(id);
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
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addPricingSubcategory)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addPricingSubcategory)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={t(copy.savePricingSubcategory)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
          size="lg"
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.category)}
              <select className="field" value={form.category_id} onChange={(e) => setForm((prev) => ({ ...prev, category_id: e.target.value }))} required>
                <option value="">{t(copy.chooseCategory)}</option>
                {categories.map((cat) => (
                  <option key={cat.id} value={cat.id}>{categoryLabel(cat, locale)}</option>
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
              {t(copy.leadEn)}
              <textarea className="field" rows={2} value={form.lead_en} onChange={(e) => setForm((prev) => ({ ...prev, lead_en: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.leadAr)}
              <textarea className="field" rows={2} value={form.lead_ar} onChange={(e) => setForm((prev) => ({ ...prev, lead_ar: e.target.value }))} />
            </label>
            <label className="checkbox-row">
              <input type="checkbox" checked={form.one_time} onChange={(e) => setForm((prev) => ({ ...prev, one_time: e.target.checked }))} />
              {t(copy.pricingOneTime)}
            </label>
            <label className="checkbox-row">
              <input type="checkbox" checked={form.lead_in_box} onChange={(e) => setForm((prev) => ({ ...prev, lead_in_box: e.target.checked }))} />
              {t(copy.pricingLeadInBox)}
            </label>
            <label className="field-label">
              {t(copy.pricingLeadNoteEn)}
              <input className="field" value={form.lead_note_en} onChange={(e) => setForm((prev) => ({ ...prev, lead_note_en: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.pricingLeadNoteAr)}
              <input className="field" value={form.lead_note_ar} onChange={(e) => setForm((prev) => ({ ...prev, lead_note_ar: e.target.value }))} />
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
