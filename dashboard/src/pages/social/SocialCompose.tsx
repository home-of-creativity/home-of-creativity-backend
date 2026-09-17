import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api, canSocial, type SocialPost, type SocialPostMedia } from "../../api";
import { useAuth } from "../../auth";
import { ConfirmAction } from "../../components/ConfirmAction";
import { DateTimeField } from "../../components/DateTimeField";
import { copy, socialActivities, type Locale } from "../../i18n";
import { formatWhen, fromLocalInput, publishErrorMessage, socialStatusLabel, toLocalInput } from "./helpers";
import { SocialChrome } from "./SocialChrome";
import { SocialPhonePreview } from "./SocialPhonePreview";
import { useSocialWorkspace } from "./SocialWorkspace";

export function SocialCompose({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { id } = useParams();
  const navigate = useNavigate();
  const postId = id ? Number(id) : null;
  const { selectedAccount, selectAccount, openPicker } = useSocialWorkspace();
  const [post, setPost] = useState<SocialPost | null>(null);
  const [body, setBody] = useState("");
  const [scheduledAt, setScheduledAt] = useState("");
  const [scheduleMode, setScheduleMode] = useState<"draft" | "now" | "custom">("draft");
  const [files, setFiles] = useState<File[]>([]);
  const [existingMedia, setExistingMedia] = useState<SocialPostMedia[]>([]);
  const [removeMediaIds, setRemoveMediaIds] = useState<number[]>([]);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(Boolean(postId));

  useEffect(() => {
    if (!postId) return;
    setLoading(true);
    api
      .socialPost(postId)
      .then((res) => {
        setPost(res.data);
        setBody(res.data.body);
        const nextIds = res.data.accounts?.map((item) => item.id) ?? [];
        if (nextIds[0]) selectAccount(nextIds[0]);
        setScheduledAt(toLocalInput(res.data.scheduled_at));
        setScheduleMode(res.data.scheduled_at ? "custom" : res.data.status === "published" ? "now" : "draft");
        setExistingMedia(res.data.media ?? []);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
      .finally(() => setLoading(false));
  }, [postId, selectAccount, t]);

  const previewFiles = useMemo(
    () => files.map((file) => ({ name: file.name, url: URL.createObjectURL(file), kind: file.type.startsWith("video/") ? "video" : "image" })),
    [files],
  );
  useEffect(() => () => previewFiles.forEach((file) => URL.revokeObjectURL(file.url)), [previewFiles]);

  const isPublished = post?.status === "published";
  const editable = !post || post.is_editable !== false;
  const canPublish = canSocial(user, "approve");
  const hasMedia = files.length > 0 || existingMedia.length > 0;

  function primaryIntent(): "draft" | "schedule" | "publish" {
    if (isPublished) return "draft";
    if (scheduleMode === "now") return "publish";
    if (scheduleMode === "custom") return "schedule";
    return "draft";
  }

  async function submit(event: FormEvent, intent: "draft" | "schedule" | "publish") {
    event.preventDefault();
    if (!selectedAccount) {
      openPicker();
      return;
    }
    if (intent !== "draft" && !body.trim() && !hasMedia) {
      setError(t(copy.socialNeedCaptionOrMedia));
      return;
    }
    if (intent !== "draft" && selectedAccount.platform === "instagram" && !hasMedia) {
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
      removeMediaIds.forEach((value) => form.append("remove_media_ids[]", String(value)));
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
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialCompose} lede={copy.socialComposeLede} immersive>
      {loading ? <p className="muted">{t(copy.loading)}</p> : null}
      {error ? <p className="error">{error}</p> : null}
      <form className="studio-board" onSubmit={(event) => void submit(event, primaryIntent())}>
        <div className="studio-phone-col">
          <SocialPhonePreview
            locale={locale}
            t={t}
            studio
            accounts={selectedAccount ? [selectedAccount] : []}
            placement="feed"
            body={body}
            existingMedia={existingMedia}
            files={previewFiles}
            excludePostId={postId}
            onFiles={editable ? setFiles : undefined}
            dropDisabled={!editable}
          />
        </div>
        <aside className="studio-rail">
          <div className="studio-composer card stack">
            {post ? <span className={`status status-${post.status}`}>{socialStatusLabel(post.status, t)}</span> : null}
          {post?.last_error ? <p className="error">{publishErrorMessage(post.last_error, t)}</p> : null}
          <label className="field-label">
            {t(copy.socialCaptionOptional)}
            <textarea
              className="field"
              rows={5}
              value={body}
              disabled={!editable}
              placeholder={t(copy.socialCaptionHint)}
              onChange={(event) => setBody(event.target.value)}
            />
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
          <fieldset className="plann-schedule">
            <legend>{t(copy.socialSchedule)}</legend>
            <label className="plann-schedule-row">
              <input type="radio" name="edit-schedule" checked={scheduleMode === "draft"} disabled={!editable} onChange={() => setScheduleMode("draft")} />
              {t(copy.socialScheduleDraftMode)}
            </label>
            <label className="plann-schedule-row">
              <input type="radio" name="edit-schedule" checked={scheduleMode === "now"} disabled={!editable || !canPublish} onChange={() => setScheduleMode("now")} />
              {t(copy.socialScheduleNowMode)}
            </label>
            <label className="plann-schedule-row">
              <input type="radio" name="edit-schedule" checked={scheduleMode === "custom"} disabled={!editable} onChange={() => setScheduleMode("custom")} />
              {t(copy.socialScheduleCustomMode)}
            </label>
            {scheduleMode === "custom" ? (
              <DateTimeField value={scheduledAt} onChange={setScheduledAt} disabled={!editable} locale={locale} placeholder={t(copy.socialScheduleAt)} />
            ) : null}
          </fieldset>
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
                <button type="submit" className="btn btn-primary" disabled={saving || (scheduleMode === "now" && !canPublish)}>
                  {scheduleMode === "now" ? t(copy.socialPublishNow) : scheduleMode === "custom" ? t(copy.socialSchedule) : t(copy.socialSaveDraft)}
                </button>
              )}
            </div>
          ) : null}
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
        </div>
        </aside>
      </form>
    </SocialChrome>
  );
}
