import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { api, canSocial, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { DateTimeField } from "../../components/DateTimeField";
import { copy, type Locale } from "../../i18n";
import { fromLocalInput } from "./helpers";
import { SocialChrome } from "./SocialChrome";
import { SocialPhonePreview } from "./SocialPhonePreview";
import { useSocialWorkspace } from "./SocialWorkspace";

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

  useEffect(() => {
    if (!selectedAccount) {
      setPosts([]);
      return;
    }
    let cancelled = false;
    api
      .socialPosts({ account_id: selectedAccount.id, per_page: 40 })
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

  async function submit(event: FormEvent, intent: "draft" | "schedule" | "publish") {
    event.preventDefault();
    if (!selectedAccount) {
      openPicker();
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
      const res = await api.socialPosts({ account_id: selectedAccount.id, per_page: 40 });
      setPosts(res.data);
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
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialHome} lede={copy.socialHomeLede}>
      {accountsLoading ? <p className="muted">{t(copy.loading)}</p> : null}
      {!accountsLoading && activeAccounts.length === 0 ? (
        <p className="muted">
          {t(copy.socialNoAccountsYet)}{" "}
          <Link to="/social/accounts">{t(copy.socialAccounts)}</Link>
        </p>
      ) : null}
      {error ? <p className="error">{error}</p> : null}
      {selectedAccount ? (
        <form className="studio-stage" onSubmit={(event) => void submit(event, primaryIntent())}>
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
            onFiles={setFiles}
            dropDisabled={!canCreate}
            feedFilter={feedFilter}
            onFeedFilter={setFeedFilter}
          />
          {canCreate ? (
            <div className="studio-composer card stack">
              <label className="field-label">
                {t(copy.socialBody)}
                <textarea
                  className="field"
                  rows={5}
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
            </div>
          ) : null}
        </form>
      ) : null}
    </SocialChrome>
  );
}
