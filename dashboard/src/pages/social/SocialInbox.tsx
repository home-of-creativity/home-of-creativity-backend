import { useEffect, useState, type FormEvent } from "react";
import { api, type PageMeta, type SocialInboxItem } from "../../api";
import { useAuth } from "../../auth";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { Pagination } from "../../components/Pagination";
import { copy, type Copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { formatWhen, platformLabel } from "./helpers";

function inboxError(err: unknown, t: (item: Copy) => string) {
  const message = err instanceof Error ? err.message : "";
  if (message.includes("missing_pages_manage_engagement") || message.includes("pages_manage_engagement")) {
    return t(copy.socialNeedEngagePerm);
  }
  return message || t(copy.loading);
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
        setError("sync_error" in res && typeof res.sync_error === "string" && res.sync_error ? res.sync_error : "");
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
        setError(res.sync_error);
      }
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setSyncing(false);
    }
  }

  async function sendReply(event: FormEvent) {
    event.preventDefault();
    if (!replyId) return;
    setSending(true);
    try {
      await api.replySocialInbox(replyId, replyBody);
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
      <div className="table-wrap card">
        <table className="table-flush">
          <thead>
            <tr>
              <th>{t(copy.socialPickAccounts)}</th>
              <th>{t(copy.status)}</th>
              <th>{t(copy.employeeName)}</th>
              <th>{t(copy.socialBody)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? <LoadingTableRow colSpan={5} label={t(copy.loading)} /> : null}
            {!loading && items.length === 0 ? (
              <tr>
                <td colSpan={5}>{t(copy.socialNoInbox)}</td>
              </tr>
            ) : null}
            {items.map((item) => (
              <tr key={item.id}>
                <td>{item.account ? `${item.account.name} · ${platformLabel(item.account.platform, t)}` : "—"}</td>
                <td>
                  <span className={`status ${item.is_replied ? "status-published" : "status-draft"}`}>
                    {item.is_replied ? t(copy.socialReplied) : t(copy.socialOpen)}
                  </span>
                  <small> · {item.kind === "message" ? t(copy.socialMessage) : t(copy.socialComment)}</small>
                </td>
                <td>
                  {item.author_name}
                  <br />
                  <small>{formatWhen(item.occurred_at, locale)}</small>
                </td>
                <td>
                  <p>{item.body}</p>
                  {item.replies?.map((reply) => (
                    <p key={reply.id} className="muted">
                      {reply.user?.name}: {reply.body}
                    </p>
                  ))}
                </td>
                <td>
                  <button type="button" className="btn btn-ghost" onClick={() => { setReplyId(item.id); setReplyBody(""); }}>
                    {t(copy.socialReply)}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {replyId ? (
        <form className="card form-inline" onSubmit={(event) => void sendReply(event)}>
          <label className="field-label">
            <span>{t(copy.socialReply)}</span>
            <textarea className="field" rows={3} value={replyBody} onChange={(event) => setReplyBody(event.target.value)} required />
          </label>
          <button type="submit" className="btn btn-primary" disabled={sending}>
            {t(copy.socialSendReply)}
          </button>
        </form>
      ) : null}
      <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
    </SocialChrome>
  );
}
