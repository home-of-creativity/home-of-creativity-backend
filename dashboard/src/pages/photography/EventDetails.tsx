import { TriangleAlert, Bell, CalendarDays, Check, Copy, ExternalLink, CircleQuestionMark, Mail, MapPin, MessageSquare, Pencil, Phone, Repeat, Trash, User, Users, Video, X } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import {
  api,
  type CommentSubject,
  type CalendarEventRow,
  type CalendarScope,
  type CalendarSource,
  type PhotographyAction,
  type PhotographyBoard,
  type PhotographyBookingRow,
  type PhotographySlot,
} from "../../api";
import { CommentsPanel } from "../../components/CommentsPanel";
import { copy, type Locale } from "../../i18n";
import { scopeLabel } from "./EventDialog";
import { TONE_COLORS, bookingTone, describeRule, eventColor, formatRangeText, plainText, statusCopy, wallClock } from "./model";

type ActExtra = { starts_at?: string; direct?: boolean; override_lead?: boolean };

/** Team comments under a calendar popover: folded, with the count on the button. */
function PopComments({ locale, subject }: { locale: Locale; subject: CommentSubject }) {
  const [open, setOpen] = useState(false);
  const [count, setCount] = useState(0);
  const t = (text: { ar: string; en: string }) => text[locale];
  return (
    <div className="comments-section">
      <button type="button" className="btn btn-ghost btn-sm comments-toggle" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        <MessageSquare size={15} aria-hidden />
        {t(copy.comments)}{count > 0 ? ` (${count})` : ""}
      </button>
      {/* Mounted while folded so the button shows the count. */}
      <div hidden={!open}>
        <CommentsPanel subject={subject} locale={locale} t={t} compact onCount={setCount} />
      </div>
    </div>
  );
}

