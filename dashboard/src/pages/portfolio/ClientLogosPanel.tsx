import { useEffect, useMemo, useState, type FormEvent } from "react";
import { FormDialog } from "../../components/FormDialog";
import { LoadingLottie } from "../../components/LoadingLottie";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PageMeta, type ShowcaseClient } from "../../api";
import { Pagination } from "../../components/Pagination";
import { copy, type Locale } from "../../i18n";

const emptyClientForm = {
  name: "",
  website_url: "",
  sort_order: "",
  is_published: true,
};

export function ClientLogosPanel({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [clients, setClients] = useState<ShowcaseClient[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyClientForm);
  const [logo, setLogo] = useState<File | null>(null);
  const [currentLogoUrl, setCurrentLogoUrl] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);

  const publishedClients = useMemo(() => clients.filter((item) => item.is_published), [clients]);

  function load() {
    setLoading(true);
    api
      .showcaseClients(page)
      .then((res) => {
        setClients(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setClients([]);
        setMeta(null);
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, [page]);

  function resetForm() {
    setEditingId(null);
    setForm(emptyClientForm);
    setLogo(null);
    setCurrentLogoUrl(null);
    setLogoPreview(null);
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setForm(emptyClientForm);
    setLogo(null);
    setCurrentLogoUrl(null);
    setLogoPreview(null);
    setError("");
    setShowForm(true);
  }

  function startEdit(item: ShowcaseClient) {
    setEditingId(item.id);
    setForm({
      name: item.name,
      website_url: item.website_url ?? "",
      sort_order: String(item.sort_order),
      is_published: item.is_published,
    });
    setLogo(null);
    setCurrentLogoUrl(item.logo_url);
    setLogoPreview(null);
    setShowForm(true);
    setError("");
    setNotice("");
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    const payload = new FormData();
    payload.set("name", form.name.trim());
    payload.set("website_url", form.website_url.trim());
    payload.set("sort_order", form.sort_order.trim() || "0");
    payload.set("is_published", form.is_published ? "1" : "0");
    if (logo) payload.set("logo", logo);

    try {
      if (editingId) await api.updateShowcaseClient(editingId, payload);
      else await api.createShowcaseClient(payload);
      resetForm();
      setNotice(t(copy.saveShowcaseClient));
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deleteShowcaseClient(id);
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
      await api.deleteAllShowcaseClients();
      resetForm();
      setPage(1);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  return (
    <>
      <div className="toolbar">
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {t(copy.addShowcaseClient)}
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={clients.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      <section className="card logo-showcase-card">
        <div className="logo-showcase-head">
          <h2 className="section-title">{t(copy.logoPreview)}</h2>
          <p className="muted">
            {publishedClients.length} {t(copy.clientsCount)}
          </p>
        </div>
        <div className="logo-showcase-grid" aria-label={t(copy.logoPreview)}>
          {loading ? (
            <LoadingLottie label={t(copy.loading)} />
          ) : publishedClients.length === 0 ? (
            <p className="muted">{t(copy.empty)}</p>
          ) : (
            publishedClients.map((item) => (
              <div key={item.id} className="logo-showcase-item" aria-label={item.name}>
                {item.logo_url ? (
                  <img src={item.logo_url} alt={item.name} loading="lazy" />
                ) : (
                  <span className="logo-showcase-fallback">{item.name.slice(0, 2).toUpperCase()}</span>
                )}
              </div>
            ))
          )}
        </div>
      </section>

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.addShowcaseClient)}
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={t(copy.saveShowcaseClient)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.client)}
              <input className="field" value={form.name} onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))} required />
            </label>
            <label className="field-label">
              {t(copy.website)}
              <input
                className="field"
                type="url"
                value={form.website_url}
                onChange={(e) => setForm((prev) => ({ ...prev, website_url: e.target.value }))}
                placeholder="https://"
              />
            </label>
            <label className="field-label">
              {t(copy.sortOrder)}
              <input
                className="field"
                type="number"
                min={0}
                value={form.sort_order}
                onChange={(e) => setForm((prev) => ({ ...prev, sort_order: e.target.value }))}
              />
            </label>
            <label className="field-label">
              {t(copy.logoFile)}
              <input
                className="field"
                type="file"
                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                onChange={(e) => {
                  const file = e.target.files?.[0] ?? null;
                  setLogo(file);
                  setLogoPreview(file ? URL.createObjectURL(file) : null);
                }}
              />
            </label>
            {(logoPreview ?? currentLogoUrl) ? (
              <div className="form-media-preview">
                <img src={logoPreview ?? currentLogoUrl ?? ""} alt="" className="form-media-preview-img" />
                <p className="muted">{logoPreview ? t(copy.replaceLogo) : t(copy.currentLogo)}</p>
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
              <th>{t(copy.client)}</th>
              <th>{t(copy.website)}</th>
              <th>{t(copy.sortOrder)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={5} label={t(copy.loading)} />
            ) : clients.length === 0 ? (
              <tr>
                <td colSpan={5}>{t(copy.empty)}</td>
              </tr>
            ) : (
              clients.map((item) => (
                <tr key={item.id}>
                  <td>
                    <div className="logo-row">
                      {item.logo_url ? <img src={item.logo_url} alt="" className="logo-row-thumb" /> : <span className="logo-row-fallback">{t(copy.noLogo)}</span>}
                      <span>{item.name}</span>
                    </div>
                  </td>
                  <td>{item.website_url ? <a href={item.website_url} target="_blank" rel="noreferrer">{item.website_url}</a> : "—"}</td>
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
