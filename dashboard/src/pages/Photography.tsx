import { useEffect, useMemo, useState } from "react";
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

type Layer = "queue" | "waiting" | "mine";

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

const dayNames = {
  ar: ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"],
  en: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
};

function wallClock(iso: string) {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso.replace("T", " ").slice(0, 16);
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function dayKey(date = new Date()) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Damascus", year: "numeric", month: "2-digit", day: "2-digit" }).format(date);
}

function addDays(key: string, days: number) {
  const [year, month, day] = key.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day + days));
  return date.toISOString().slice(0, 10);
}

function sundayOf(key: string) {
  const [year, month, day] = key.split("-").map(Number);
  return addDays(key, -new Date(Date.UTC(year, month - 1, day)).getUTCDay());
}

function clockParts(iso: string) {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Damascus",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(iso));
  const hour = Number(parts.find((part) => part.type === "hour")?.value ?? "0");
  const minute = Number(parts.find((part) => part.type === "minute")?.value ?? "0");
  return { hour, minute };
}

function eventDay(iso: string) {
  return dayKey(new Date(iso));
}

export function PhotographyPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [board, setBoard] = useState<PhotographyBoard | null>(null);
  const [error, setError] = useState("");
  const [layers, setLayers] = useState<Layer[]>(["queue", "waiting", "mine"]);
  const [week, setWeek] = useState(() => sundayOf(dayKey()));
  const [busy, setBusy] = useState("");
  const [times, setTimes] = useState<Record<number, string>>({});
  const [slots, setSlots] = useState<Record<number, PhotographySlot[]>>({});
  const [bookRequest, setBookRequest] = useState("");
  const [bookDate, setBookDate] = useState("");
  const [bookTime, setBookTime] = useState("");
  const [bookSlots, setBookSlots] = useState<PhotographySlot[]>([]);
  const [bookConfirm, setBookConfirm] = useState(false);
  const [caps, setCaps] = useState<Record<number, string>>({});
  const [openId, setOpenId] = useState<number | null>(null);

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
    setBookDate(date);
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

  const openHour = Number((board?.settings.team_hours?.open ?? "09:00").slice(0, 2)) || 9;
  const closeHour = Number((board?.settings.team_hours?.close ?? "21:00").slice(0, 2)) || 21;
  const hours = Array.from({ length: Math.max(closeHour - openHour, 1) }, (_, index) => openHour + index);
  const days = Array.from({ length: 7 }, (_, index) => addDays(week, index));
  const today = dayKey();

  const events = useMemo(() => {
    const rows = board?.calendar ?? [];
    return rows.filter((row) => {
      if (!row.starts_at) return false;
      const mine = board?.me.employee_id != null && row.employee?.id === board.me.employee_id;
      if (row.status === "confirmed") return true;
      if (layers.includes("queue") && (row.status === "pending_staff" || row.status === "rescheduling")) return true;
      if (layers.includes("waiting") && row.status === "needs_client") return true;
      if (layers.includes("mine") && mine) return true;
      return false;
    });
  }, [board, layers]);

  const selected = events.find((row) => row.id === openId) ?? board?.calendar.find((row) => row.id === openId) ?? null;

  function toggle(layer: Layer) {
    setLayers((current) => (current.includes(layer) ? current.filter((item) => item !== layer) : [...current, layer]));
  }

  if (!board && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const chips: Array<{ id: Layer; label: { ar: string; en: string }; count: number }> = board
    ? [
        { id: "queue", label: { ar: "انتظار المصور", en: "Photographer" }, count: board.queue.length },
        { id: "waiting", label: { ar: "انتظار العميل", en: "Client" }, count: board.waiting_client.length },
        { id: "mine", label: { ar: "مواعيدي", en: "Mine" }, count: board.mine.length },
      ]
    : [];

  return (
    <>
      <PageHeader title={t(copy.navPhotography)} lede={t(copy.photographyLede)} />
      {error ? <p className="error">{error}</p> : null}
      {board ? (
        <div className="photo-cal-toolbar">
          <div className="photo-cal-nav">
            <button type="button" className="btn btn-ghost" onClick={() => setWeek(addDays(week, -7))}>{locale === "ar" ? "الأسبوع السابق" : "Previous"}</button>
            <button type="button" className="btn btn-ghost" onClick={() => setWeek(sundayOf(today))}>{locale === "ar" ? "هذا الأسبوع" : "This week"}</button>
            <button type="button" className="btn btn-ghost" onClick={() => setWeek(addDays(week, 7))}>{locale === "ar" ? "الأسبوع التالي" : "Next"}</button>
            <strong dir="ltr">{days[0]} — {days[6]}</strong>
          </div>
          <div className="photo-cal-legend" role="group">
            {chips.map((chip) => (
              <button key={chip.id} type="button" className={layers.includes(chip.id) ? `photo-chip is-${chip.id}` : "photo-chip"} onClick={() => toggle(chip.id)}>
                {chip.label[locale]} ({chip.count})
              </button>
            ))}
            <span className="photo-chip is-static">{locale === "ar" ? `بدون موعد (${board.unbooked.length})` : `Unbooked (${board.unbooked.length})`}</span>
            {board.me.can_all ? <span className="photo-chip is-static">{locale === "ar" ? `عدد الجلسات (${board.needs_count.length})` : `Sessions (${board.needs_count.length})`}</span> : null}
          </div>
          {board.calendar_url ? <a className="btn btn-primary" href={board.calendar_url} target="_blank" rel="noreferrer">{locale === "ar" ? "فتح جوجل كالندر" : "Open Google Calendar"}</a> : null}
        </div>
      ) : null}

      <div className="photo-cal-layout">
        <section className="photo-cal" aria-label={locale === "ar" ? "تقويم التصوير" : "Photography calendar"}>
          <div className="photo-cal-head">
            <span />
            {days.map((day, index) => (
              <button key={day} type="button" className={day === today ? "is-today" : index === 5 ? "is-friday" : ""} onClick={() => loadBookSlots(day)}>
                <small>{dayNames[locale][index]}</small>
                <strong>{Number(day.slice(8))}</strong>
              </button>
            ))}
          </div>
          <div className="photo-cal-body" style={{ ["--hours" as string]: hours.length }}>
            <div className="photo-cal-hours">
              {hours.map((hour) => <span key={hour}>{String(hour).padStart(2, "0")}:00</span>)}
            </div>
            {days.map((day) => (
              <div key={day} className="photo-cal-day" style={{ ["--hours" as string]: hours.length }}>
                {hours.map((hour) => <span key={hour} className="photo-cal-line" />)}
                {events.filter((row) => row.starts_at && eventDay(row.starts_at) === day).map((row) => {
                  const start = clockParts(row.starts_at!);
                  const end = row.ends_at ? clockParts(row.ends_at) : { hour: start.hour + (board?.settings.shoot_hours ?? 3), minute: start.minute };
                  const top = ((start.hour + start.minute / 60) - openHour) * 52;
                  const height = Math.max(((end.hour + end.minute / 60) - (start.hour + start.minute / 60)) * 52, 36);
                  const tone = row.status === "needs_client" ? "waiting" : row.status === "pending_staff" ? "queue" : row.status === "rescheduling" ? "move" : row.employee?.id === board?.me.employee_id ? "mine" : "agreed";
                  return (
                    <button
                      key={row.id}
                      type="button"
                      className={`photo-event is-${tone}${openId === row.id ? " is-open" : ""}`}
                      style={{ top, height }}
                      onClick={() => setOpenId(row.id)}
                    >
                      <strong>{row.client?.name ?? row.request?.client_name ?? row.request?.number}</strong>
                      <small>{statusCopy[row.status]?.[locale] ?? row.status}</small>
                    </button>
                  );
                })}
              </div>
            ))}
          </div>
        </section>

        <aside className="photo-cal-rail">
          {selected ? (
            <section className="panel">
              <h2>{selected.client?.name ?? selected.request?.client_name ?? "—"}</h2>
              <p>{selected.request?.number} · {statusCopy[selected.status]?.[locale] ?? selected.status}</p>
              <p dir="ltr">{selected.when}</p>
              {selected.proposed_when ? <p className="muted">{locale === "ar" ? "مقترح: " : "Proposed: "}{selected.proposed_when}</p> : null}
              {selected.session_label ? <p className="muted">{selected.session_label}</p> : null}
              {selected.employee ? <p className="muted">{selected.employee.name}</p> : null}
              {selected.calendar_url ? <a href={selected.calendar_url} target="_blank" rel="noreferrer">{locale === "ar" ? "هذا الموعد في جوجل كالندر" : "This shoot in Google Calendar"}</a> : null}
              <div className="toolbar">
                {selected.can.accept ? <button type="button" className="btn btn-primary" disabled={busy !== ""} onClick={() => act(selected, "accept")}>{locale === "ar" ? "قبول" : "Accept"}</button> : null}
                {selected.can.decline ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "decline")}>{locale === "ar" ? "رفض" : "Decline"}</button> : null}
                {selected.can.cancel ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "cancel")}>{locale === "ar" ? "إلغاء" : "Cancel"}</button> : null}
                {selected.can.done ? <button type="button" className="btn btn-primary" disabled={busy !== ""} onClick={() => act(selected, "done")}>{locale === "ar" ? "تم" : "Done"}</button> : null}
                {selected.can.noshow ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "noshow")}>{locale === "ar" ? "لم يحضر" : "No show"}</button> : null}
                {selected.can.refund ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "refund")}>{locale === "ar" ? "رد الجلسة" : "Refund"}</button> : null}
                {selected.can.resend ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "resend")}>{locale === "ar" ? "أعد الإرسال" : "Resend"}</button> : null}
                {selected.can.retry_calendar ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "retry_calendar")}>{locale === "ar" ? "أعد التقويم" : "Retry calendar"}</button> : null}
                {selected.can.retry_clickup ? <button type="button" className="btn btn-ghost" disabled={busy !== ""} onClick={() => act(selected, "retry_clickup")}>ClickUp</button> : null}
              </div>
              {selected.can.propose || selected.can.reschedule ? (
                <div className="toolbar">
                  <input className="field" type="date" onChange={(event) => loadSlots(selected.id, event.target.value)} />
                  <select className="field" value={times[selected.id] ?? ""} onChange={(event) => setTimes((current) => ({ ...current, [selected.id]: event.target.value }))}>
                    <option value="">{locale === "ar" ? "وقت" : "Time"}</option>
                    {(slots[selected.id] ?? []).map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{slot.label}</option>)}
                  </select>
                  {selected.can.propose ? <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[selected.id]} onClick={() => act(selected, "propose", { starts_at: wallClock(times[selected.id]), override_lead: board?.me.can_all === true })}>{locale === "ar" ? "اقترح" : "Propose"}</button> : null}
                  {selected.can.reschedule ? <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[selected.id]} onClick={() => act(selected, "reschedule", { starts_at: wallClock(times[selected.id]), override_lead: board?.me.can_all === true })}>{locale === "ar" ? "عدّل" : "Move"}</button> : null}
                  {selected.can.reschedule_direct ? <button type="button" className="btn btn-ghost" disabled={busy !== "" || !times[selected.id]} onClick={() => act(selected, "reschedule", { starts_at: wallClock(times[selected.id]), direct: true, override_lead: true })}>{locale === "ar" ? "انقل مباشرة" : "Move now"}</button> : null}
                </div>
              ) : null}
            </section>
          ) : null}

          {board ? (
            <section className="panel">
              <h2>{locale === "ar" ? "بدون موعد" : "Unbooked"}</h2>
              <label className="field-label">
                {locale === "ar" ? "الطلب" : "Request"}
                <select className="field" value={bookRequest} onChange={(event) => setBookRequest(event.target.value)}>
                  <option value="">{locale === "ar" ? "اختر طلباً" : "Choose a request"}</option>
                  {board.unbooked.map((row) => (
                    <option key={row.id} value={row.id}>{row.number} — {row.client_name} ({row.remaining}/{row.sessions})</option>
                  ))}
                </select>
              </label>
              <label className="field-label">
                {locale === "ar" ? "اليوم" : "Day"}
                <input className="field" type="date" value={bookDate} onChange={(event) => loadBookSlots(event.target.value)} />
              </label>
              <label className="field-label">
                {locale === "ar" ? "الوقت" : "Time"}
                <select className="field" value={bookTime} onChange={(event) => setBookTime(event.target.value)}>
                  <option value="">{locale === "ar" ? "اختر وقتاً" : "Choose a time"}</option>
                  {bookSlots.map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{slot.label}</option>)}
                </select>
              </label>
              {board.me.can_all || board.me.is_photographer ? (
                <label className="checkbox-row">
                  <input type="checkbox" checked={bookConfirm} onChange={(event) => setBookConfirm(event.target.checked)} />
                  {locale === "ar" ? "تثبيت فوري وخصم الجلسة" : "Confirm now and charge the session"}
                </label>
              ) : null}
              <button type="button" className="btn btn-primary" disabled={busy === "book" || !bookRequest || !bookTime} onClick={book}>
                {locale === "ar" ? "احجز على التقويم" : "Put it on the calendar"}
              </button>
            </section>
          ) : null}

          {board?.me.can_all ? (
            <section className="panel">
              <h2>{locale === "ar" ? "عدد الجلسات" : "Session count"}</h2>
              {board.needs_count.length === 0 ? <p className="muted">{locale === "ar" ? "كل الطلبات المؤهلة لها عدد جلسات." : "Every eligible request has a session count."}</p> : null}
              {board.needs_count.map((row) => (
                <div className="toolbar" key={row.id}>
                  <strong>{row.number}</strong>
                  <span>{row.client_name}</span>
                  <input className="field" type="number" min={0} max={100} value={caps[row.id] ?? ""} onChange={(event) => setCaps((current) => ({ ...current, [row.id]: event.target.value }))} />
                  <button type="button" className="btn btn-primary" disabled={busy === `cap:${row.id}`} onClick={() => saveCap(row)}>{locale === "ar" ? "حفظ" : "Save"}</button>
                </div>
              ))}
            </section>
          ) : null}
        </aside>
      </div>
    </>
  );
}
