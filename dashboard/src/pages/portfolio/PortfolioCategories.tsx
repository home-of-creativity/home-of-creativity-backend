import { useEffect, useState, type FormEvent } from "react";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PortfolioCategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

const emptyCategoryForm = {
  slug: "",
  name_en: "",
  name_ar: "",
  is_published: true,
};

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
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [movingId, setMovingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyCategoryForm);

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

  function resetForm() {
    setEditingId(null);
    setForm(emptyCategoryForm);
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setForm(emptyCategoryForm);
    setError("");
    setShowForm(true);
  }

  function startEdit(item: PortfolioCategory) {
    setEditingId(item.id);
    setForm({
      slug: item.slug,
      name_en: item.name_en,
      name_ar: item.name_ar,
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
      slug: form.slug.trim(),
      name_en: form.name_en.trim(),
      name_ar: form.name_ar.trim(),
      is_published: form.is_published,
    };

    try {
      if (editingId) await api.updatePortfolioCategory(editingId, payload);
      else await api.createPortfolioCategory(payload);
      resetForm();
      setNotice(t(copy.savePortfolioCategory));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deletePortfolioCategory(id);
      if (editingId === id) resetForm();
      load();
      setNotice(t(copy.deleted));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function removeAll() {
    if (!window.confirm(t(copy.confirmDeleteAll))) return;
    setError("");
    try {
      await api.deleteAllPortfolioCategories();
      resetForm();
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
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.portfolioTabCategories)}</h1>
          <p className="page-lede">{t(copy.categoriesLede)}</p>
        </div>
      </header>

      <div className="toolbar">
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addPortfolioCategory)}
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={categories.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addPortfolioCategory)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={t(copy.savePortfolioCategory)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
        >
          <div className="portfolio-form-grid">
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
                    <button type="button" className="btn btn-ghost" onClick={() => startEdit(item)}>
                      {t(copy.edit)}
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={() => void remove(item.id)}>
                      {t(copy.delete)}
                    </button>
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