export function GoogleEventDetails({
  locale,
  row,
  calendar,
  onEdit,
  onDuplicate,
  onDeleted,
  onClose,
}: {
  locale: Locale;
  row: CalendarEventRow;
  calendar: CalendarSource | undefined;
  onEdit: () => void;
  onDuplicate?: () => void;
  onDeleted: () => void;
  onClose: () => void;
}) {
  const ar = locale === "ar";
  const [deleting, setDeleting] = useState(false);
  const [scope, setScope] = useState<CalendarScope>("this");
  const [notify, setNotify] = useState(true);
  const [busy, setBusy] = useState(false);
  const recurring = row.recurring_event_id != null;
  const color = eventColor(row.color_id, calendar?.color ?? null);
  const description = plainText(row.description);
  const guests = row.attendees;
  const counts = {
    yes: guests.filter((guest) => guest.response === "accepted").length,
    no: guests.filter((guest) => guest.response === "declined").length,
    maybe: guests.filter((guest) => guest.response === "tentative" || guest.response === "needsAction").length,
  };

  async function remove() {
    setBusy(true);
    try {
      const res = await api.deletePhotographyEvent({ calendar_id: row.calendar_id, event_id: row.id, scope: recurring ? scope : undefined, notify: notify && guests.length > 0 });
      toast.success(res.message || (ar ? "حُذف." : "Deleted."));
      onDeleted();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="gcal-pop">
      <div className="gcal-pop-tools">
        {row.editable ? (
          <>
            <button type="button" className="gcal-icon-btn" onClick={onEdit} title={ar ? "تعديل" : "Edit"} aria-label={ar ? "تعديل" : "Edit"}><Pencil size={16} aria-hidden /></button>
            <button type="button" className="gcal-icon-btn" onClick={() => setDeleting((value) => !value)} title={ar ? "حذف" : "Delete"} aria-label={ar ? "حذف" : "Delete"}><Trash size={16} aria-hidden /></button>
          </>
        ) : null}
        {onDuplicate ? <button type="button" className="gcal-icon-btn" onClick={onDuplicate} title={ar ? "نسخ الحدث" : "Duplicate"} aria-label={ar ? "نسخ الحدث" : "Duplicate"}><Copy size={16} aria-hidden /></button> : null}
        {guests.length ? (
          <a className="gcal-icon-btn" href={`mailto:${guests.map((guest) => guest.email).join(",")}?subject=${encodeURIComponent(row.title)}`} title={ar ? "راسل المدعوين" : "Email guests"} aria-label={ar ? "راسل المدعوين" : "Email guests"}><Mail size={16} aria-hidden /></a>
        ) : null}
        {row.html_link ? (
          <a className="gcal-icon-btn" href={row.html_link} target="_blank" rel="noreferrer" title={ar ? "افتح في جوجل كالندر" : "Open in Google Calendar"} aria-label={ar ? "افتح في جوجل كالندر" : "Open in Google Calendar"}><ExternalLink size={16} aria-hidden /></a>
        ) : null}
        <button type="button" className="gcal-icon-btn" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}><X size={16} aria-hidden /></button>
      </div>

      <div className="gcal-pop-head">
        <span className="gcal-dot" style={{ background: color }} aria-hidden />
        <div>
          <h3>{row.title || (ar ? "(بلا عنوان)" : "(No title)")}</h3>
          <p>{formatRangeText(locale, row.start, row.end, row.all_day)}</p>
          {recurring || row.recurrence ? <p className="muted"><Repeat size={13} aria-hidden /> {describeRule(row.recurrence, row.start.slice(0, 10), locale) || (ar ? "حدث متكرر" : "Repeating event")}</p> : null}
        </div>
      </div>

      {deleting ? (
        <div className="gcal-pop-confirm">
          {recurring ? (
            <fieldset className="gcal-scope">
              <legend>{ar ? "حذف" : "Delete"}</legend>
              {(["this", "following", "all"] as CalendarScope[]).map((item) => (
                <label key={item} className="checkbox-row">
                  <input type="radio" name={`del-${row.id}`} checked={scope === item} onChange={() => setScope(item)} />
                  {scopeLabel(item, locale)}
                </label>
              ))}
            </fieldset>
          ) : (
            <p>{ar ? "حذف هذا الحدث من جوجل كالندر؟" : "Delete this event from Google Calendar?"}</p>
          )}
          {guests.length ? (
            <label className="checkbox-row">
              <input type="checkbox" checked={notify} onChange={(event) => setNotify(event.target.checked)} />
              {ar ? "أبلغ المدعوين بالإلغاء" : "Send cancellation to guests"}
            </label>
          ) : null}
          <div className="toolbar">
            <button type="button" className="btn btn-primary btn-danger" disabled={busy} onClick={remove}>{ar ? "احذف" : "Delete"}</button>
            <button type="button" className="btn btn-ghost" onClick={() => setDeleting(false)}>{ar ? "تراجع" : "Cancel"}</button>
          </div>
        </div>
      ) : null}

      <ul className="gcal-pop-list">
        {row.meet_url ? (
          <li>
            <Video size={16} aria-hidden />
            <a className="btn btn-primary gcal-meet" href={row.meet_url} target="_blank" rel="noreferrer">{ar ? "انضم عبر Google Meet" : "Join with Google Meet"}</a>
          </li>
        ) : null}
        {row.location ? (
          <li>
            <MapPin size={16} aria-hidden />
            <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(row.location)}`} target="_blank" rel="noreferrer">{row.location}</a>
          </li>
        ) : null}
        {guests.length ? (
          <li>
            <Users size={16} aria-hidden />
            <div>
              <strong>{ar ? `${guests.length} مدعوين` : `${guests.length} guests`}</strong>
              <small className="muted">
                {ar ? `${counts.yes} نعم · ${counts.no} لا · ${counts.maybe} بانتظار الرد` : `${counts.yes} yes · ${counts.no} no · ${counts.maybe} awaiting`}
              </small>
              <ul className="gcal-guests">
                {guests.map((guest) => (
                  <li key={guest.email}>
                    {guest.response === "accepted" ? <Check size={13} className="is-yes" aria-label={ar ? "قبل" : "Accepted"} /> : guest.response === "declined" ? <X size={13} className="is-no" aria-label={ar ? "رفض" : "Declined"} /> : <CircleQuestionMark size={13} className="is-maybe" aria-label={ar ? "لم يرد" : "Awaiting"} />}
                    <span dir="ltr">{guest.name ?? guest.email}</span>
                    {guest.organizer ? <small className="muted">{ar ? "المنظم" : "Organizer"}</small> : null}
                  </li>
                ))}
              </ul>
            </div>
          </li>
        ) : null}
        {description ? (
          <li>
            <span aria-hidden className="gcal-pop-gap" />
            <p className="gcal-pop-desc">{description}</p>
          </li>
        ) : null}
        <li>
          <Bell size={16} aria-hidden />
          <span>
            {row.reminders.use_default
              ? ar ? "التنبيهات الافتراضية" : "Default notifications"
              : row.reminders.overrides.length
                ? row.reminders.overrides.map((item) => `${item.minutes} ${ar ? "د" : "min"}${item.method === "email" ? (ar ? " (بريد)" : " (email)") : ""}`).join(" · ")
                : ar ? "بلا تنبيهات" : "No notifications"}
          </span>
        </li>
        <li>
          <CalendarDays size={16} aria-hidden />
          <span>
            {calendar?.summary ?? row.calendar_id}
            {row.transparency === "transparent" ? (ar ? " · متاح" : " · Free") : ""}
            {row.visibility === "private" ? (ar ? " · خاص" : " · Private") : ""}
          </span>
        </li>
        {!row.editable ? (
          <li className="muted">
            <TriangleAlert size={16} aria-hidden />
            <span>{ar ? "للعرض فقط: لا تملك صلاحية تعديل هذا التقويم أو هذا الحدث." : "View only: you cannot change this calendar or event."}</span>
          </li>
        ) : null}
      </ul>
      <PopComments key={`${row.calendar_id}:${row.id}`} locale={locale} subject={{ type: "calendar_event", id: row.id, calendarId: row.calendar_id }} />
    </div>
  );
}

export function BookingDetails({
  locale,
  row,
  board,
  busy,
  onAct,
  onClose,
}: {
  locale: Locale;
  row: PhotographyBookingRow;
  board: PhotographyBoard | null;
  busy: string;
  onAct: (row: PhotographyBookingRow, action: PhotographyAction, extra?: ActExtra) => void;
  onClose: () => void;
}) {
  const ar = locale === "ar";
  const [date, setDate] = useState("");
  const [slots, setSlots] = useState<PhotographySlot[]>([]);
  const [time, setTime] = useState("");
  const tone = bookingTone(row, board?.me.employee_id ?? null);
  const canAll = board?.me.can_all === true;
  const disabled = busy !== "";

  async function loadSlots(day: string) {
    setDate(day);
    setTime("");
    if (!day) return;
    try {
      const res = await api.photographySlots(day, row.id);
      setSlots(res.data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    }
  }

  const buttons: Array<{ action: PhotographyAction; show: boolean; label: string; primary?: boolean }> = [
    { action: "accept", show: row.can.accept, label: ar ? "قبول" : "Accept", primary: true },
    { action: "decline", show: row.can.decline, label: ar ? "رفض" : "Decline" },
    { action: "done", show: row.can.done, label: ar ? "تم التصوير" : "Done", primary: true },
    { action: "noshow", show: row.can.noshow, label: ar ? "لم يحضر" : "No show" },
    { action: "refund", show: row.can.refund, label: ar ? "رد الجلسة" : "Refund" },
    { action: "resend", show: row.can.resend, label: ar ? "أعد إرسال رسالة العميل" : "Resend to client" },
    { action: "retry_calendar", show: row.can.retry_calendar, label: ar ? "أعد التقويم" : "Retry calendar" },
    { action: "retry_clickup", show: row.can.retry_clickup, label: ar ? "أعد ClickUp" : "Retry ClickUp" },
    { action: "cancel", show: row.can.cancel, label: ar ? "إلغاء الموعد" : "Cancel shoot" },
  ];

  return (
    <div className="gcal-pop">
      <div className="gcal-pop-tools">
        {row.calendar_url ? (
          <a className="gcal-icon-btn" href={row.calendar_url} target="_blank" rel="noreferrer" title={ar ? "هذا الموعد في جوجل كالندر" : "This shoot in Google Calendar"} aria-label={ar ? "هذا الموعد في جوجل كالندر" : "This shoot in Google Calendar"}><ExternalLink size={16} aria-hidden /></a>
        ) : null}
        <button type="button" className="gcal-icon-btn" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}><X size={16} aria-hidden /></button>
      </div>
      <div className="gcal-pop-head">
        <span className="gcal-dot" style={{ background: TONE_COLORS[tone] }} aria-hidden />
        <div>
          <h3>{row.client?.name ?? row.request?.client_name ?? "—"}</h3>
          <p>{row.starts_at && row.ends_at ? formatRangeText(locale, row.starts_at, row.ends_at, false) : row.when}</p>
          <p className="muted">{row.request?.number} · {statusCopy[row.status]?.[locale] ?? row.status}</p>
        </div>
      </div>
      <ul className="gcal-pop-list">
        {row.proposed_when ? <li><Repeat size={16} aria-hidden /><span>{ar ? "الوقت المقترح: " : "Proposed: "}{row.proposed_when}</span></li> : null}
        {row.session_label ? <li><CalendarDays size={16} aria-hidden /><span>{row.session_label}</span></li> : null}
        {row.employee ? <li><User size={16} aria-hidden /><span>{row.employee.name}</span></li> : null}
        {row.client?.phone ? <li><Phone size={16} aria-hidden /><a href={`tel:${row.client.phone}`} dir="ltr">{row.client.phone}</a></li> : null}
        {row.expires_at ? <li className={row.late ? "is-warn" : "muted"}><Bell size={16} aria-hidden /><span>{ar ? "ينتهي الانتظار: " : "Reply closes: "}{wallClock(row.expires_at)}</span></li> : null}
        {row.client_notify_failed ? <li className="is-warn"><TriangleAlert size={16} aria-hidden /><span>{ar ? "رسالة العميل لم تصل." : "The client message did not arrive."}</span></li> : null}
        {row.calendar_missing ? <li className="is-warn"><TriangleAlert size={16} aria-hidden /><span>{ar ? "الموعد ناقص على جوجل كالندر." : "Missing on Google Calendar."}</span></li> : null}
        {row.clickup_stale ? <li className="is-warn"><TriangleAlert size={16} aria-hidden /><span>{ar ? "مهمة ClickUp متأخرة." : "ClickUp task is stale."}</span></li> : null}
      </ul>
      <div className="toolbar gcal-pop-actions">
        {buttons.filter((button) => button.show).map((button) => (
          <button key={button.action} type="button" className={button.primary ? "btn btn-primary" : "btn btn-ghost"} disabled={disabled} onClick={() => onAct(row, button.action)}>
            {button.label}
          </button>
        ))}
      </div>
      {row.can.propose || row.can.reschedule ? (
        <div className="gcal-pop-move">
          <strong>{ar ? "وقت آخر" : "Another time"}</strong>
          <p className="muted">{ar ? "أو اسحب الموعد على التقويم." : "Or drag the shoot on the calendar."}</p>
          <div className="gcal-row">
            <input className="field" type="date" value={date} onChange={(event) => loadSlots(event.target.value)} aria-label={ar ? "اليوم" : "Day"} />
            <select className="field" value={time} onChange={(event) => setTime(event.target.value)} aria-label={ar ? "الوقت" : "Time"}>
              <option value="">{ar ? "وقت" : "Time"}</option>
              {slots.map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{slot.label}</option>)}
            </select>
          </div>
          <div className="toolbar">
            {row.can.propose ? <button type="button" className="btn btn-ghost" disabled={disabled || !time} onClick={() => onAct(row, "propose", { starts_at: wallClock(time), override_lead: canAll })}>{ar ? "اقترح على العميل" : "Propose"}</button> : null}
            {row.can.reschedule ? <button type="button" className="btn btn-ghost" disabled={disabled || !time} onClick={() => onAct(row, "reschedule", { starts_at: wallClock(time), override_lead: canAll })}>{ar ? "اطلب التعديل" : "Request move"}</button> : null}
            {row.can.reschedule_direct ? <button type="button" className="btn btn-primary" disabled={disabled || !time} onClick={() => onAct(row, "reschedule", { starts_at: wallClock(time), direct: true, override_lead: true })}>{ar ? "انقل مباشرة" : "Move now"}</button> : null}
          </div>
        </div>
      ) : null}
      <PopComments key={row.id} locale={locale} subject={{ type: "photography_booking", id: String(row.id) }} />
    </div>
  );
}
