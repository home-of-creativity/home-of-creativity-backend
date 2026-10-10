import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  api,
  type PhotographyAction,
  type PhotographyBoard,
  type PhotographyBookingRow,
  type PhotographyRequestRow,
  type PhotographySlot,
} from "../api";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

type Tab = "queue" | "waiting" | "mine" | "all" | "unbooked" | "needs";

const statusCopy: Record<string, { ar: string; en: string }> = {
  pending_staff: { ar: "بانتظار المصور", en: "Waiting on photographer" },
  needs_client: { ar: "بانتظار العميل", en: "Waiting on client" },
  confirmed: { ar: "مثبت", en: "Confirmed" },
  rescheduling: { ar: "تعديل بانتظار الرد", en: "Reschedule pending" },
  declined: { ar: "مرفوض", en: "Declined" },
  cancelled: { ar: "ملغى", en: "Cancelled" },
  expired: { ar: "انتهت المهلة", en: "Expired" },
  done: { ar: "تم التصوير", en: "Shot done" },
  noshow: { ar: "لم يحضر", en: "No show" },
};

function wallClock(iso: string) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso.replace("T", " ").slice(0, 16);
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function PhotographyPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [board, setBoard] = useState<PhotographyBoard | null>(null);
  const [error, setError] = useState("");
  const [tab, setTab] = useState<Tab>("queue");
  const [busy, setBusy] = useState("");
  const [times, setTimes] = useState<Record<number, string>>({});
  const [slots, setSlots] = useState<Record<number, PhotographySlot[]>>({});
  const [bookRequest, setBookRequest] = useState("");
  const [bookTime, setBookTime] = useState("");
  const [bookSlots, setBookSlots] = useState<PhotographySlot[]>([]);
  const [bookConfirm, setBookConfirm] = useState(false);
  const [caps, setCaps] = useState<Record<number, string>>({});

  async function load() {
    const res = await api.photographyBoard();
    setBoard(res.data);
    setCaps(Object.fromEntries(res.data.needs_count.map((row) => [row.id, String(row.sessions)])));
  }

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  async function act(row: PhotographyBookingRow, action: PhotographyAction, extra?: { starts_at?: string; direct?: boolean; override_lead?: boolean }) {
    const key = `${row.id}:${action}${extra?.direct ? ":direct" : ""}`;
    setBusy(key);
    try {
      const res = await api.photographyAction(row.id, action, extra);
      toast.success(res.message || t(copy.saved));
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  async function loadSlots(id: number, date: string) {
    if (!date) return;
    try {
      const res = await api.photographySlots(date, id);
      setSlots((current) => ({ ...current, [id]: res.data }));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function loadBookSlots(date: string) {
    if (!date) return;
    try {
      const res = await api.photographySlots(date);
      setBookSlots(res.data);
      setBookTime("");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function book() {
    if (!bookRequest || !bookTime) return;
    setBusy("book");
    try {
      const res = await api.bookPhotography({
        request_id: Number(bookRequest),
        starts_at: wallClock(bookTime),
        confirm: bookConfirm,
        override_lead: board?.me.can_all === true,
      });
      toast.success(res.message || t(copy.saved));
      setBookTime("");
      setBookConfirm(false);
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  async function saveCap(row: PhotographyRequestRow) {
    const sessions = Number(caps[row.id]);
    if (!Number.isInteger(sessions) || sessions < 0 || sessions > 100) {
      toast.error(locale === "ar" ? "عدد الجلسات من 0 إلى 100." : "Sessions must be from 0 to 100.");
      return;
    }
    setBusy(`cap:${row.id}`);
    try {
      const res = await api.setPhotographySessions(row.id, sessions);
      toast.success(res.message || t(copy.saved));
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy("");
    }
  }

  if (!board && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const rows = board
    ? tab === "queue"
      ? board.queue
      : tab === "waiting"
        ? board.waiting_client
        : tab === "mine"
          ? board.mine
          : board.all
    : [];

  const tabs: Array<{ id: Tab; label: { ar: string; en: string }; count: number }> = board
    ? [
        { id: "queue", label: { ar: "بانتظار المصور", en: "Photographer queue" }, count: board.queue.length },
        { id: "waiting", label: { ar: "بانتظار العميل", en: "Waiting on client" }, count: board.waiting_client.length },
        { id: "mine", label: { ar: "مواعيدي", en: "My shoots" }, count: board.mine.length },
        { id: "all", label: { ar: "السجل", en: "History" }, count: board.all.length },
        { id: "unbooked", label: { ar: "بدون موعد", en: "Unbooked" }, count: board.unbooked.length },
        ...(board.me.can_all
          ? [{ id: "needs" as const, label: { ar: "عدد الجلسات", en: "Session count" }, count: board.needs_count.length }]
          : []),
      ]
    : [];

  return (
    <>
      <PageHeader title={t(copy.navPhotography)} lede={t(copy.photographyLede)} />
      {error ? <p className="error">{error}</p> : null}
      {board ? (
        <p className="muted">
          {locale === "ar"
            ? `الجلسة ${board.settings.shoot_hours} ساعات، والفاصل ${board.settings.gap_hours} ساعات، وأقرب موعد بعد ${board.settings.lead_days} أيام. المهلة ${board.settings.reply_hours} ساعة.`
            : `A session is ${board.settings.shoot_hours} hours, with a ${board.settings.gap_hours}-hour gap. The earliest day is ${board.settings.lead_days} days out. Replies expire after ${board.settings.reply_hours} hours.`}
        </p>
      ) : null}
      <div className="toolbar" role="tablist">
        {tabs.map((item) => (
          <button
            key={item.id}
            type="button"
            className={tab === item.id ? "btn btn-primary" : "btn btn-ghost"}
            onClick={() => setTab(item.id)}
          >
            {item.label[locale]} ({item.count})
          </button>
        ))}
      </div>

      {tab === "unbooked" && board ? (
        <section className="panel">
          <h2>{locale === "ar" ? "حجز يدوي" : "Manual booking"}</h2>
          <div className="toolbar">
            <label className="field-label">
              {locale === "ar" ? "الطلب" : "Request"}
              <select className="field" value={bookRequest} onChange={(event) => setBookRequest(event.target.value)}>
                <option value="">{locale === "ar" ? "اختر طلباً" : "Choose a request"}</option>
                {board.unbooked.map((row) => (
                  <option key={row.id} value={row.id}>
                    {row.number} — {row.client_name} ({row.remaining}/{row.sessions})
                  </option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {locale === "ar" ? "اليوم" : "Day"}
              <input className="field" type="date" onChange={(event) => loadBookSlots(event.target.value)} />
            </label>
            <label className="field-label">
              {locale === "ar" ? "الوقت" : "Time"}
              <select className="field" value={bookTime} onChange={(event) => setBookTime(event.target.value)}>
                <option value="">{locale === "ar" ? "اختر وقتاً" : "Choose a time"}</option>
                {bookSlots.map((slot) => (
                  <option key={slot.starts_at} value={slot.starts_at}>{slot.label}</option>
                ))}
              </select>
            </label>
            {board.me.can_all || board.me.is_photographer ? (
              <label className="checkbox-row">
                <input type="checkbox" checked={bookConfirm} onChange={(event) => setBookConfirm(event.target.checked)} />
                {locale === "ar" ? "تثبيت فوري وخصم الجلسة" : "Confirm now and charge the session"}
              </label>
            ) : null}
            <button type="button" className="btn btn-primary" disabled={busy === "book" || !bookRequest || !bookTime} onClick={book}>
              {locale === "ar" ? "احجز" : "Book"}
            </button>
          </div>
          {board.unbooked.length === 0 ? <p className="muted">{locale === "ar" ? "لا توجد طلبات مدفوعة بجلسة متبقية بلا موعد." : "No paid requests with a free session and no booking."}</p> : null}
        </section>
      ) : null}

      {tab === "needs" && board ? (
        <section className="panel">
          {board.needs_count.length === 0 ? <p className="muted">{locale === "ar" ? "كل الطلبات المؤهلة لها عدد جلسات." : "Every eligible request has a session count."}</p> : null}
          {board.needs_count.map((row) => (
            <div className="toolbar" key={row.id}>
              <strong>{row.number}</strong>
              <span>{row.client_name}</span>
              <span>{row.package}</span>
              <label className="field-label">
                {locale === "ar" ? "الجلسات" : "Sessions"}
                <input
                  className="field"
                  type="number"
                  min={0}
                  max={100}
                  value={caps[row.id] ?? ""}
                  onChange={(event) => setCaps((current) => ({ ...current, [row.id]: event.target.value }))}
                />
              </label>
              <button type="button" className="btn btn-primary" disabled={busy === `cap:${row.id}`} onClick={() => saveCap(row)}>
                {locale === "ar" ? "حفظ" : "Save"}
              </button>
            </div>
          ))}
        </section>
      ) : null}

      {tab !== "unbooked" && tab !== "needs" ? (
        <section className="panel recent-panel">
          {rows.length === 0 ? <p className="muted">{locale === "ar" ? "لا مواعيد في هذا القسم." : "Nothing in this list."}</p> : null}
          {rows.length > 0 ? (
            <div className="table-wrap table-flush">
              <table>
                <thead>
                  <tr>
                    <th>{locale === "ar" ? "الطلب" : "Request"}</th>
                    <th>{locale === "ar" ? "العميل" : "Client"}</th>
                    <th>{locale === "ar" ? "الوقت" : "Time"}</th>
                    <th>{locale === "ar" ? "الحالة" : "Status"}</th>
                    <th>{locale === "ar" ? "إجراء" : "Action"}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td>
                        {row.request?.number ?? "—"}
                        {row.session_label ? <div className="muted">{row.session_label}</div> : null}
                      </td>
                      <td>{row.client?.name ?? row.request?.client_name ?? "—"}</td>
                      <td>
                        {row.when || "—"}
                        {row.proposed_when ? <div className="muted">{locale === "ar" ? "مقترح: " : "Proposed: "}{row.proposed_when}</div> : null}
                        {row.late ? <div className="error">{locale === "ar" ? "متأخر" : "Late"}</div> : null}
                        {row.client_notify_failed ? <div className="error">{locale === "ar" ? "رسالة العميل لم تصل" : "Client message failed"}</div> : null}
                        {row.calendar_missing ? <div className="error">{locale === "ar" ? "التقويم ناقص" : "Calendar missing"}</div> : null}
                        {row.clickup_stale ? <div className="error">{locale === "ar" ? "ClickUp لم يتحدث" : "ClickUp is stale"}</div> : null}
                      </td>
                      <td>{statusCopy[row.status]?.[locale] ?? row.status}{row.employee ? ` · ${row.employee.name}` : ""}</td>
                      <td>
                        <div className="toolbar">
                          {row.can.accept ? <button type="button" className="btn btn-primary" disabled={busy !== ""} onClick={() => act(row, "accept")}>{locale === "ar" ? "قبول" : "Accept"}</button> : null}
                          {row.can.decline ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "decline")}>{locale === "ar" ? "رفض" : "Decline"}</button> : null}
                          {row.can.propose || row.can.reschedule ? (
                            <>
                              <input className="field" type="date" onChange={(event) => loadSlots(row.id, event.target.value)} />
                              <select className="field" value={times[row.id] ?? ""} onChange={(event) => setTimes((current) => ({ ...current, [row.id]: event.target.value }))}>
                                <option value="">{locale === "ar" ? "وقت" : "Time"}</option>
                                {(slots[row.id] ?? []).map((slot) => (
                                  <option key={slot.starts_at} value={slot.starts_at}>{slot.label}</option>
                                ))}
                              </select>
                            </>
                          ) : null}
                          {row.can.propose ? (
                            <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[row.id]} onClick={() => act(row, "propose", { starts_at: wallClock(times[row.id]), override_lead: board?.me.can_all === true })}>
                              {locale === "ar" ? "اقترح" : "Propose"}
                            </button>
                          ) : null}
                          {row.can.reschedule ? (
                            <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[row.id]} onClick={() => act(row, "reschedule", { starts_at: wallClock(times[row.id]), override_lead: board?.me.can_all === true })}>
                              {locale === "ar" ? "عدّل" : "Move"}
                            </button>
                          ) : null}
                          {row.can.reschedule_direct ? (
                            <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[row.id]} onClick={() => act(row, "reschedule", { starts_at: wallClock(times[row.id]), direct: true, override_lead: true })}>
                              {locale === "ar" ? "انقل مباشرة" : "Move now"}
                            </button>
                          ) : null}
                          {row.can.cancel ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "cancel")}>{locale === "ar" ? "إلغاء" : "Cancel"}</button> : null}
                          {row.can.done ? <button type="button" className="btn btn-primary" disabled={busy !== ""} onClick={() => act(row, "done")}>{locale === "ar" ? "تم" : "Done"}</button> : null}
                          {row.can.noshow ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "noshow")}>{locale === "ar" ? "لم يحضر" : "No show"}</button> : null}
                          {row.can.refund ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "refund")}>{locale === "ar" ? "رد الجلسة" : "Refund"}</button> : null}
                          {row.can.resend ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "resend")}>{locale === "ar" ? "أعد الإرسال" : "Resend"}</button> : null}
                          {row.can.retry_calendar ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "retry_calendar")}>{locale === "ar" ? "أعد التقويم" : "Retry calendar"}</button> : null}
                          {row.can.retry_clickup ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(row, "retry_clickup")}>ClickUp</button> : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </section>
      ) : null}
    </>
  );
}
