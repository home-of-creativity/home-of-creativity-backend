import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api, canSocial, type SocialAccount, type SocialPost, type SocialPostMedia } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { DateTimeField } from "../../components/DateTimeField";
import { FileDropzone } from "../../components/FileDropzone";
import { copy, socialActivities, type Locale } from "../../i18n";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { SocialChrome } from "./SocialChrome";
import { SocialPhonePreview } from "./SocialPhonePreview";
import { PlacementTile } from "./PlacementTile";
import { formatWhen, fromLocalInput, groupSocialPages, pageChannelSummary, pageGroupKey, pagePublishAccountIds, publishErrorMessage, socialPlacementLabel, socialStatusLabel, threadsAccountForPage, toLocalInput } from "./helpers";

type SocialPlacementValue = "feed" | "story" | "reel";

function comboEligible(platform: string, placementValue: SocialPlacementValue, hasMedia: boolean, hasVideo: boolean): boolean {
  if (platform === "instagram") return hasMedia && (placementValue !== "reel" || hasVideo);
  if (platform === "facebook") {
    if (placementValue === "story") return hasMedia;
    if (placementValue === "reel") return hasVideo;
    return true;
  }
  if (platform === "linkedin") return placementValue === "feed";
  return true;
}

function comboReason(platform: string, placementValue: SocialPlacementValue, t: (c: { ar: string; en: string }) => string): string | undefined {
  if (placementValue === "reel") return t(copy.socialReelNeedsVideo);
  if (placementValue === "story") return t(copy.socialStoryNeedsMedia);
  if (platform === "instagram") return t(copy.socialInstagramNeedsMedia);
  return undefined;
}

