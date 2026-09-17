import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, canSocial, type PageMeta, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { Pagination } from "../../components/Pagination";
import { copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { formatWhen, publishErrorMessage, socialPlacementLabel, socialStatusLabel } from "./helpers";

export function SocialPosts({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [items, setItems] = useState<SocialPost[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);

  function load(silent = false) {
    if (!silent) setLoading(true);
    api
      .socialPosts({ status: status || undefined, page })
      .then((res) => {
        setItems(res.data);
        setMeta(res.meta);
        setError("");
      })
      .catch((err) => {
        if (!silent) {
          setItems([]);
          setMeta(null);
        }
        setError(err instanceof Error ? err.message : t(copy.loading));
      })
      .finally(() => {
        if (!silent) setLoading(false);
      });
  }

  useEffect(() => {
    load();
    const timer = window.setInterval(() => load(true), 15000);
    return () => window.clearInterval(timer);
  }, [status, page]);

  async function approve(id: number) {
    setBusyId(id);
    try {
      await api.approveSocialPost(id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setBusyId(null);
    }
  }

  async function publish(id: number) {
    setBusyId(id);
    try {
      await api.publishSocialPost(id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setBusyId(null);
    }
  }

  async function remove(id: number) {
    setBusyId(id);
    try {
      await api.deleteSocialPost(id);
      load();
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.loading);
      setError(message);
      toast.error(message);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialPosts} lede={copy.socialPostsLede}>
      <div className="toolbar filter-bar">
        <label className="filter-label" htmlFor="social-status-filter">
          {t(copy.status)}
        </label>
        <select
          id="social-status-filter"
          className="field"
          value={status}
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
        >
          <option value="">{t(copy.viewAll)}</option>
          {["draft", "scheduled", "publishing", "published", "failed"].map((value) => (
            <option key={value} value={value}>
              {socialStatusLabel(value, t)}
            </option>
          ))}
        </select>
        {canSocial(user, "create") ? (
          <Link className="btn btn-primary" to="/social/compose">
            {t(copy.socialCompose)}
          </Link>
        ) : null}
      </div>
      {error ? <p className="error">{error}</p> : null}
      {loading ? <p className="muted">{t(copy.loading)}</p> : null}
      {!loading && items.length === 0 ? <p className="card social-inbox-empty">{t(copy.socialNoPosts)}</p> : null}
      <div className="social-card-list">
        {items.map((item) => {
          const canApprove = item.status === "draft" || item.status === "failed";
          const canPublishNow = item.status === "draft" || item.status === "scheduled" || item.status === "failed";
          const canDelete = item.is_deletable !== false && item.status !== "publishing";
          const cover = item.media?.[0];
          return (
            <article key={item.id} className="social-card">
              {cover ? (
                cover.kind === "video" ? (
                  <video className="social-card-thumb" src={cover.url ?? undefined} muted />
                ) : (
                  <img className="social-card-thumb" src={cover.url ?? ""} alt={cover.original_name} />
                )
              ) : (
                <span className="social-card-thumb social-post-thumb-empty" aria-hidden="true" />
              )}
              <div className="social-card-body">
                <Link className="social-card-title" to={`/social/compose/${item.id}`}>
                  {item.body.slice(0, 100) || "—"}
                </Link>
                <p className="social-card-meta">
                  <span className={`status status-${item.status}`}>{socialStatusLabel(item.status, t)}</span>
                  <span className="muted"> · {socialPlacementLabel(item.placement, t)}</span>
                  <span className="muted"> · {item.accounts?.map((account) => account.name).join(" + ") || "—"}</span>
                </p>
                <p className="social-card-meta muted">
                  {t(copy.socialCreatedBy)}: {item.created_by?.name ?? "—"} · {t(copy.socialApprovedBy)}: {item.approved_by?.name ?? "—"} ·{" "}
                  {formatWhen(item.published_at ?? item.scheduled_at, locale)}
                </p>
                {item.last_error ? (
                  <p className="muted" title={item.last_error}>
                    {publishErrorMessage(item.last_error, t)}
                  </p>
                ) : null}
              </div>
              <div className="social-card-actions">
                {canSocial(user, "approve") && canApprove ? (
                  <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void approve(item.id)}>
                    {t(copy.socialApprove)}
                  </button>
                ) : null}
                {canSocial(user, "approve") && canPublishNow ? (
                  <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void publish(item.id)}>
                    {t(copy.socialPublishNow)}
                  </button>
                ) : null}
                {canSocial(user, "create") && canDelete ? (
                  <ConfirmAction
                    label={t(copy.delete)}
                    confirmLabel={item.status === "published" ? t(copy.socialDeleteLive) : undefined}
                    yesLabel={t(copy.delete)}
                    noLabel={t(copy.cancel)}
                    disabled={busyId === item.id}
                    onConfirm={() => void remove(item.id)}
                  />
                ) : null}
              </div>
            </article>
          );
        })}
      </div>
      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </SocialChrome>
  );
}
