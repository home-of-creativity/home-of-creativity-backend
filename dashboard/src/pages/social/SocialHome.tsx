import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, canSocial, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { DateTimeField } from "../../components/DateTimeField";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { fromLocalInput, platformLabel, socialStatusLabel } from "./helpers";
import { SocialChrome } from "./SocialChrome";
import { SocialPhonePreview } from "./SocialPhonePreview";
import { useSocialWorkspace } from "./SocialWorkspace";

const PAGE_SIZE = 100;

export function SocialHome({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { selectedAccount, activeAccounts, loading: accountsLoading, openPicker } = useSocialWorkspace();
  const [posts, setPosts] = useState<SocialPost[]>([]);
  const [body, setBody] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [scheduledAt, setScheduledAt] = useState("");
  const [scheduleMode, setScheduleMode] = useState<"draft" | "now" | "custom">("draft");
  const [feedFilter, setFeedFilter] = useState<"all" | "draft" | "scheduled">("all");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function refreshPosts(accountId: number) {
    const res = await api.socialPosts({ account_id: accountId, per_page: PAGE_SIZE });
    setPosts(res.data);
  }

  useEffect(() => {
    if (!selectedAccount) {
      setPosts([]);
      return;
    }
    let cancelled = false;
    api
      .socialPosts({ account_id: selectedAccount.id, per_page: PAGE_SIZE })
      .then((res) => {
        if (!cancelled) setPosts(res.data);
      })
      .catch(() => {
        if (!cancelled) setPosts([]);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedAccount?.id]);

  const previewFiles = useMemo(
    () => files.map((file) => ({ name: file.name, url: URL.createObjectURL(file), kind: file.type.startsWith("video/") ? "video" : "image" })),
    [files],
  );
  useEffect(() => () => previewFiles.forEach((file) => URL.revokeObjectURL(file.url)), [previewFiles]);

  const canPublish = canSocial(user, "approve");
  const canCreate = canSocial(user, "create");
  const listed = posts.filter((item) => {
    if (feedFilter === "draft") return item.status === "draft" || item.status === "failed";
    if (feedFilter === "scheduled") return item.status === "scheduled" || item.status === "publishing";
    return true;
  });

  function postActions(post: SocialPost): ReactNode {
    const showEdit = canCreate && post.is_editable !== false;
    const showDelete = canCreate && post.is_deletable !== false && post.status !== "publishing";
    if (!showEdit && !showDelete) return null;
    return (
      <div className="social-phone-post-toolbar">
        {showEdit ? (
          <Link className="btn btn-ghost" to={`/social/compose/${post.id}`}>
            {t(copy.edit)}
          </Link>
        ) : null}
        {showDelete ? (
          <ConfirmAction
            className="btn btn-ghost btn-danger"
            label={t(copy.delete)}
            confirmLabel={post.status === "published" ? t(copy.socialDeleteLive) : undefined}
            yesLabel={t(copy.delete)}
            noLabel={t(copy.cancel)}
            disabled={busyId === post.id}
            onConfirm={() => void removePost(post.id)}
          />
        ) : null}
      </div>
    );
  }

  async function removePost(id: number) {
    setBusyId(id);
    setError("");
    try {
      await api.deleteSocialPost(id);
      setPosts((current) => current.filter((item) => item.id !== id));
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.loading);
      setError(message);
      toast.error(message);
    } finally {
      setBusyId(null);
    }
  }

  async function submit(event: FormEvent, intent: "draft" | "schedule" | "publish") {
    event.preventDefault();
    if (!selectedAccount) {
      openPicker();
      return;
    }
    if (intent !== "draft" && !body.trim() && files.length === 0) {
      setError(t(copy.socialNeedCaptionOrMedia));
      return;
    }
    if (intent !== "draft" && selectedAccount.platform === "instagram" && files.length === 0) {
      setError(t(copy.socialInstagramNeedsMedia));
      return;
    }
    setSaving(true);
    setError("");
    try {
      const form = new FormData();
      form.set("body", body);
      form.set("placement", "feed");
      form.set("intent", intent);
      if (scheduledAt) form.set("scheduled_at", fromLocalInput(scheduledAt));
      form.append("account_ids[]", String(selectedAccount.id));
      files.forEach((file) => form.append("media[]", file));
      await api.createSocialPost(form);
      setBody("");
      setFiles([]);
      setScheduledAt("");
      setScheduleMode("draft");
      await refreshPosts(selectedAccount.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setSaving(false);
    }
  }

  function primaryIntent(): "draft" | "schedule" | "publish" {
    if (scheduleMode === "now") return "publish";
    if (scheduleMode === "custom") return "schedule";
    return "draft";
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialHome} lede={copy.socialHomeLede} immersive>
      {accountsLoading ? <p className="muted">{t(copy.loading)}</p> : null}
      {!accountsLoading && activeAccounts.length === 0 ? (
        <p className="muted">
          {t(copy.socialNoAccountsYet)}{" "}
          <Link to="/social/accounts">{t(copy.socialAccounts)}</Link>
        </p>
      ) : null}
      {error ? <p className="error">{error}</p> : null}
      {selectedAccount ? (
        <form className="studio-board" onSubmit={(event) => void submit(event, primaryIntent())}>
          <div className="studio-phone-col">
            <SocialPhonePreview
              locale={locale}
              t={t}
              studio
              accounts={[selectedAccount]}
              placement="feed"
              body={body}
              existingMedia={[]}
              files={previewFiles}
              posts={posts}
              onFiles={canCreate ? setFiles : undefined}
              dropDisabled={!canCreate}
              feedFilter={feedFilter}
              onFeedFilter={setFeedFilter}
              renderPostActions={postActions}
            />
          </div>
          <aside className="studio-rail">
            <section className="card studio-account-card">
              <div className="studio-account-current">
                <span className={`studio-chip-icon is-${selectedAccount.platform}`}>
                  <SocialBrandIcon platform={selectedAccount.platform} />
                </span>
                <span>
                  <p className="eyebrow">{t(copy.socialWorkingOn)}</p>
                  <strong>{selectedAccount.name}</strong>
                  <small>
                    {platformLabel(selectedAccount.platform, t)}
                    {selectedAccount.handle ? ` · @${selectedAccount.handle.replace(/^@/, "")}` : ""}
                  </small>
                </span>
              </div>
              <button type="button" className="btn btn-primary" onClick={openPicker}>
                {t(copy.socialChangeAccount)}
              </button>
            </section>
            {canCreate ? (
              <section className="card stack">
                <h2 className="section-title">{t(copy.socialCreatePost)}</h2>
                <label className="field-label">
                  {t(copy.socialCaptionOptional)}
                  <textarea
                    className="field"
                    rows={4}
                    value={body}
                    placeholder={t(copy.socialCaptionHint)}
                    onChange={(event) => setBody(event.target.value)}
                  />
                </label>
                <fieldset className="plann-schedule">
                  <legend>{t(copy.socialSchedule)}</legend>
                  <label className="plann-schedule-row">
                    <input type="radio" name="home-schedule" checked={scheduleMode === "draft"} onChange={() => setScheduleMode("draft")} />
                    {t(copy.socialScheduleDraftMode)}
                  </label>
                  <label className="plann-schedule-row">
                    <input type="radio" name="home-schedule" checked={scheduleMode === "now"} disabled={!canPublish} onChange={() => setScheduleMode("now")} />
                    {t(copy.socialScheduleNowMode)}
                  </label>
                  <label className="plann-schedule-row">
                    <input type="radio" name="home-schedule" checked={scheduleMode === "custom"} onChange={() => setScheduleMode("custom")} />
                    {t(copy.socialScheduleCustomMode)}
                  </label>
                  {scheduleMode === "custom" ? (
                    <DateTimeField value={scheduledAt} onChange={setScheduledAt} locale={locale} placeholder={t(copy.socialScheduleAt)} />
                  ) : null}
                </fieldset>
                <button type="submit" className="btn btn-primary" disabled={saving || (scheduleMode === "now" && !canPublish)}>
                  {scheduleMode === "now" ? t(copy.socialPublishNow) : scheduleMode === "custom" ? t(copy.socialSchedule) : t(copy.socialSaveDraft)}
                </button>
              </section>
            ) : null}
            <section className="card stack">
              <header className="studio-rail-head">
                <h2 className="section-title">{t(copy.socialAccountPosts)}</h2>
                <span className="muted">{t(copy.socialPostsCount).replace("{count}", String(listed.length))}</span>
              </header>
              {listed.length === 0 ? <p className="muted">{t(copy.socialEmptyFeed)}</p> : null}
              <ul className="studio-post-list">
                {listed.map((post) => {
                  const cover = post.media?.[0];
                  return (
                    <li key={post.id} className="studio-post-row">
                      <span className="studio-post-thumb">
                        {cover?.kind === "video" ? (
                          <video src={cover.url ?? undefined} muted playsInline />
                        ) : cover?.url ? (
                          <img src={cover.url} alt="" />
                        ) : (
                          <SocialBrandIcon platform={selectedAccount.platform} />
                        )}
                      </span>
                      <div>
                        <p>{post.body.trim() || t(copy.socialUntitledPost)}</p>
                        <small>{socialStatusLabel(post.status, t)}</small>
                      </div>
                      <div className="studio-post-row-actions">
                        {canCreate && post.is_editable !== false ? (
                          <Link className="btn btn-ghost" to={`/social/compose/${post.id}`}>
                            {t(copy.edit)}
                          </Link>
                        ) : null}
                        {canCreate && post.is_deletable !== false && post.status !== "publishing" ? (
                          <ConfirmAction
                            className="btn btn-ghost btn-danger"
                            label={t(copy.delete)}
                            confirmLabel={post.status === "published" ? t(copy.socialDeleteLive) : undefined}
                            yesLabel={t(copy.delete)}
                            noLabel={t(copy.cancel)}
                            disabled={busyId === post.id}
                            onConfirm={() => void removePost(post.id)}
                          />
                        ) : null}
                      </div>
                    </li>
                  );
                })}
              </ul>
            </section>
          </aside>
        </form>
      ) : null}
    </SocialChrome>
  );
}
