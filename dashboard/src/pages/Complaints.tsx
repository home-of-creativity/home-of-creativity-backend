import { CircleCheck, Mail, MessageSquare, Search, Send, StickyNote, Ticket, TriangleAlert, X } from "lucide-react";
import { useCan } from "../lib/useCan";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { api, type ComplaintDetail, type ComplaintRow, type ComplaintStatus } from "../api";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

type Filter = "all" | ComplaintStatus;
type Channel = "bot" | "email" | "note";

const STATUS_COPY: Record<ComplaintStatus, { ar: string; en: string }> = {
  open: { ar: "جديدة", en: "New" },
  in_progress: { ar: "قيد المعالجة", en: "In progress" },
  resolved: { ar: "محلولة", en: "Resolved" },
};

const STATUS_CLASS: Record<ComplaintStatus, string> = {
  open: "status status-sent",
  in_progress: "status status-partial",
  resolved: "status status-paid",
};

const CHANNEL_COPY: Record<string, { ar: string; en: string }> = {
  whatsapp: { ar: "واتساب", en: "WhatsApp" },
  telegram: { ar: "تيليغرام", en: "Telegram" },
  email: { ar: "إيميل", en: "Email" },
  note: { ar: "ملاحظة داخلية", en: "Internal note" },
};

