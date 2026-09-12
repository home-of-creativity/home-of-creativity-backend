import { useEffect, useState, type FormEvent } from "react";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PageMeta, type PortfolioCategory, type PortfolioProject } from "../../api";
import { Pagination } from "../../components/Pagination";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

const emptySocialForm = {
  instagram: "",
  facebook: "",
  linkedin: "",
  x: "",
  tiktok: "",
  youtube: "",
};

const emptyProjectForm = {
  category_id: "",
  title_en: "",
  title_ar: "",
  summary_en: "",
  summary_ar: "",
  website_url: "",
  social: emptySocialForm,
  sort_order: "",
  is_published: true,
  featured: false,
};

export function PortfolioProjects({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [projects, setProjects] = useState<PortfolioProject[]>([]);
  const [categories, setCategories] = useState<PortfolioCategory[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyProjectForm);
  const [image, setImage] = useState<File | null>(null);
  const [currentImageUrl, setCurrentImageUrl] = useState<string | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [galleryFiles, setGalleryFiles] = useState<File[]>([]);
  const [existingGallery, setExistingGallery] = useState<NonNullable<PortfolioProject["images"]>>([]);
  const [removeGalleryIds, setRemoveGalleryIds] = useState<number[]>([]);

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

  function resetForm() {
    setEditingId(null);
    setForm(emptyProjectForm);
    setImage(null);
    setCurrentImageUrl(null);
    setImagePreview(null);
    setGalleryFiles([]);
    setExistingGallery([]);
    setRemoveGalleryIds([]);
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setForm(emptyProjectForm);
    setImage(null);
    setCurrentImageUrl(null);
    setImagePreview(null);
    setGalleryFiles([]);
    setExistingGallery([]);
    setRemoveGalleryIds([]);
    setError("");
    setShowForm(true);
  }

  function startEdit(item: PortfolioProject) {
    setEditingId(item.id);
    setForm({
      category_id: String(item.category_id),
      title_en: item.title_en,
      title_ar: item.title_ar,
      summary_en: item.summary_en ?? "",
      summary_ar: item.summary_ar ?? "",
      website_url: item.website_url ?? "",
      social: {
        instagram: item.social_links?.instagram ?? "",
        facebook: item.social_links?.facebook ?? "",
        linkedin: item.social_links?.linkedin ?? "",
        x: item.social_links?.x ?? "",
        tiktok: item.social_links?.tiktok ?? "",
        youtube: item.social_links?.youtube ?? "",
      },
      sort_order: String(item.sort_order),
      is_published: item.is_published,
      featured: item.featured,
    });
    setImage(null);
    setCurrentImageUrl(item.image_url);
    setImagePreview(null);
    setGalleryFiles([]);
    setExistingGallery(item.images ?? []);
    setRemoveGalleryIds([]);
    setShowForm(true);
    setError("");
    setNotice("");
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    const payload = new FormData();
    payload.set("category_id", form.category_id);
    payload.set("title_en", form.title_en.trim());
    payload.set("title_ar", form.title_ar.trim());
    payload.set("summary_en", form.summary_en.trim());
    payload.set("summary_ar", form.summary_ar.trim());
    payload.set("website_url", form.website_url.trim());
    for (const [platform, value] of Object.entries(form.social)) {
      payload.set(`social_links[${platform}]`, value.trim());
    }
    payload.set("sort_order", form.sort_order.trim() || "0");
    payload.set("is_published", form.is_published ? "1" : "0");
    payload.set("featured", form.featured ? "1" : "0");
    if (image) payload.set("image", image);
    for (const file of galleryFiles) {
      payload.append("gallery[]", file);
    }
    for (const id of removeGalleryIds) {
      payload.append("remove_gallery_ids[]", String(id));
    }

    try {
      if (editingId) await api.updatePortfolioProject(editingId, payload);
      else await api.createPortfolioProject(payload);
      resetForm();
      setNotice(t(copy.savePortfolioProject));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deletePortfolioProject(id);
      if (editingId === id) resetForm();
      setNotice(t(copy.deleted));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function removeAll() {
    if (!window.confirm(t(copy.confirmDeleteAll))) return;
    setError("");
    try {
      await api.deleteAllPortfolioProjects();
      resetForm();
      setPage(1);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  return (
    <>
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.portfolioTabProjects)}</h1>
          <p className="page-lede">{t(copy.projectsLede)}</p>
        </div>
      </header>

      <div className="toolbar">
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addPortfolioProject)}
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={projects.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addPortfolioProject)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={t(copy.savePortfolioProject)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
          size="lg"
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.category)}
              <select className="field" value={form.category_id} onChange={(e) => setForm((prev) => ({ ...prev, category_id: e.target.value }))} required>
                <option value="">{t(copy.category)}</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {categoryLabel(category, locale)}
                  </option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {t(copy.titleEn)}
              <input className="field" value={form.title_en} onChange={(e) => setForm((prev) => ({ ...prev, title_en: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.titleAr)}
              <input className="field" value={form.title_ar} onChange={(e) => setForm((prev) => ({ ...prev, title_ar: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.summaryEn)}
              <textarea className="field" rows={2} value={form.summary_en} onChange={(e) => setForm((prev) => ({ ...prev, summary_en: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.summaryAr)}
              <textarea className="field" rows={2} value={form.summary_ar} onChange={(e) => setForm((prev) => ({ ...prev, summary_ar: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.sortOrder)}
              <input className="field" type="number" min={0} value={form.sort_order} onChange={(e) => setForm((prev) => ({ ...prev, sort_order: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.website)}
              <input
                className="field"
                type="url"
                dir="ltr"
                value={form.website_url}
                onChange={(e) => setForm((prev) => ({ ...prev, website_url: e.target.value }))}
                placeholder="https://"
              />
            </label>
            <label className="field-label">
              {t(copy.coverImage)}
              <input
                className="field"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={(e) => {
                  const file = e.target.files?.[0] ?? null;
                  setImage(file);
                  setImagePreview(file ? URL.createObjectURL(file) : null);
                }}
              />
            </label>
            {(imagePreview ?? currentImageUrl) ? (
              <div className="form-media-preview">
                <img src={imagePreview ?? currentImageUrl ?? ""} alt="" className="form-media-preview-img project-thumb" />
                <p className="muted">{imagePreview ? t(copy.replaceImage) : t(copy.currentImage)}</p>
              </div>
            ) : null}
            <div className="field-label portfolio-form-span">
              <span>{t(copy.socialLinks)}</span>
              <div className="portfolio-form-grid portfolio-form-grid--social">
                <input className="field" type="url" dir="ltr" placeholder={t(copy.instagram)} value={form.social.instagram} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, instagram: e.target.value } }))} />
                <input className="field" type="url" dir="ltr" placeholder={t(copy.facebook)} value={form.social.facebook} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, facebook: e.target.value } }))} />
                <input className="field" type="url" dir="ltr" placeholder={t(copy.linkedin)} value={form.social.linkedin} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, linkedin: e.target.value } }))} />
                <input className="field" type="url" dir="ltr" placeholder={t(copy.xTwitter)} value={form.social.x} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, x: e.target.value } }))} />
                <input className="field" type="url" dir="ltr" placeholder={t(copy.tiktok)} value={form.social.tiktok} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, tiktok: e.target.value } }))} />
                <input className="field" type="url" dir="ltr" placeholder={t(copy.youtube)} value={form.social.youtube} onChange={(e) => setForm((prev) => ({ ...prev, social: { ...prev.social, youtube: e.target.value } }))} />
              </div>
            </div>
            <label className="field-label portfolio-form-span">
              {t(copy.projectGallery)}
              <input
                className="field"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                multiple
                onChange={(e) => setGalleryFiles(Array.from(e.target.files ?? []))}
              />
            </label>
            {existingGallery.length > 0 ? (
              <div className="gallery-preview-grid portfolio-form-span">
                {existingGallery.map((item) => {
                  const marked = removeGalleryIds.includes(item.id);
                  return (
                    <label key={item.id} className={marked ? "gallery-preview-item is-marked" : "gallery-preview-item"}>
                      <img src={item.image_url ?? ""} alt="" referrerPolicy="no-referrer" className="gallery-preview-thumb" />
                      <input
                        type="checkbox"
                        checked={marked}
                        onChange={() =>
                          setRemoveGalleryIds((prev) =>
                            prev.includes(item.id) ? prev.filter((id) => id !== item.id) : [...prev, item.id],
                          )
                        }
                      />
                      <span>{t(copy.removeGalleryImage)}</span>
                    </label>
                  );
                })}
              </div>
            ) : null}
            {galleryFiles.length > 0 ? (
              <p className="muted portfolio-form-span">
                {t(copy.addGalleryImages)}: {galleryFiles.length}
              </p>
            ) : null}
            <label className="checkbox-row">
              <input type="checkbox" checked={form.is_published} onChange={(e) => setForm((prev) => ({ ...prev, is_published: e.target.checked }))} />
              {t(copy.published)}
            </label>
            <label className="checkbox-row">
              <input type="checkbox" checked={form.featured} onChange={(e) => setForm((prev) => ({ ...prev, featured: e.target.checked }))} />
              {t(copy.featured)}
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

      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </>
  );
}
