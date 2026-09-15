import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { api, type PageMeta, type SocialInboxItem } from "../../api";
import { useAuth } from "../../auth";
import { Pagination } from "../../components/Pagination";
import { copy, type Copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { formatWhen, inboxErrorMessage, platformLabel } from "./helpers";

function inboxError(err: unknown, t: (item: Copy) => string) {
  const message = err instanceof Error ? err.message : "";
  return inboxErrorMessage(message, t);
}

function sourceExcerpt(item: SocialInboxItem) {
  const text = item.source_post?.body || item.source_body;
  if (!text) return "";
  return text.length > 140 ? `${text.slice(0, 140)}…` : text;
}

export function SocialInbox({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [items, setItems] = useState<SocialInboxItem[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [kind, setKind] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [replyId, setReplyId] = useState<number | null>(null);
  const [replyBody, setReplyBody] = useState("");
  const [sending, setSending] = useState(false);
  const [syncing, setSyncing] = useState(false);

  function load() {
    setLoading(true);
    api
      .socialInbox({ kind: kind || undefined, page })
      .then((res) => {
        setItems(res.data);
        setMeta(res.meta);
        setError(
          "sync_error" in res && typeof res.sync_error === "string" && res.sync_error
            ? inboxErrorMessage(res.sync_error, t)
            : "",
        );
      })
      .catch((err) => {
        setItems([]);
        setError(err instanceof Error ? err.message : t(copy.loading));
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, [page, kind]);

  async function sync() {
    setSyncing(true);
    try {
      const res = await api.syncSocialInbox();
      setNotice(`${t(copy.socialSyncInbox)}: ${res.data.imported}`);
      if ("sync_error" in res && typeof res.sync_error === "string" && res.sync_error) {
        setError(inboxErrorMessage(res.sync_error, t));
      }
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setSyncing(false);
    }
  }

  async function sendReply(event: FormEvent, itemId: number) {
    event.preventDefault();
    setSending(true);
    try {
      await api.replySocialInbox(itemId, replyBody);
      setReplyId(null);
      setReplyBody("");
      load();
    } catch (err) {
      setError(inboxError(err, t));
    } finally {
      setSending(false);
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialInbox} lede={copy.socialInboxLede}>
      <div className="toolbar filter-bar">
        <select className="field" value={kind} onChange={(event) => { setPage(1); setKind(event.target.value); }}>
          <option value="">{t(copy.viewAll)}</option>
          <option value="comment">{t(copy.socialComment)}</option>
          <option value="message">{t(copy.socialMessage)}</option>
        </select>
        <button type="button" className="btn btn-primary" disabled={syncing} onClick={() => void sync()}>
          {t(copy.socialSyncInbox)}
        </button>
      </div>
      {notice ? <p className="notice">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}
      {loading ? <p className="muted">{t(copy.loading)}</p> : null}
      {!loading && items.length === 0 ? <p className="card social-inbox-empty">{t(copy.socialNoInbox)}</p> : null}
      <div className="social-inbox-list">
        {items.map((item) => {
          const isComment = item.kind !== "message";
          const excerpt = sourceExcerpt(item);
          return (
            <article key={item.id} className="card social-inbox-card">
              <header className="social-inbox-head">
                <div>
                  <p className="social-inbox-account">
                    {item.account ? `${item.account.name} · ${platformLabel(item.account.platform, t)}` : "—"}
                  </p>
                  <p className="muted">
                    {item.author_name}
                    {item.author_handle ? ` · ${item.author_handle}` : ""}
                    {" · "}
                    {formatWhen(item.occurred_at, locale)}
                  </p>
                </div>
                <div className="social-inbox-flags">
                  <span className={`status ${item.is_replied ? "status-published" : "status-draft"}`}>
                    {item.is_replied ? t(copy.socialReplied) : t(copy.socialOpen)}
                  </span>
                  <span className="status status-pending">
                    {isComment ? t(copy.socialComment) : t(copy.socialMessage)}
                  </span>
                </div>
              </header>

              {isComment ? (
                <section className="social-inbox-source" aria-label={t(copy.socialOnPost)}>
                  {item.source_preview_url ? (
                    <img className="social-inbox-thumb" src={item.source_preview_url} alt="" />
                  ) : (
                    <span className="social-inbox-thumb social-inbox-thumb-empty" aria-hidden="true" />
                  )}
                  <div>
                    <p className="social-inbox-source-label">{t(copy.socialCommentOn)}</p>
                    <p className="social-inbox-source-body">{excerpt || t(copy.socialUnlinkedPost)}</p>
                    <p className="social-inbox-source-links">
                      {item.source_post ? (
                        <Link className="table-link" to={`/social/compose/${item.source_post.id}`}>
                          {t(copy.socialLocalPost)} #{item.source_post.id}
                        </Link>
                      ) : null}
                      {item.source_permalink ? (
                        <a className="table-link" href={item.source_permalink} target="_blank" rel="noreferrer">
                          {t(copy.socialOpenSource)}
                        </a>
                      ) : null}
                    </p>
                  </div>
                </section>
              ) : (
                <p className="muted">{t(copy.socialPrivateConversation)}</p>
              )}

              <p className="social-inbox-body">{item.body}</p>
              {item.replies?.map((reply) => (
                <p key={reply.id} className="social-inbox-reply">
                  {reply.user?.name}: {reply.body}
                </p>
              ))}

              {replyId === item.id ? (
                <form className="social-inbox-reply-form" onSubmit={(event) => void sendReply(event, item.id)}>
                  <label className="field-label">
                    <span>{t(copy.socialReply)}</span>
                    <textarea className="field" rows={3} value={replyBody} onChange={(event) => setReplyBody(event.target.value)} required />
                  </label>
                  <div className="toolbar">
                    <button type="submit" className="btn btn-primary" disabled={sending}>
                      {t(copy.socialSendReply)}
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={() => { setReplyId(null); setReplyBody(""); }}>
                      {t(copy.cancel)}
                    </button>
                  </div>
                </form>
              ) : (
                <button type="button" className="btn btn-ghost" onClick={() => { setReplyId(item.id); setReplyBody(""); }}>
                  {t(copy.socialReply)}
                </button>
              )}
            </article>
          );
        })}
      </div>
      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </SocialChrome>
  );
}
