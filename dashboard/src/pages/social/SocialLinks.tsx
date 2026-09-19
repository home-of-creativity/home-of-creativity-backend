import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, canSocial, type PageMeta, type SocialLinktreeProfile, type SocialPost, type User } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { Pagination } from "../../components/Pagination";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { LinktreePhone, type LinktreeTheme } from "./LinktreePhone";
import { SocialChrome } from "./SocialChrome";
import { useSocialWorkspace } from "./SocialWorkspace";
import {
  publishErrorMessage,
  socialPlacementLabel,
  socialStatusLabel,
} from "./helpers";

function linkTitle(item: SocialPost, t: (c: { ar: string; en: string }) => string) {
  return (item.body.split("\n")[0]?.trim() || socialPlacementLabel(item.placement ?? "feed", t)).slice(0, 72);
}

function LinkActions({
  item,
  user,
  busy,
  t,
  onApprove,
  onPublish,
  onRemove,
}: {
  item: SocialPost;
  user: User | null;
  busy: boolean;
  t: (c: { ar: string; en: string }) => string;
  onApprove: () => void;
  onPublish: () => void;
  onRemove: () => void;
}) {
  const canApprove = item.status === "draft" || item.status === "failed";
  const canPublishNow = item.can_publish !== false && item.status !== "published";
  const canDelete = item.is_deletable !== false;
  const showApprove = canSocial(user, "approve") && canApprove;
  const showPublish = canSocial(user, "approve") && canPublishNow;
  const showDelete = canSocial(user, "create") && canDelete;
  const showEdit = canSocial(user, "create");
  if (!showApprove && !showPublish && !showDelete && !showEdit) return null;

  return (
    <details className="linktree-more">
      <summary aria-label={t(copy.socialLinkActions)}>
        <span aria-hidden className={item.last_error ? "is-error" : undefined}>
          ···
        </span>
      </summary>
      <div className="linktree-menu">
        <p className="linktree-menu-meta">
          {socialPlacementLabel(item.placement ?? "feed", t)} · {socialStatusLabel(item.status, t)}
        </p>
        {showEdit ? (
          <Link className="linktree-menu-item" to={`/social/compose/${item.id}`}>
            {t(copy.edit)}
          </Link>
        ) : null}
        {showApprove ? (
          <button type="button" className="linktree-menu-item" disabled={busy} onClick={onApprove}>
            {t(copy.socialApprove)}
          </button>
        ) : null}
        {showPublish ? (
          <button type="button" className="linktree-menu-item" disabled={busy} onClick={onPublish}>
            {item.status === "publishing" || item.status === "failed" ? t(copy.socialRetryPublish) : t(copy.socialPublishNow)}
          </button>
        ) : null}
        {showDelete ? (
          <ConfirmAction
            className="linktree-menu-item is-danger"
            label={t(copy.delete)}
            confirmLabel={item.status === "published" ? t(copy.socialDeleteLive) : undefined}
            yesLabel={t(copy.delete)}
            noLabel={t(copy.cancel)}
            disabled={busy}
            onConfirm={onRemove}
          />
        ) : null}
        {item.last_error ? <p className="linktree-error">{publishErrorMessage(item.last_error, t)}</p> : null}
      </div>
    </details>
  );
}

export function SocialLinks({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { selectedAccount } = useSocialWorkspace();
  const [items, setItems] = useState<SocialPost[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const [profile, setProfile] = useState<SocialLinktreeProfile | null>(null);
  const [listTab, setListTab] = useState<"links" | "stories">("links");
  const logoSrc = `${import.meta.env.BASE_URL}hummingbird.svg`;

  const stories = items.filter((item) => item.placement === "story");
  const links = items.filter((item) => item.placement !== "story");

  function load(silent = false) {
    if (!selectedAccount) {
      setItems([]);
      setMeta(null);
      if (!silent) setLoading(false);
      return;
    }
    if (!silent) setLoading(true);
    Promise.all([
      api.socialPosts({ status: status || undefined, page, per_page: 30, account_id: selectedAccount.id }),
      profile ? Promise.resolve(null) : api.socialProfile().catch(() => null),
    ])
      .then(([posts, profileRes]) => {
        setItems(posts.data);
        setMeta(posts.meta);
        if (profileRes) setProfile(profileRes.data);
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
  }, [status, page, selectedAccount?.id]);

  async function approve(id: number) {
    setBusyId(id);
    try {
      await api.approveSocialPost(id);
      load(true);
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
      load(true);
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
      load(true);
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.loading);
      setError(message);
      toast.error(message);
    } finally {
      setBusyId(null);
    }
  }

  const handle = selectedAccount?.handle;
  const displayName = profile?.display_name || selectedAccount?.name || "Home of Creativity";
  const bio = profile?.bio || t(copy.socialLinktreeBio);
  const theme = (profile?.theme ?? "cream") as LinktreeTheme;

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialContent} lede={copy.socialLinksLede}>
      <div className="lt-admin">
        <section className="lt-admin-list">
          <div className="lt-admin-tabs" role="tablist" aria-label={t(copy.socialContent)}>
            <button type="button" role="tab" aria-selected={listTab === "links"} className={listTab === "links" ? "is-on" : undefined} onClick={() => setListTab("links")}>
              {t(copy.socialBioLinks)}
            </button>
            <button type="button" role="tab" aria-selected={listTab === "stories"} className={listTab === "stories" ? "is-on" : undefined} onClick={() => setListTab("stories")}>
              {t(copy.socialHighlights)}
            </button>
          </div>
          <div className="lt-admin-tools">
            <label className="filter-label" htmlFor="social-status-filter">
              {t(copy.status)}
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
            </label>
            {canSocial(user, "create") ? (
              <Link className="btn btn-primary" to="/social/compose">
                + {t(copy.socialAddLink)}
              </Link>
            ) : null}
          </div>
          {error ? <p className="error">{error}</p> : null}
          {loading ? <p className="muted">{t(copy.loading)}</p> : null}
          {!loading && (listTab === "stories" ? stories : links).length === 0 ? <p className="muted">{t(copy.socialNoPosts)}</p> : null}
          <div className="lt-admin-links">
            {(listTab === "stories" ? stories : links).map((item) => {
              const cover = item.media?.[0];
              return (
                <div key={item.id} className="linktree-link">
                  <Link className="linktree-link-hit is-editor" to={`/social/compose/${item.id}`}>
                    <span className="linktree-thumb">
                      {cover?.kind === "video" ? <video src={cover.url ?? undefined} muted playsInline /> : cover?.url ? <img src={cover.url} alt="" /> : <SocialBrandIcon platform={item.accounts?.[0]?.platform ?? "instagram"} />}
                    </span>
                    <span className="linktree-title">{linkTitle(item, t)}</span>
                  </Link>
                  <LinkActions
                    item={item}
                    user={user}
                    busy={busyId === item.id}
                    t={t}
                    onApprove={() => void approve(item.id)}
                    onPublish={() => void publish(item.id)}
                    onRemove={() => void remove(item.id)}
                  />
                </div>
              );
            })}
          </div>
          <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
        </section>
        <aside className="lt-admin-preview" aria-label={t(copy.socialPreview)}>
          <LinktreePhone
            t={t}
            dir={locale === "ar" ? "rtl" : "ltr"}
            theme={theme}
            name={displayName}
            handle={handle}
            bio={bio}
            accounts={selectedAccount ? [selectedAccount] : []}
            stories={stories}
            links={links}
            logoSrc={logoSrc}
          />
        </aside>
      </div>
    </SocialChrome>
  );
}

export { SocialLinks as SocialPosts };
