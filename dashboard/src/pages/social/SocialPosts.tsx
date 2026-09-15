import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, canSocial, type PageMeta, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { LoadingTableRow } from "../../components/LoadingTableRow";
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
    const item = items.find((post) => post.id === id);
    if (!window.confirm(item?.status === "published" ? t(copy.socialDeleteLive) : t(copy.delete))) return;
    setBusyId(id);
    try {
      await api.deleteSocialPost(id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
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
      <div className="table-wrap card">
        <table className="table-flush">
          <thead>
            <tr>
              <th>{t(copy.socialBody)}</th>
              <th>{t(copy.status)}</th>
              <th>{t(copy.socialPickAccounts)}</th>
              <th>{t(copy.socialCreatedBy)}</th>
              <th>{t(copy.socialApprovedBy)}</th>
              <th>{t(copy.socialPublishedAt)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? <LoadingTableRow colSpan={7} label={t(copy.loading)} /> : null}
            {!loading && items.length === 0 ? (
              <tr>
                <td colSpan={7}>{t(copy.socialNoPosts)}</td>
              </tr>
            ) : null}
            {items.map((item) => {
              const canApprove = item.status === "draft" || item.status === "failed";
              const canPublishNow = item.status === "draft" || item.status === "scheduled" || item.status === "failed";
              const canDelete = item.is_deletable !== false && item.status !== "publishing";
              const cover = item.media?.[0];
              return (
                <tr key={item.id}>
                  <td>
                    <Link className="table-link social-post-cell" to={`/social/compose/${item.id}`}>
                      {cover ? (
                        cover.kind === "video" ? (
                          <video className="social-post-thumb" src={cover.url ?? undefined} muted />
                        ) : (
                          <img className="social-post-thumb" src={cover.url ?? ""} alt={cover.original_name} />
                        )
                      ) : (
                        <span className="social-post-thumb social-post-thumb-empty" aria-hidden="true" />
                      )}
                      <span>
                        {item.body.slice(0, 80) || "—"}
                        <small className="muted"> · {socialPlacementLabel(item.placement, t)}</small>
                      </span>
                    </Link>
                  </td>
                  <td>
                    <span className={`status status-${item.status}`}>{socialStatusLabel(item.status, t)}</span>
                    {item.last_error ? (
                      <p className="muted" title={item.last_error}>
                        {publishErrorMessage(item.last_error, t)}
                      </p>
                    ) : null}
                  </td>
                  <td>{item.accounts?.map((account) => account.name).join(" · ") || "—"}</td>
                  <td>{item.created_by?.name ?? "—"}</td>
                  <td>{item.approved_by?.name ?? "—"}</td>
                  <td>{formatWhen(item.published_at ?? item.scheduled_at, locale)}</td>
                  <td className="row-actions">
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
                      <button
                        type="button"
                        className="btn btn-ghost"
                        disabled={busyId === item.id}
                        onClick={() => void remove(item.id)}
                      >
                        {t(copy.delete)}
                      </button>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </SocialChrome>
  );
}
