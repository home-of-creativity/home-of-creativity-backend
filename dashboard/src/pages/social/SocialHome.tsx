import * as Popover from "@radix-ui/react-popover";
import { Check, Ellipsis, Pencil } from "lucide-react";
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, canSocial, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { DateTimeField } from "../../components/DateTimeField";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { startUploadToast } from "../../components/UploadToast";
import { copy, type Locale } from "../../i18n";
import { fromLocalInput, matchesSocialPlacement, orderedPageAccounts, platformLabel, publishErrorMessage } from "./helpers";
import { SocialChrome } from "./SocialChrome";
import { SocialPhonePreview, SocialPostCard } from "./SocialPhonePreview";
import { useSocialWorkspace } from "./SocialWorkspace";

const PAGE_SIZE = 100;

export function SocialHome({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { selectedAccount, selectedPage, activeAccounts, selectAccount, loading: accountsLoading, openPicker } = useSocialWorkspace();
  const [body, setBody] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [scheduledAt, setScheduledAt] = useState("");
  const [scheduleMode, setScheduleMode] = useState<"draft" | "now" | "custom">("draft");
  const [feedFilter, setFeedFilter] = useState<"all" | "draft" | "scheduled">("all");
  const [placement, setPlacement] = useState<"feed" | "story" | "reel">("feed");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [targetIds, setTargetIds] = useState<number[]>([]);
  const [postsByAccount, setPostsByAccount] = useState<Record<number, SocialPost[]>>({});

  const previewFiles = useMemo(
    () => files.map((file) => ({ name: file.name, url: URL.createObjectURL(file), kind: file.type.startsWith("video/") ? "video" : "image" })),
    [files],
  );
  useEffect(() => () => previewFiles.forEach((file) => URL.revokeObjectURL(file.url)), [previewFiles]);

  const canPublish = canSocial(user, "approve");
  const canCreate = canSocial(user, "create");
  const linkedNetworks = selectedPage
    ? orderedPageAccounts(selectedPage)
    : selectedAccount
      ? [selectedAccount]
      : [];
  const linkedIdKey = linkedNetworks.map((account) => account.id).join(",");

  async function refreshPosts() {
    const ids = linkedNetworks.map((account) => account.id);
    if (ids.length === 0) {
      setPostsByAccount({});
      return;
    }
    const entries = await Promise.all(
      ids.map((id) => api.socialPosts({ account_id: id, per_page: PAGE_SIZE }).then((res) => [id, res.data] as const)),
    );
    setPostsByAccount(Object.fromEntries(entries));
  }

  useEffect(() => {
    if (!linkedIdKey) {
      setPostsByAccount({});
      return;
    }
    let cancelled = false;
    const ids = linkedIdKey.split(",").map(Number);
    Promise.all(ids.map((id) => api.socialPosts({ account_id: id, per_page: PAGE_SIZE }).then((res) => [id, res.data] as const)))
      .then((entries) => {
        if (!cancelled) setPostsByAccount(Object.fromEntries(entries));
      })
      .catch(() => {
        if (!cancelled) setPostsByAccount({});
      });
    return () => {
      cancelled = true;
    };
  }, [linkedIdKey]);

  useEffect(() => {
    setTargetIds(
      linkedIdKey
        ? linkedIdKey.split(",").map(Number).filter((id) => Number.isFinite(id) && id > 0)
        : [],
    );
  }, [linkedIdKey]);

  const targets = linkedNetworks.filter((account) => targetIds.includes(account.id));
  const instagramOn = targets.some((account) => account.platform === "instagram");
  const posts = postsByAccount[selectedAccount?.id ?? 0] ?? [];
  const listed = posts.filter((item) => {
    if (feedFilter === "draft") return item.status === "draft" || item.status === "failed";
    if (feedFilter === "scheduled") return item.status === "scheduled" || item.status === "publishing";
    return true;
  }).filter((item) => matchesSocialPlacement(item, placement));

  function toggleNetwork(account: (typeof linkedNetworks)[number]) {
    const selected = targetIds.includes(account.id);
    if (!selected) {
      setTargetIds((current) => (current.includes(account.id) ? current : [...current, account.id]));
      selectAccount(account.id);
      return;
    }
    if (targetIds.length < 2) {
      selectAccount(account.id);
      return;
    }
    const next = targetIds.filter((id) => id !== account.id);
    setTargetIds(next);
    if (account.platform === "instagram" && placement === "reel") setPlacement("feed");
    if (account.id === selectedAccount?.id) {
      const fallback = linkedNetworks.find((item) => next.includes(item.id));
      if (fallback) selectAccount(fallback.id);
    }
  }

  function postActions(post: SocialPost): ReactNode {
    const showEdit = canCreate && post.is_editable !== false;
    const showDelete = canCreate && post.is_deletable !== false && post.status !== "publishing";
    if (!showEdit && !showDelete) return null;
    return (
      <Popover.Root>
        <Popover.Trigger asChild>
          <button type="button" className="social-phone-more" aria-label={t(copy.actions)} aria-haspopup="menu">
            <Ellipsis size={18} strokeWidth={2.2} aria-hidden="true" />
          </button>
        </Popover.Trigger>
        <Popover.Portal>
          <Popover.Content className="menu-popover" sideOffset={6} align="end" collisionPadding={12} role="menu">
            {showEdit ? (
              <Link className="menu-popover-item" role="menuitem" to={`/social/compose/${post.id}`}>
                <Pencil size={14} aria-hidden="true" />
                {t(copy.edit)}
              </Link>
            ) : null}
            {showDelete ? (
              <ConfirmAction
                className="menu-popover-item is-danger"
                label={t(copy.delete)}
                confirmLabel={post.status === "published" ? t(copy.socialDeleteLive) : undefined}
                yesLabel={t(copy.delete)}
                noLabel={t(copy.cancel)}
                disabled={busyId === post.id}
                onConfirm={() => void removePost(post.id)}
              />
            ) : null}
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>
    );
  }

  async function removePost(id: number) {
    setBusyId(id);
    setError("");
    try {
      await api.deleteSocialPost(id);
      setPostsByAccount((current) => {
        const next: Record<number, SocialPost[]> = {};
        for (const [key, rows] of Object.entries(current)) {
          next[Number(key)] = rows.filter((item) => item.id !== id);
        }
        return next;
      });
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
    if (intent !== "draft" && placement === "story" && files.length === 0) {
      setError(t(copy.socialStoryNeedsMedia));
      return;
    }
    if (intent !== "draft" && placement === "reel" && !files.some((file) => file.type.startsWith("video/"))) {
      setError(t(copy.socialReelNeedsVideo));
      return;
    }
    if (intent !== "draft" && targets.some((account) => account.platform === "instagram") && files.length === 0) {
      setError(t(copy.socialInstagramNeedsMedia));
      return;
    }
    if (targets.length === 0) {
      openPicker();
      return;
    }
    setSaving(true);
    setError("");
    const upload = files.length > 0 ? startUploadToast(t, locale) : null;
    try {
      const form = new FormData();
      form.set("body", body);
      form.set("placement", placement);
      form.set("intent", intent);
      if (scheduledAt) form.set("scheduled_at", fromLocalInput(scheduledAt));
      targets.forEach((account) => form.append("account_ids[]", String(account.id)));
      files.forEach((file) => form.append("media[]", file));
      const created = await api.createSocialPost(form, upload?.onProgress);
      if (created.data.last_error) {
        const message = publishErrorMessage(created.data.last_error, t);
        setError(message);
        upload ? upload.fail(message) : toast.error(message);
      } else {
        upload?.done();
      }
      setBody("");
      setFiles([]);
      setScheduledAt("");
      setScheduleMode("draft");
      await refreshPosts();
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.loading);
      setError(message);
      upload ? upload.fail(message) : toast.error(message);
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
          <div className={targets.length > 1 ? "studio-phone-col is-split" : "studio-phone-col"}>
            {targets.map((account) => (
              <SocialPhonePreview
                key={account.id}
                locale={locale}
                t={t}
                studio
                accounts={[account]}
                placement={placement}
                body={account.id === selectedAccount.id ? body : ""}
                existingMedia={[]}
                files={account.id === selectedAccount.id ? previewFiles : []}
                posts={postsByAccount[account.id] ?? []}
                onFiles={canCreate && account.id === selectedAccount.id ? setFiles : undefined}
                dropDisabled={!canCreate}
                feedFilter={feedFilter}
                renderPostActions={account.id === selectedAccount.id ? postActions : undefined}
                focused={account.id === selectedAccount.id}
                onFocus={() => selectAccount(account.id)}
              />
            ))}
          </div>
          <aside className="studio-rail">
            {linkedNetworks.length > 0 ? (
              <div className="studio-network-panel">
                <div className="studio-network-dock" role="group" aria-label={t(copy.socialViewNetworks)}>
                  {linkedNetworks.map((account) => {
                    const on = targetIds.includes(account.id);
                    const focused = account.id === selectedAccount.id;
                    return (
                      <button
                        key={account.id}
                        type="button"
                        className={[
                          "studio-network-dot",
                          `is-${account.platform}`,
                          on ? "is-on" : "",
                          focused ? "is-focus" : "",
                        ]
                          .filter(Boolean)
                          .join(" ")}
                        aria-pressed={on}
                        aria-current={focused ? "true" : undefined}
                        aria-label={platformLabel(account.platform, t)}
                        onClick={() => toggleNetwork(account)}
                      >
                        <SocialBrandIcon platform={account.platform} />
                        {on ? (
                          <span className="studio-network-check" aria-hidden="true">
                            <Check size={10} strokeWidth={3} />
                          </span>
                        ) : null}
                      </button>
                    );
                  })}
                </div>
                <div className="studio-placement-dock" role="tablist" aria-label={t(copy.socialPlacement)}>
                  <button
                    type="button"
                    role="tab"
                    className={placement === "story" ? "is-on" : undefined}
                    aria-selected={placement === "story"}
                    onClick={() => setPlacement("story")}
                  >
                    {t(copy.socialPlacementStory)}
                  </button>
                  {instagramOn ? (
                    <button
                      type="button"
                      role="tab"
                      className={placement === "reel" ? "is-on" : undefined}
                      aria-selected={placement === "reel"}
                      onClick={() => setPlacement("reel")}
                    >
                      {t(copy.socialPlacementReel)}
                    </button>
                  ) : null}
                  <button
                    type="button"
                    role="tab"
                    className={placement === "feed" ? "is-on" : undefined}
                    aria-selected={placement === "feed"}
                    onClick={() => setPlacement("feed")}
                  >
                    {t(copy.socialPlacementPost)}
                  </button>
                </div>
              </div>
            ) : null}
            {canCreate ? (
              <section className="card stack studio-rail-compose">
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
            <section className="card stack studio-rail-posts">
              <header className="studio-rail-head">
                <h2 className="section-title">{t(copy.socialAccountPosts)}</h2>
                <span className="muted">{t(copy.socialPostsCount).replace("{count}", String(listed.length))}</span>
              </header>
              <div className="social-phone-filters" role="tablist" aria-label={t(copy.socialPosts)}>
                {(["all", "draft", "scheduled"] as const).map((value) => (
                  <button
                    key={value}
                    type="button"
                    role="tab"
                    aria-selected={feedFilter === value}
                    className={feedFilter === value ? "is-on" : undefined}
                    onClick={() => setFeedFilter(value)}
                  >
                    {value === "all" ? t(copy.socialFeedAll) : value === "draft" ? t(copy.socialFeedDrafts) : t(copy.socialFeedScheduled)}
                  </button>
                ))}
              </div>
              {listed.length === 0 ? <p className="muted">{t(copy.socialEmptyFeed)}</p> : null}
              {listed.length > 0 ? (
                <ul className="studio-ig-list">
                  {listed.map((post) => (
                    <li key={post.id}>
                      <SocialPostCard
                        account={selectedAccount}
                        post={post}
                        locale={locale}
                        t={t}
                        toolbar={postActions(post)}
                      />
                      {post.last_error ? <small className="error-inline">{publishErrorMessage(post.last_error, t)}</small> : null}
                    </li>
                  ))}
                </ul>
              ) : null}
            </section>
          </aside>
        </form>
      ) : null}
    </SocialChrome>
  );
}