export function SocialCompose({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { id } = useParams();
  const navigate = useNavigate();
  const postId = id ? Number(id) : null;
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [post, setPost] = useState<SocialPost | null>(null);
  const [body, setBody] = useState("");
  const [placement, setPlacement] = useState("feed");
  const [hoverPlacement, setHoverPlacement] = useState<string | null>(null);
  const [accountIds, setAccountIds] = useState<number[]>([]);
  const [pageKey, setPageKey] = useState("");
  const [scheduledAt, setScheduledAt] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [existingMedia, setExistingMedia] = useState<SocialPostMedia[]>([]);
  const [removeMediaIds, setRemoveMediaIds] = useState<number[]>([]);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(Boolean(postId));

  useEffect(() => {
    api.socialAccounts().then((res) => setAccounts(res.data.filter((item) => item.is_active))).catch(() => setAccounts([]));
  }, []);

  useEffect(() => {
    if (!postId) return;
    setLoading(true);
    api
      .socialPost(postId)
      .then((res) => {
        setPost(res.data);
        setBody(res.data.body);
        setPlacement(res.data.placement || "feed");
        const nextIds = res.data.accounts?.map((item) => item.id) ?? [];
        setAccountIds(nextIds);
        setScheduledAt(toLocalInput(res.data.scheduled_at));
        setExistingMedia(res.data.media ?? []);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
      .finally(() => setLoading(false));
  }, [postId]);

  const previewFiles = useMemo(() => files.map((file) => ({ name: file.name, url: URL.createObjectURL(file), kind: file.type.startsWith("video/") ? "video" : "image" })), [files]);
  useEffect(() => () => previewFiles.forEach((file) => URL.revokeObjectURL(file.url)), [previewFiles]);

  const pages = useMemo(() => groupSocialPages(accounts), [accounts]);
  const selectedPage = pages.find((page) => page.key === pageKey) ?? null;
  const selectedAccounts = accounts.filter((account) => accountIds.includes(account.id));
  const threadsOption = selectedPage ? threadsAccountForPage(selectedPage, accounts) : undefined;
  const instagramSelected = selectedAccounts.some((account) => account.platform === "instagram");
  const hasMedia = files.length > 0 || existingMedia.length > 0;
  const hasVideo = files.some((file) => file.type.startsWith("video/")) || existingMedia.some((item) => item.kind === "video");
  const isPublished = post?.status === "published";
  const editable = !post || post.is_editable !== false;

  useEffect(() => {
    if (pageKey || pages.length === 0) return;
    const fromSelection = accounts.find((account) => accountIds.includes(account.id));
    if (fromSelection) {
      setPageKey(pageGroupKey(fromSelection));
      return;
    }
    if (!postId && pages.length > 0) {
      const first = pages[0];
      setPageKey(first.key);
      setAccountIds(pagePublishAccountIds(first, accounts));
    }
  }, [accounts, accountIds, pageKey, pages, postId]);

  function selectPage(key: string) {
    const page = pages.find((item) => item.key === key);
    setPageKey(key);
    setAccountIds(page ? pagePublishAccountIds(page, accounts) : []);
  }

  function toggleCombo(accountId: number, comboPlacement: SocialPlacementValue) {
    const isOn = accountIds.includes(accountId) && placement === comboPlacement;
    if (isOn) {
      setAccountIds((current) => current.filter((value) => value !== accountId));
      return;
    }
    setPlacement(comboPlacement);
    setAccountIds((current) => (current.includes(accountId) ? current : [...current, accountId]));
  }

  const previewPlacement = hoverPlacement ?? placement;

  function buildForm(intent: "draft" | "schedule" | "publish") {
    const form = new FormData();
    form.set("body", body);
    form.set("placement", placement);
    form.set("intent", intent);
    if (scheduledAt) form.set("scheduled_at", fromLocalInput(scheduledAt));
    accountIds.forEach((value) => form.append("account_ids[]", String(value)));
    files.forEach((file) => form.append("media[]", file));
    removeMediaIds.forEach((value) => form.append("remove_media_ids[]", String(value)));
    return form;
  }

  async function submit(event: FormEvent, intent: "draft" | "schedule" | "publish") {
    event.preventDefault();
    if (intent !== "draft" && accountIds.length === 0) {
      setError(t(copy.socialPickPage));
      return;
    }
    if (intent !== "draft" && instagramSelected && !hasMedia) {
      setError(t(copy.socialInstagramNeedsMedia));
      return;
    }
    if (intent !== "draft" && placement === "story" && !hasMedia) {
      setError(t(copy.socialStoryNeedsMedia));
      return;
    }
    if (intent !== "draft" && placement === "reel" && !hasVideo) {
      setError(t(copy.socialReelNeedsVideo));
      return;
    }
    setSaving(true);
    setError("");
    try {
      const form = buildForm(intent);
      const res = postId ? await api.updateSocialPost(postId, form) : await api.createSocialPost(form);
      navigate(`/social/compose/${res.data.id}`, { replace: true });
      setPost(res.data);
      setFiles([]);
      setRemoveMediaIds([]);
      setExistingMedia(res.data.media ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setSaving(false);
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialCompose} lede={copy.socialComposeLede}>
      {loading ? <p className="muted">{t(copy.loading)}</p> : null}
      {error ? <p className="error">{error}</p> : null}
      <form className="social-compose" onSubmit={(event) => void submit(event, "draft")}>
        <div className="card social-compose-main">
          <label className="field-label">
            <span>{t(copy.socialBody)}</span>
            <textarea className="field" rows={8} value={body} disabled={!editable} onChange={(event) => setBody(event.target.value)} required />
          </label>
          <label className="field-label">
            <span>{t(copy.socialMedia)}</span>
            <FileDropzone
              accept={{ "image/*": [], "video/*": [] }}
              multiple
              disabled={!editable}
              hint={t(copy.dropzoneHintMultiple)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(nextFiles) => setFiles(nextFiles)}
            />
            {files.length > 0 ? (
              <p className="muted">
                {files.length} {locale === "ar" ? "ملف مختار" : "file(s) selected"}
              </p>
            ) : null}
          </label>
          {existingMedia.length > 0 ? (
            <ul className="social-media-list">
              {existingMedia.map((item) => (
                <li key={item.id}>
                  <span>{item.original_name}</span>
                  {editable ? (
                    <button
                      type="button"
                      className="btn btn-ghost"
                      onClick={() => {
                        setExistingMedia((current) => current.filter((media) => media.id !== item.id));
                        setRemoveMediaIds((current) => [...current, item.id]);
                      }}
                    >
                      {t(copy.delete)}
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}
          <fieldset className="field-label">
            <legend>{t(copy.socialPickPage)}</legend>
            {pages.length === 0 ? <p className="muted">{t(copy.socialNoAccounts)}</p> : null}
            <div className="social-account-picks" role="radiogroup" aria-label={t(copy.socialPickPage)}>
              {pages.map((page) => (
                <label key={page.key} className="check-row">
                  <input
                    type="radio"
                    name="social-page"
                    checked={pageKey === page.key}
                    disabled={!editable}
                    onChange={() => selectPage(page.key)}
                  />
                  <span>
                    {page.name}
                    <small className="muted"> · {pageChannelSummary(page, t)}</small>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
          {selectedPage ? (
            <fieldset className="field-label">
              <legend>{t(copy.socialPickPlatforms)}</legend>
              <p className="muted">{t(copy.socialPlacementHint)}</p>
              <div className="placement-tile-list">
                {(
                  [
                    selectedPage.facebook
                      ? { account: selectedPage.facebook, tone: "facebook" as const, label: t(copy.facebook), placements: ["feed", "story", "reel"] as SocialPlacementValue[] }
                      : null,
                    selectedPage.instagram
                      ? { account: selectedPage.instagram, tone: "instagram" as const, label: t(copy.instagram), placements: ["feed", "story", "reel"] as SocialPlacementValue[] }
                      : null,
                    threadsOption
                      ? { account: threadsOption, tone: "threads" as const, label: t(copy.threads), placements: ["feed"] as SocialPlacementValue[] }
                      : null,
                    selectedPage.linkedin
                      ? { account: selectedPage.linkedin, tone: "linkedin" as const, label: t(copy.linkedin), placements: ["feed"] as SocialPlacementValue[] }
                      : null,
                  ] as Array<{ account: SocialAccount; tone: "facebook" | "instagram" | "threads" | "linkedin"; label: string; placements: SocialPlacementValue[] } | null>
                )
                  .filter((group): group is { account: SocialAccount; tone: "facebook" | "instagram" | "threads" | "linkedin"; label: string; placements: SocialPlacementValue[] } => group !== null)
                  .flatMap((group) =>
                  group.placements.map((value) => {
                    const on = accountIds.includes(group.account.id) && placement === value;
                    const eligible = comboEligible(group.account.platform, value, hasMedia, hasVideo);
                    return (
                      <PlacementTile
                        key={`${group.account.id}-${value}`}
                        icon={<SocialBrandIcon platform={group.account.platform} />}
                        iconTone={group.tone}
                        title={`${group.label} · ${socialPlacementLabel(value, t)}`}
                        on={on}
                        disabled={!editable}
                        eligible={eligible}
                        eligibleLabel={comboReason(group.account.platform, value, t)}
                        focused={hoverPlacement === value && on}
                        onToggle={() => toggleCombo(group.account.id, value)}
                        onHoverChange={(hovering) => setHoverPlacement(hovering ? value : null)}
                      />
                    );
                  }),
                )}
              </div>
            </fieldset>
          ) : null}
          <label className="field-label">
            <span>{t(copy.socialScheduleAt)}</span>
            <DateTimeField value={scheduledAt} onChange={setScheduledAt} disabled={!editable} locale={locale} placeholder={t(copy.socialScheduleAt)} />
          </label>
          {editable && canSocial(user, "create") ? (
            <div className="toolbar">
              {isPublished ? (
                <>
                  <button type="submit" className="btn btn-primary" disabled={saving}>
                    {t(copy.socialSaveChanges)}
                  </button>
                  {postId ? (
                    <ConfirmAction
                      label={t(copy.delete)}
                      confirmLabel={t(copy.socialDeleteLive)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      disabled={saving}
                      onConfirm={() => {
                        setSaving(true);
                        api
                          .deleteSocialPost(postId)
                          .then(() => navigate("/social"))
                          .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
                          .finally(() => setSaving(false));
                      }}
                    />
                  ) : null}
                </>
              ) : (
                <>
                  <button type="submit" className="btn" disabled={saving}>
                    {t(copy.socialSaveDraft)}
                  </button>
                  <button type="button" className="btn" disabled={saving} onClick={(event) => void submit(event, "schedule")}>
                    {t(copy.socialSchedule)}
                  </button>
                  {canSocial(user, "approve") ? (
                    <button type="button" className="btn btn-primary" disabled={saving} onClick={(event) => void submit(event, "publish")}>
                      {t(copy.socialPublishNow)}
                    </button>
                  ) : null}
                </>
              )}
            </div>
          ) : null}
        </div>
        <aside className="card social-preview" aria-live="polite">
          <p className="eyebrow">{t(copy.socialPreview)}</p>
          {post ? <span className={`status status-${post.status}`}>{socialStatusLabel(post.status, t)}</span> : null}
          <p className="muted">{socialPlacementLabel(previewPlacement, t)}</p>
          {post?.last_error ? <p className="error">{publishErrorMessage(post.last_error, t)}</p> : null}
          <SocialPhonePreview
            locale={locale}
            t={t}
            accounts={selectedAccounts}
            placement={previewPlacement}
            body={body}
            existingMedia={existingMedia}
            files={previewFiles}
            excludePostId={postId}
          />
          {post?.activities && post.activities.length > 0 ? (
            <section>
              <h2 className="section-title">{t(copy.socialActivity)}</h2>
              <ul className="social-activity">
                {post.activities.map((activity) => (
                  <li key={activity.id}>
                    <strong>{socialActivities[activity.action] ? t(socialActivities[activity.action]) : activity.action}</strong>
                    <span>
                      {activity.user?.name ?? "—"} · {formatWhen(activity.created_at, locale)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </aside>
      </form>
    </SocialChrome>
  );
}