function when(iso: string | null, locale: Locale) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso.replace("T", " ").slice(0, 16);
  return new Intl.DateTimeFormat(locale === "ar" ? "ar-SY-u-nu-latn" : "en-GB", {
    timeZone: "Asia/Damascus",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(date);
}

function statusOf(value: string): ComplaintStatus {
  return value === "in_progress" || value === "resolved" ? value : value === "reviewed" ? "resolved" : "open";
}

export function ComplaintsPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const ar = locale === "ar";
  const [rows, setRows] = useState<ComplaintRow[] | null>(null);
  const [error, setError] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const [openId, setOpenId] = useState<number | null>(null);

  const load = useCallback(async () => {
    const res = await api.complaints();
    setRows(res.data);
  }, []);

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") load().catch(() => undefined);
    }, 30_000);
    return () => window.clearInterval(timer);
  }, [load, t]);

  const counts = useMemo(() => {
    const result: Record<Filter, number> = { all: 0, open: 0, in_progress: 0, resolved: 0 };
    for (const row of rows ?? []) {
      result.all += 1;
      result[statusOf(row.status)] += 1;
    }
    return result;
  }, [rows]);

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return (rows ?? []).filter((row) => {
      if (filter !== "all" && statusOf(row.status) !== filter) return false;
      if (!needle) return true;
      return [row.ticket_number, row.title, row.description, row.client_name, row.company_name, row.phone].some((value) => value?.toLowerCase().includes(needle));
    });
  }, [rows, filter, query]);

  return (
    <section>
      <PageHeader title={t(copy.navComplaints)} lede={t(copy.complaintsLede)} />
      {error ? <p className="form-error">{error}</p> : null}
      {rows === null && !error ? <LoadingLottie variant="page" label={t(copy.loading)} /> : null}
      {rows ? (
        <>
          <div className="complaint-toolbar">
            <div className="complaint-filters" role="tablist" aria-label={ar ? "حالة الشكوى" : "Complaint status"}>
              {(["all", "open", "in_progress", "resolved"] as Filter[]).map((item) => (
                <button key={item} type="button" role="tab" aria-selected={filter === item} className={filter === item ? "is-active" : ""} onClick={() => setFilter(item)}>
                  {item === "all" ? (ar ? "الكل" : "All") : STATUS_COPY[item][locale]} <span>{counts[item]}</span>
                </button>
              ))}
            </div>
            <label className="search-bar complaint-search">
              <Search size={16} aria-hidden="true" />
              <input type="search" className="field" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={ar ? "رقم التذكرة، العميل، العنوان" : "Ticket, client, title"} aria-label={t(copy.search)} />
            </label>
          </div>
          {visible.length === 0 ? (
            <p className="muted">{rows.length === 0 ? (ar ? "ما في شكاوى بعد." : "No complaints yet.") : t(copy.noSearchResults)}</p>
          ) : (
            <div className="table-wrap">
              <table className="complaint-table">
                <thead>
                  <tr>
                    <th>{ar ? "التذكرة" : "Ticket"}</th>
                    <th>{ar ? "العميل" : "Client"}</th>
                    <th>{ar ? "الشكوى" : "Complaint"}</th>
                    <th>{ar ? "الحالة" : "Status"}</th>
                    <th>{ar ? "آخر نشاط" : "Last activity"}</th>
                    <th aria-label={ar ? "فتح" : "Open"} />
                  </tr>
                </thead>
                <tbody>
                  {visible.map((row) => {
                    const status = statusOf(row.status);
                    return (
                      <tr key={row.id} className="complaint-row" onClick={() => setOpenId(row.id)}>
                        <td dir="ltr" className="nowrap">{row.ticket_number ?? <span className="muted">—</span>}</td>
                        <td>
                          <div>{row.company_name || row.client_name || "—"}</div>
                          <small className="muted" dir="ltr">{row.phone || ""}</small>
                        </td>
                        <td>
                          <strong>{row.title}</strong>
                          <div className="complaint-snippet muted">{row.description}</div>
                        </td>
                        <td>
                          <span className={STATUS_CLASS[status]}>{STATUS_COPY[status][locale]}</span>
                          {row.messages_count ? <small className="muted complaint-count"><MessageSquare size={12} aria-hidden="true" /> {row.messages_count}</small> : null}
                        </td>
                        <td className="nowrap">{when(row.last_activity_at, locale)}</td>
                        <td>
                          <button type="button" className={status === "open" ? "btn btn-primary btn-sm" : "btn btn-ghost btn-sm"} onClick={(event) => { event.stopPropagation(); setOpenId(row.id); }}>
                            {status === "open" ? (ar ? "رد" : "Reply") : ar ? "فتح" : "Open"}
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : null}
      {openId !== null ? (
        <ComplaintTicket
          id={openId}
          locale={locale}
          t={t}
          onClose={() => setOpenId(null)}
          onChanged={() => void load().catch(() => undefined)}
        />
      ) : null}
    </section>
  );
}

function ComplaintTicket({
  id,
  locale,
  t,
  onClose,
  onChanged,
}: {
  id: number;
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  onClose: () => void;
  onChanged: () => void;
}) {
  const ar = locale === "ar";
  const [row, setRow] = useState<ComplaintDetail | null>(null);
  const can = useCan();
  const canReply = can("ops.complaints.reply");
  const canStatus = can("ops.complaints.status");
  const [body, setBody] = useState("");
  const [channel, setChannel] = useState<Channel>("bot");
  const [resolve, setResolve] = useState(false);
  const [busy, setBusy] = useState("");
  const [image, setImage] = useState("");
  const [audio, setAudio] = useState("");
  const urls = useRef<string[]>([]);
  const thread = useRef<HTMLOListElement>(null);
  const chose = useRef(false);

  const load = useCallback(async () => {
    const res = await api.complaint(id);
    setRow(res.data);
    if (!chose.current) {
      chose.current = true;
      setChannel(res.data.channels.bot ? "bot" : res.data.channels.email ? "email" : "note");
    }
  }, [id]);

  useEffect(() => {
    load().catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)));
    // A client reply on the bot lands on the ticket; keep the thread fresh while it is open.
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") load().catch(() => undefined);
    }, 20_000);
    return () => window.clearInterval(timer);
  }, [load, t]);

  useEffect(() => () => urls.current.forEach((url) => URL.revokeObjectURL(url)), []);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    thread.current?.lastElementChild?.scrollIntoView({ block: "nearest" });
  }, [row?.messages.length]);

  async function run(key: string, action: () => Promise<{ data: ComplaintDetail; message?: string }>) {
    setBusy(key);
    try {
      const res = await action();
      setRow(res.data);
      if (res.message) toast.success(res.message);
      onChanged();
      return true;
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      return false;
    } finally {
      setBusy("");
    }
  }

  async function send(event: FormEvent) {
    event.preventDefault();
    if (!body.trim()) return;
    const ok = await run("reply", () => api.replyComplaint(id, { body: body.trim(), channel, resolve }));
    if (ok) {
      setBody("");
      setResolve(false);
    }
  }

  async function openFile(kind: "image" | "audio") {
    if ((kind === "image" && image) || (kind === "audio" && audio)) return;
    setBusy(kind);
    try {
      const blob = await api.complaintFile(id, kind);
      const url = URL.createObjectURL(blob);
      urls.current.push(url);
      if (kind === "image") setImage(url);
      else setAudio(url);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  const status = row ? statusOf(row.status) : "open";
  const botLabel = row?.channels.bot ? CHANNEL_COPY[row.channels.bot][locale] : ar ? "البوت" : "Bot";

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="complaint-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg complaint-panel">
        <div className="form-dialog-head">
          <div>
            <h2 className="form-dialog-title" id="complaint-title">{row?.title ?? (ar ? "الشكوى" : "Complaint")}</h2>
            {row ? (
              <p className="complaint-meta">
                {row.ticket_number ? (
                  <span className="complaint-ticket" dir="ltr"><Ticket size={13} aria-hidden="true" /> {row.ticket_number}</span>
                ) : (
                  <span className="muted">{ar ? "بلا تذكرة بعد" : "No ticket yet"}</span>
                )}
                <span className={STATUS_CLASS[status]}>{STATUS_COPY[status][locale]}</span>
                <span className="muted">{when(row.created_at, locale)}</span>
              </p>
            ) : null}
          </div>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}>
            <X aria-hidden />
          </button>
        </div>

        {row === null ? (
          <div className="form-dialog-body"><p className="muted">{t(copy.loading)}</p></div>
        ) : (
          <form className="form-dialog-form" onSubmit={send}>
            <div className="form-dialog-body complaint-body">
              <aside className="complaint-side">
                <div className="complaint-card">
                  <strong>{row.company_name || row.client_name || "—"}</strong>
                  {row.company_name && row.client_name ? <span>{row.client_name}</span> : null}
                  {row.phone ? <a href={`tel:${row.phone}`} dir="ltr">{row.phone}</a> : null}
                  {row.channels.email ? <a href={`mailto:${row.channels.email}`} dir="ltr">{row.channels.email}</a> : null}
                </div>
                <div className="complaint-actions">
                  {!row.ticket_number && canReply ? (
                    <button type="button" className="btn btn-primary btn-sm" disabled={busy !== ""} onClick={() => void run("ticket", () => api.openComplaintTicket(id))}>
                      <Ticket size={14} aria-hidden="true" /> {ar ? "فتح تذكرة" : "Open ticket"}
                    </button>
                  ) : null}
                  <label className="field-label">
                    {ar ? "الحالة" : "Status"}
                    <select className="field" value={status} disabled={busy !== "" || !canStatus} onChange={(event) => void run("status", () => api.updateComplaint(id, event.target.value as ComplaintStatus))}>
                      {(["open", "in_progress", "resolved"] as ComplaintStatus[]).map((item) => (
                        <option key={item} value={item}>{STATUS_COPY[item][locale]}</option>
                      ))}
                    </select>
                  </label>
                </div>
              </aside>

              <div className="complaint-main">
                <article className="complaint-original">
                  <small className="muted">{ar ? "الشكوى من العميل" : "The client's complaint"}</small>
                  <p>{row.description}</p>
                  <div className="toolbar">
                    {row.has_image ? <button type="button" className="btn btn-ghost btn-sm" disabled={busy !== ""} onClick={() => void openFile("image")}>{ar ? "الصورة" : "Photo"}</button> : null}
                    {row.has_audio ? <button type="button" className="btn btn-ghost btn-sm" disabled={busy !== ""} onClick={() => void openFile("audio")}>{ar ? "التسجيل" : "Voice note"}</button> : null}
                  </div>
                  {image ? <img src={image} alt={ar ? "صورة الشكوى" : "Complaint photo"} className="complaint-image" /> : null}
                  {audio ? <audio controls src={audio} /> : null}
                </article>

                {row.messages.length ? (
                  <ol className="complaint-thread" ref={thread} aria-label={ar ? "الردود" : "Replies"}>
                    {row.messages.map((message) => (
                      <li key={message.id} className={`complaint-msg is-${message.author}${message.channel === "note" ? " is-note" : ""}`}>
                        <div className="complaint-msg-head">
                          <strong>{message.author === "staff" ? message.author_name ?? (ar ? "الفريق" : "Team") : message.author_name ?? (ar ? "العميل" : "Client")}</strong>
                          <span className="muted">{CHANNEL_COPY[message.channel]?.[locale] ?? message.channel} · {when(message.created_at, locale)}</span>
                        </div>
                        <p>{message.body}</p>
                        {!message.delivered ? (
                          <small className="complaint-failed"><TriangleAlert size={12} aria-hidden="true" /> {ar ? "لم يصل للعميل" : "Not delivered"}</small>
                        ) : null}
                      </li>
                    ))}
                  </ol>
                ) : (
                  <p className="muted complaint-empty">{ar ? "لا ردود بعد. أول رد يفتح تذكرة ويصل العميل." : "No replies yet. The first reply opens a ticket and reaches the client."}</p>
                )}

                {canReply ? <div className="complaint-reply">
                  <div className="gcal-segment complaint-channels" role="radiogroup" aria-label={ar ? "قناة الرد" : "Reply channel"}>
                    <button type="button" role="radio" aria-checked={channel === "bot"} className={channel === "bot" ? "is-active" : ""} disabled={!row.channels.bot} onClick={() => setChannel("bot")} title={row.channels.bot ? undefined : ar ? "العميل لا يصله البوت الآن" : "The bot cannot reach the client now"}>
                      <Send size={14} aria-hidden="true" /> {botLabel}
                    </button>
                    <button type="button" role="radio" aria-checked={channel === "email"} className={channel === "email" ? "is-active" : ""} disabled={!row.channels.email} onClick={() => setChannel("email")} title={row.channels.email ? undefined : ar ? "لا يوجد إيميل للعميل" : "No client email"}>
                      <Mail size={14} aria-hidden="true" /> {ar ? "إيميل" : "Email"}
                    </button>
                    <button type="button" role="radio" aria-checked={channel === "note"} className={channel === "note" ? "is-active" : ""} onClick={() => setChannel("note")}>
                      <StickyNote size={14} aria-hidden="true" /> {ar ? "ملاحظة داخلية" : "Internal note"}
                    </button>
                  </div>
                  <textarea
                    className="field"
                    rows={4}
                    maxLength={4000}
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    placeholder={channel === "note" ? (ar ? "ملاحظة للفريق، لا تصل العميل" : "A note for the team; the client does not see it") : ar ? "اكتب ردك للعميل" : "Write your reply to the client"}
                    aria-label={ar ? "الرد" : "Reply"}
                    required
                  />
                  {channel === "bot" ? <small className="muted">{ar ? "ما يكتبه العميل على البوت خلال 24 ساعة يُضاف هنا تلقائياً، وبعدها بزر «رد على التذكرة»." : "What the client writes on the bot within 24 hours lands here; after that, through the «reply» button."}</small> : null}
                  {channel === "email" ? <small className="muted">{ar ? "رد العميل على الإيميل يظهر هنا خلال دقيقتين." : "The client's email answer appears here within two minutes."}</small> : null}
                </div> : <p className="muted">{ar ? "عرض فقط: لا تملك صلاحية الرد على الشكاوى." : "View only: you cannot reply to complaints."}</p>}
              </div>
            </div>
            {canReply ? <div className="form-dialog-actions">
              <button type="submit" className="btn btn-primary" disabled={busy !== "" || !body.trim()} aria-busy={busy === "reply"}>
                <Send size={15} aria-hidden="true" /> {channel === "note" ? (ar ? "حفظ الملاحظة" : "Save note") : ar ? "إرسال الرد" : "Send reply"}
              </button>
              <label className="checkbox-row">
                <input type="checkbox" checked={resolve} onChange={(event) => setResolve(event.target.checked)} />
                <CircleCheck size={15} aria-hidden="true" /> {ar ? "أغلق التذكرة كمحلولة" : "Close the ticket as resolved"}
              </label>
            </div> : null}
          </form>
        )}
      </div>
    </div>
  );
}
