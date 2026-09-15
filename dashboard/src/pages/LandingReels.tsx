import { useEffect, useState, type FormEvent } from "react";
import { FormDialog } from "../components/FormDialog";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { Pagination } from "../components/Pagination";
import { api, type LandingReel, type PageMeta } from "../api";
import { copy, type Locale } from "../i18n";

const emptyForm = {
  title_en: "",
  title_ar: "",
  sort_order: "",
  is_published: true,
};

export function LandingReels({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [reels, setReels] = useState<LandingReel[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [video, setVideo] = useState<File | null>(null);
  const [poster, setPoster] = useState<File | null>(null);
  const [currentVideoUrl, setCurrentVideoUrl] = useState<string | null>(null);
  const [currentPosterUrl, setCurrentPosterUrl] = useState<string | null>(null);
  const [videoPreview, setVideoPreview] = useState<string | null>(null);
  const [posterPreview, setPosterPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const maxVideoBytes = 512 * 1024 * 1024;

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

  function resetForm() {
    setEditingId(null);
    setForm(emptyForm);
    setVideo(null);
    setPoster(null);
    setCurrentVideoUrl(null);
    setCurrentPosterUrl(null);
    setVideoPreview(null);
    setPosterPreview(null);
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    resetForm();
    setShowForm(true);
  }

  function startEdit(item: LandingReel) {
    setEditingId(item.id);
    setForm({
      title_en: item.title_en,
      title_ar: item.title_ar,
      sort_order: String(item.sort_order),
      is_published: item.is_published,
    });
    setVideo(null);
    setPoster(null);
    setCurrentVideoUrl(item.video_url);
    setCurrentPosterUrl(item.poster_url);
    setVideoPreview(null);
    setPosterPreview(null);
    setShowForm(true);
    setError("");
    setNotice("");
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    if (video && video.size > maxVideoBytes) {
      setError(t(copy.reelFileTooLarge));
      return;
    }
    const payload = new FormData();
    payload.set("title_en", form.title_en.trim());
    payload.set("title_ar", form.title_ar.trim());
    payload.set("sort_order", form.sort_order.trim() || "0");
    payload.set("is_published", form.is_published ? "1" : "0");
    if (video) payload.set("video", video);
    if (poster) payload.set("poster", poster);

    document.querySelectorAll("video").forEach((el) => {
      el.pause();
    });

    setSaving(true);
    try {
      if (editingId) await api.updateLandingReel(editingId, payload);
      else await api.createLandingReel(payload);
      resetForm();
      setNotice(t(copy.saveReel));
      load();
    } catch (err) {
      const message = err instanceof Error ? err.message : "";
      const network = err instanceof TypeError || /failed to fetch|networkerror|load failed/i.test(message);
      setError(network ? t(copy.reelSaveNetworkFailed) : message || t(copy.savePortfolioFailed));
    } finally {
      setSaving(false);
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deleteLandingReel(id);
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
      await api.deleteAllLandingReels();
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
          <h1 className="page-title">{t(copy.reelsTitle)}</h1>
          <p className="page-lede">{t(copy.reelsLede)}</p>
        </div>
      </header>

      <div className="toolbar">
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addReel)}
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={reels.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addReel)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={saving ? t(copy.reelSaving) : t(copy.saveReel)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
          busy={saving}
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.titleEn)}
              <input className="field" value={form.title_en} onChange={(e) => setForm((prev) => ({ ...prev, title_en: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.titleAr)}
              <input className="field" value={form.title_ar} onChange={(e) => setForm((prev) => ({ ...prev, title_ar: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.sortOrder)}
              <input className="field" type="number" min={0} value={form.sort_order} onChange={(e) => setForm((prev) => ({ ...prev, sort_order: e.target.value }))} />
            </label>
            <label className="field-label">
              {t(copy.reelVideo)}
              <input
                className="field"
                type="file"
                accept="video/mp4,video/webm,video/quicktime"
                required={editingId === null}
                onChange={(e) => {
                  const file = e.target.files?.[0] ?? null;
                  setVideo(file);
                  setVideoPreview(file ? URL.createObjectURL(file) : null);
                }}
              />
            </label>
            {(videoPreview ?? currentVideoUrl) ? (
              <div className="form-media-preview">
                <video src={videoPreview ?? currentVideoUrl ?? ""} className="form-media-preview-img project-thumb" controls muted playsInline preload="metadata" />
                <p className="muted">{videoPreview ? t(copy.replaceVideo) : t(copy.currentVideo)}</p>
              </div>
            ) : null}
            <label className="field-label">
              {t(copy.coverImage)}
              <input
                className="field"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={(e) => {
                  const file = e.target.files?.[0] ?? null;
                  setPoster(file);
                  setPosterPreview(file ? URL.createObjectURL(file) : null);
                }}
              />
            </label>
            {(posterPreview ?? currentPosterUrl) ? (
              <div className="form-media-preview">
                <img src={posterPreview ?? currentPosterUrl ?? ""} alt="" className="form-media-preview-img project-thumb" />
                <p className="muted">{posterPreview ? t(copy.replaceImage) : t(copy.currentImage)}</p>
              </div>
            ) : null}
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

export default LandingReels;
