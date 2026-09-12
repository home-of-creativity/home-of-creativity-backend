import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { api, canSocial, type SocialAccount, type SocialPost, type SocialPostMedia } from "../../api";
import { useAuth } from "../../auth";
import { copy, socialActivities, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { formatWhen, fromLocalInput, platformLabel, socialStatusLabel, toLocalInput } from "./helpers";

export function SocialCompose({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { id } = useParams();
  const navigate = useNavigate();
  const postId = id ? Number(id) : null;
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [post, setPost] = useState<SocialPost | null>(null);
  const [body, setBody] = useState("");
  const [accountIds, setAccountIds] = useState<number[]>([]);
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
        setAccountIds(res.data.accounts?.map((item) => item.id) ?? []);
        setScheduledAt(toLocalInput(res.data.scheduled_at));
        setExistingMedia(res.data.media ?? []);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
      .finally(() => setLoading(false));
  }, [postId]);

  const previewFiles = useMemo(() => files.map((file) => ({ name: file.name, url: URL.createObjectURL(file), kind: file.type.startsWith("video/") ? "video" : "image" })), [files]);
  useEffect(() => () => previewFiles.forEach((file) => URL.revokeObjectURL(file.url)), [previewFiles]);

  const selectedAccounts = accounts.filter((account) => accountIds.includes(account.id));
  const isPublished = post?.status === "published";
  const editable = !post || post.is_editable !== false;

  function toggleAccount(accountId: number) {
    setAccountIds((current) => (current.includes(accountId) ? current.filter((value) => value !== accountId) : [...current, accountId]));
  }

  function buildForm(intent: "draft" | "schedule" | "publish") {
    const form = new FormData();
    form.set("body", body);
    form.set("intent", intent);
    if (scheduledAt) form.set("scheduled_at", fromLocalInput(scheduledAt));
    accountIds.forEach((value) => form.append("account_ids[]", String(value)));
    files.forEach((file) => form.append("media[]", file));
    removeMediaIds.forEach((value) => form.append("remove_media_ids[]", String(value)));
    return form;
  }

  async function submit(event: FormEvent, intent: "draft" | "schedule" | "publish") {
    event.preventDefault();
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
            <input
              className="field"
              type="file"
              accept="image/*,video/*"
              multiple
              disabled={!editable}
              onChange={(event) => setFiles(Array.from(event.target.files ?? []))}
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
          <fieldset className="field-label">
            <legend>{t(copy.socialPickAccounts)}</legend>
            {accounts.length === 0 ? <p className="muted">{t(copy.socialNoAccounts)}</p> : null}
            <div className="social-account-picks">
              {accounts.map((account) => (
                <label key={account.id} className="check-row">
                  <input type="checkbox" checked={accountIds.includes(account.id)} disabled={!editable} onChange={() => toggleAccount(account.id)} />
                  <span>
                    {account.name} · {platformLabel(account.platform, t)}
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
          <label className="field-label">
            <span>{t(copy.socialScheduleAt)}</span>
            <input className="field" type="datetime-local" value={scheduledAt} disabled={!editable} onChange={(event) => setScheduledAt(event.target.value)} />
          </label>
          {editable && canSocial(user, "create") ? (
            <div className="toolbar">
              {isPublished ? (
                <>
                  <button type="submit" className="btn btn-primary" disabled={saving}>
                    {t(copy.socialSaveChanges)}
                  </button>
                  {postId ? (
                    <button
                      type="button"
                      className="btn"
                      disabled={saving}
                      onClick={() => {
                        if (!window.confirm(t(copy.socialDeleteLive))) return;
                        setSaving(true);
                        api
                          .deleteSocialPost(postId)
                          .then(() => navigate("/social"))
                          .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
                          .finally(() => setSaving(false));
                      }}
                    >
                      {t(copy.delete)}
                    </button>
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
          {post?.last_error ? <p className="error">{post.last_error}</p> : null}
          <p className="social-preview-body">{body || "—"}</p>
          <p className="muted">{selectedAccounts.map((account) => account.name).join(" · ") || t(copy.socialPickAccounts)}</p>
          <div className="social-preview-media">
            {existingMedia.map((item) =>
              item.kind === "video" ? <video key={item.id} src={item.url ?? undefined} controls /> : <img key={item.id} src={item.url ?? ""} alt={item.original_name} />,
            )}
            {previewFiles.map((file) =>
              file.kind === "video" ? <video key={file.url} src={file.url} controls /> : <img key={file.url} src={file.url} alt={file.name} />,
            )}
          </div>
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
