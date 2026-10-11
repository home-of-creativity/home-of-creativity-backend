import { Bell, CalendarDays, Camera, Clock, Lock, MapPin, Plus, Repeat, TextAlignStart, Users, Video, X } from "lucide-react";
import { useEffect, useMemo, useState, type FormEvent, type KeyboardEvent } from "react";
import { toast } from "sonner";
import {
  api,
  type CalendarEventRow,
  type CalendarReminder,
  type CalendarScope,
  type CalendarSource,
  type PhotographyBoard,
  type PhotographySlot,
} from "../../api";
import type { Locale } from "../../i18n";
import {
  GOOGLE_COLORS,
  WEEKDAYS,
  WEEKDAY_NAMES,
  addDays,
  addMinutes,
  buildRule,
  defaultRule,
  detectPreset,
  eventColor,
  minutesBetween,
  parseRule,
  presetLabel,
  presetRules,
  wallClock,
  zoned,
  type RepeatPreset,
  type Rule,
} from "./model";

export type Draft = {
  calendar_id: string;
  title: string;
  description: string;
  location: string;
  all_day: boolean;
  startDate: string;
  startTime: string;
  endDate: string;
  endTime: string;
  color_id: string | null;
  attendees: string[];
  notify: boolean;
  reminders: { use_default: boolean; overrides: CalendarReminder[] };
  recurrence: string[];
  transparency: "opaque" | "transparent";
  visibility: "default" | "public" | "private";
  meet: boolean;
};

export type DialogState =
  | { kind: "create"; draft: Draft; tab: "event" | "shoot"; requestId?: number }
  | { kind: "edit"; row: CalendarEventRow; draft: Draft };

export function emptyDraft(calendarId: string, start: Date, end: Date, allDay: boolean): Draft {
  const from = zoned(start);
  const to = zoned(end);
  const endDate = allDay ? addDays(to.date, -1) : to.date;
  return {
    calendar_id: calendarId,
    title: "",
    description: "",
    location: "",
    all_day: allDay,
    startDate: from.date,
    startTime: allDay ? "09:00" : from.time,
    endDate: endDate < from.date ? from.date : endDate,
    endTime: allDay ? "10:00" : to.time,
    color_id: null,
    attendees: [],
    notify: true,
    reminders: { use_default: true, overrides: [] },
    recurrence: [],
    transparency: allDay ? "transparent" : "opaque",
    visibility: "default",
    meet: false,
  };
}

export function draftFromRow(row: CalendarEventRow): Draft {
  const start = row.all_day ? { date: row.start, time: "09:00" } : zoned(new Date(row.start));
  const end = row.all_day ? { date: addDays(row.end, -1), time: "10:00" } : zoned(new Date(row.end));
  return {
    calendar_id: row.calendar_id,
    title: row.title,
    description: row.description ?? "",
    location: row.location ?? "",
    all_day: row.all_day,
    startDate: start.date,
    startTime: start.time,
    endDate: end.date < start.date ? start.date : end.date,
    endTime: end.time,
    color_id: row.color_id,
    attendees: row.attendees.filter((attendee) => !attendee.organizer).map((attendee) => attendee.email),
    notify: true,
    reminders: { use_default: row.reminders.use_default, overrides: row.reminders.overrides },
    recurrence: row.recurrence ?? [],
    transparency: row.transparency === "transparent" ? "transparent" : "opaque",
    visibility: row.visibility === "public" || row.visibility === "private" ? row.visibility : "default",
    meet: row.meet_url != null,
  };
}

function times(draft: Draft) {
  return draft.all_day
    ? { all_day: true, start: draft.startDate, end: addDays(draft.endDate, 1) }
    : { all_day: false, start: `${draft.startDate}T${draft.startTime}`, end: `${draft.endDate}T${draft.endTime}` };
}

const REMINDER_UNITS: Array<{ id: number; label: { ar: string; en: string } }> = [
  { id: 1, label: { ar: "دقائق", en: "minutes" } },
  { id: 60, label: { ar: "ساعات", en: "hours" } },
  { id: 1440, label: { ar: "أيام", en: "days" } },
  { id: 10080, label: { ar: "أسابيع", en: "weeks" } },
];

function splitMinutes(minutes: number) {
  const unit = [10080, 1440, 60].find((size) => minutes >= size && minutes % size === 0) ?? 1;
  return { amount: minutes / unit, unit };
}

const PRESETS: RepeatPreset[] = ["none", "daily", "weekly", "workdays", "monthly", "monthlyNth", "yearly", "custom"];

export function EventDialog({
  locale,
  state,
  calendars,
  board,
  canManage,
  onClose,
  onSaved,
}: {
  locale: Locale;
  state: DialogState;
  calendars: CalendarSource[];
  board: PhotographyBoard | null;
  canManage: boolean;
  onClose: () => void;
  onSaved: () => void;
}) {
  const ar = locale === "ar";
  const [draft, setDraft] = useState<Draft>(state.draft);
  const [tab, setTab] = useState<"event" | "shoot">(state.kind === "create" ? state.tab : "event");
  const [scope, setScope] = useState<CalendarScope>("this");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [guest, setGuest] = useState("");
  const [preset, setPreset] = useState<RepeatPreset>(() => detectPreset(state.draft.recurrence, state.draft.startDate));
  const [rule, setRule] = useState<Rule>(() => parseRule(state.draft.recurrence, state.draft.startDate) ?? defaultRule(state.draft.startDate));
  const [requestId, setRequestId] = useState(state.kind === "create" && state.requestId ? String(state.requestId) : "");
  const [slots, setSlots] = useState<PhotographySlot[]>([]);
  const [slot, setSlot] = useState("");
  const [confirmNow, setConfirmNow] = useState(false);

  const editing = state.kind === "edit" ? state.row : null;
  const recurring = editing?.recurring_event_id != null;
  const writable = calendars.filter((calendar) => calendar.writable);
  const calendar = calendars.find((item) => item.id === draft.calendar_id) ?? writable[0];
  const canShoot = state.kind === "create" && board != null && (board.me.can_all || board.me.is_photographer);
  const canEvent = canManage && writable.length > 0;
  const showTabs = state.kind === "create" && canShoot && canEvent;

  const recurrence = useMemo(() => {
    if (preset === "none") return [];
    if (preset === "custom") return [buildRule(rule, draft.startDate, draft.all_day)];
    return presetRules(preset, draft.startDate);
  }, [preset, rule, draft.startDate, draft.all_day]);

  useEffect(() => {
    function onKey(event: globalThis.KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    if (tab !== "shoot" || !draft.startDate) return;
    let cancelled = false;
    api
      .photographySlots(draft.startDate)
      .then((res) => {
        if (cancelled) return;
        setSlots(res.data);
        const match = res.data.find((item) => wallClock(item.starts_at).slice(11) === draft.startTime);
        setSlot(match?.starts_at ?? "");
      })
      .catch((err) => !cancelled && toast.error(err instanceof Error ? err.message : String(err)));
    return () => {
      cancelled = true;
    };
  }, [tab, draft.startDate, draft.startTime]);

  function patch(next: Partial<Draft>) {
    setDraft((current) => {
      const merged = { ...current, ...next };
      if (next.startDate !== undefined || next.startTime !== undefined) {
        // Moving the start keeps the length, as Google Calendar does.
        const length = Math.max(minutesBetween(current.startDate, current.startTime, current.endDate, current.endTime), current.all_day ? 0 : 15);
        const end = addMinutes(merged.startDate, merged.startTime, length);
        merged.endDate = end.date;
        merged.endTime = current.all_day ? current.endTime : end.time;
      }
      if (merged.endDate < merged.startDate) merged.endDate = merged.startDate;
      return merged;
    });
  }

  function addGuest() {
    const emails = guest
      .split(/[\s,;]+/)
      .map((item) => item.trim())
      .filter((item) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(item));
    if (emails.length === 0) return;
    setDraft((current) => ({ ...current, attendees: Array.from(new Set([...current.attendees, ...emails])) }));
    setGuest("");
  }

  function onGuestKey(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Enter" || event.key === ",") {
      event.preventDefault();
      addGuest();
    }
  }

  function setReminder(index: number, next: Partial<CalendarReminder>) {
    setDraft((current) => ({
      ...current,
      reminders: {
        ...current.reminders,
        overrides: current.reminders.overrides.map((item, position) => (position === index ? { ...item, ...next } : item)),
      },
    }));
  }

  async function saveShoot() {
    if (!requestId || !slot) {
      setError(ar ? "اختر الطلب والوقت." : "Choose the request and a time.");
      return;
    }
    const res = await api.bookPhotography({
      request_id: Number(requestId),
      starts_at: wallClock(slot),
      confirm: confirmNow,
      override_lead: board?.me.can_all === true,
    });
    toast.success(res.message || (ar ? "حُفظ الموعد." : "Saved."));
  }

  async function saveEvent() {
    const when = times(draft);
    if (!draft.all_day && when.end <= when.start) {
      setError(ar ? "النهاية قبل البداية." : "The end is before the start.");
      return;
    }
    if (!editing) {
      const res = await api.createPhotographyEvent({
        calendar_id: calendar?.id ?? draft.calendar_id,
        title: draft.title,
        description: draft.description,
        location: draft.location,
        ...when,
        color_id: draft.color_id,
        attendees: draft.attendees,
        reminders: draft.reminders,
        recurrence,
        transparency: draft.transparency,
        visibility: draft.visibility,
        meet: draft.meet,
        notify: draft.notify && draft.attendees.length > 0,
      });
      toast.success(res.message || (ar ? "حُفظ الحدث." : "Saved."));
      return;
    }

    const original = draftFromRow(editing);
    const before = times(original);
    const payload: Parameters<typeof api.updatePhotographyEvent>[0] = {
      calendar_id: editing.calendar_id,
      event_id: editing.id,
      scope: recurring ? scope : undefined,
      notify: draft.notify && (draft.attendees.length > 0 || original.attendees.length > 0),
    };
    if (draft.title !== original.title) payload.title = draft.title;
    if (draft.description !== original.description) payload.description = draft.description;
    if (draft.location !== original.location) payload.location = draft.location;
    if (draft.color_id !== original.color_id) payload.color_id = draft.color_id;
    if (when.start !== before.start || when.end !== before.end || when.all_day !== before.all_day) Object.assign(payload, when);
    if ([...draft.attendees].sort().join() !== [...original.attendees].sort().join()) payload.attendees = draft.attendees;
    if (JSON.stringify(draft.reminders) !== JSON.stringify(original.reminders)) payload.reminders = draft.reminders;
    if (JSON.stringify(recurrence) !== JSON.stringify(original.recurrence) && (!recurring || scope !== "this")) payload.recurrence = recurrence;
    if (draft.transparency !== original.transparency) payload.transparency = draft.transparency;
    if (draft.visibility !== original.visibility) payload.visibility = draft.visibility;
    if (draft.meet && !original.meet) payload.meet = true;
    if (draft.calendar_id !== editing.calendar_id) payload.target_calendar_id = draft.calendar_id;
    const res = await api.updatePhotographyEvent(payload);
    toast.success(res.message || (ar ? "حُفظ التعديل." : "Saved."));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setBusy(true);
    try {
      if (tab === "shoot") await saveShoot();
      else await saveEvent();
      onSaved();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  const title = editing ? (ar ? "تعديل الحدث" : "Edit event") : tab === "shoot" ? (ar ? "موعد تصوير جديد" : "New shoot") : ar ? "حدث جديد" : "New event";
  const swatch = eventColor(draft.color_id, calendar?.color ?? null);

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="gcal-dialog-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg gcal-dialog">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="gcal-dialog-title">{title}</h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}>
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={submit}>
          <div className="form-dialog-body gcal-form">
            {showTabs ? (
              <div className="gcal-segment" role="tablist">
                <button type="button" role="tab" aria-selected={tab === "event"} className={tab === "event" ? "is-active" : ""} onClick={() => setTab("event")}>
                  <CalendarDays size={15} aria-hidden /> {ar ? "حدث" : "Event"}
                </button>
                <button type="button" role="tab" aria-selected={tab === "shoot"} className={tab === "shoot" ? "is-active" : ""} onClick={() => setTab("shoot")}>
                  <Camera size={15} aria-hidden /> {ar ? "موعد تصوير" : "Shoot"}
                </button>
              </div>
            ) : null}

            {tab === "shoot" ? (
              <>
                <label className="field-label">
                  {ar ? "الطلب" : "Request"}
                  <select className="field" value={requestId} onChange={(event) => setRequestId(event.target.value)} required>
                    <option value="">{ar ? "اختر طلباً مدفوعاً" : "Choose a paid request"}</option>
                    {(board?.unbooked ?? []).map((row) => (
                      <option key={row.id} value={row.id}>
                        {row.number} — {row.client_name} ({row.remaining}/{row.sessions})
                      </option>
                    ))}
                  </select>
                </label>
                <div className="gcal-row">
                  <label className="field-label">
                    {ar ? "اليوم" : "Day"}
                    <input className="field" type="date" value={draft.startDate} onChange={(event) => patch({ startDate: event.target.value })} required />
                  </label>
                  <label className="field-label">
                    {ar ? "الوقت" : "Time"}
                    <select className="field" value={slot} onChange={(event) => setSlot(event.target.value)} required>
                      <option value="">{slots.length ? (ar ? "اختر وقتاً" : "Choose a time") : ar ? "لا أوقات متاحة في هذا اليوم" : "No free time this day"}</option>
                      {slots.map((item) => (
                        <option key={item.starts_at} value={item.starts_at}>{item.label}</option>
                      ))}
                    </select>
                  </label>
                </div>
                {board ? (
                  <p className="muted gcal-hint">
                    {ar
                      ? `الجلسة ${board.settings.shoot_hours} ساعات، وبين البدايات ${board.settings.gap_hours} ساعات، والجمعة عطلة.`
                      : `A shoot lasts ${board.settings.shoot_hours} hours, starts sit ${board.settings.gap_hours} hours apart, and Friday is off.`}
                  </p>
                ) : null}
                <label className="checkbox-row">
                  <input type="checkbox" checked={confirmNow} onChange={(event) => setConfirmNow(event.target.checked)} />
                  {ar ? "تثبيت فوري وخصم الجلسة" : "Confirm now and charge the session"}
                </label>
              </>
            ) : (
              <>
                <input
                  className="field gcal-title-input"
                  placeholder={ar ? "أضف عنواناً" : "Add title"}
                  value={draft.title}
                  onChange={(event) => patch({ title: event.target.value })}
                  autoFocus
                  maxLength={1024}
                />

                <div className="gcal-line">
                  <Clock size={17} aria-hidden />
                  <div className="gcal-line-body">
                    <div className="gcal-row gcal-when">
                      <input className="field" type="date" value={draft.startDate} onChange={(event) => patch({ startDate: event.target.value })} required aria-label={ar ? "يوم البداية" : "Start day"} />
                      {!draft.all_day ? <input className="field" type="time" step={300} value={draft.startTime} onChange={(event) => patch({ startTime: event.target.value })} required aria-label={ar ? "وقت البداية" : "Start time"} /> : null}
                      <span aria-hidden>–</span>
                      {!draft.all_day ? <input className="field" type="time" step={300} value={draft.endTime} onChange={(event) => setDraft((current) => ({ ...current, endTime: event.target.value }))} required aria-label={ar ? "وقت النهاية" : "End time"} /> : null}
                      <input className="field" type="date" value={draft.endDate} min={draft.startDate} onChange={(event) => setDraft((current) => ({ ...current, endDate: event.target.value }))} required aria-label={ar ? "يوم النهاية" : "End day"} />
                    </div>
                    <label className="checkbox-row">
                      <input type="checkbox" checked={draft.all_day} onChange={(event) => setDraft((current) => ({ ...current, all_day: event.target.checked, transparency: event.target.checked ? "transparent" : "opaque" }))} />
                      {ar ? "طوال اليوم" : "All day"}
                    </label>
                  </div>
                </div>

                <div className="gcal-line">
                  <Repeat size={17} aria-hidden />
                  <div className="gcal-line-body">
                    <select className="field" value={preset} onChange={(event) => setPreset(event.target.value as RepeatPreset)} disabled={recurring && scope === "this"}>
                      {PRESETS.map((item) => (
                        <option key={item} value={item}>{presetLabel(item, draft.startDate, locale)}</option>
                      ))}
                    </select>
                    {preset === "custom" ? (
                      <div className="gcal-custom-rule">
                        <div className="gcal-row">
                          <span>{ar ? "كل" : "Every"}</span>
                          <input className="field gcal-num" type="number" min={1} max={99} value={rule.interval} onChange={(event) => setRule({ ...rule, interval: Math.max(1, Number(event.target.value) || 1) })} />
                          <select className="field" value={rule.freq} onChange={(event) => setRule({ ...rule, freq: event.target.value as Rule["freq"] })}>
                            <option value="DAILY">{ar ? "يوم" : "day"}</option>
                            <option value="WEEKLY">{ar ? "أسبوع" : "week"}</option>
                            <option value="MONTHLY">{ar ? "شهر" : "month"}</option>
                            <option value="YEARLY">{ar ? "سنة" : "year"}</option>
                          </select>
                        </div>
                        {rule.freq === "WEEKLY" ? (
                          <div className="gcal-days" role="group" aria-label={ar ? "أيام التكرار" : "Repeat on"}>
                            {WEEKDAYS.map((code) => (
                              <button
                                key={code}
                                type="button"
                                aria-pressed={rule.byday.includes(code)}
                                className={rule.byday.includes(code) ? "is-on" : ""}
                                title={WEEKDAY_NAMES[code][locale]}
                                onClick={() => setRule({ ...rule, byday: rule.byday.includes(code) ? rule.byday.filter((day) => day !== code) : [...rule.byday, code] })}
                              >
                                {WEEKDAY_NAMES[code][locale].slice(0, ar ? 2 : 1)}
                              </button>
                            ))}
                          </div>
                        ) : null}
                        {rule.freq === "MONTHLY" ? (
                          <select className="field" value={rule.monthly} onChange={(event) => setRule({ ...rule, monthly: event.target.value as Rule["monthly"] })}>
                            <option value="day">{presetLabel("monthly", draft.startDate, locale)}</option>
                            <option value="nth">{presetLabel("monthlyNth", draft.startDate, locale)}</option>
                          </select>
                        ) : null}
                        <div className="gcal-row">
                          <span>{ar ? "ينتهي" : "Ends"}</span>
                          <select className="field" value={rule.end} onChange={(event) => setRule({ ...rule, end: event.target.value as Rule["end"] })}>
                            <option value="never">{ar ? "أبداً" : "Never"}</option>
                            <option value="until">{ar ? "في تاريخ" : "On"}</option>
                            <option value="count">{ar ? "بعد عدد مرات" : "After"}</option>
                          </select>
                          {rule.end === "until" ? <input className="field" type="date" min={draft.startDate} value={rule.until} onChange={(event) => setRule({ ...rule, until: event.target.value })} /> : null}
                          {rule.end === "count" ? <input className="field gcal-num" type="number" min={1} max={730} value={rule.count} onChange={(event) => setRule({ ...rule, count: Math.max(1, Number(event.target.value) || 1) })} /> : null}
                        </div>
                      </div>
                    ) : null}
                  </div>
                </div>

                <div className="gcal-line">
                  <span className="gcal-dot" style={{ background: swatch }} aria-hidden />
                  <div className="gcal-line-body gcal-row">
                    <select className="field" value={draft.calendar_id} onChange={(event) => setDraft((current) => ({ ...current, calendar_id: event.target.value }))} aria-label={ar ? "التقويم" : "Calendar"}>
                      {writable.map((item) => (
                        <option key={item.id} value={item.id}>{item.summary}{item.main ? (ar ? " (التصوير)" : " (shoots)") : ""}</option>
                      ))}
                    </select>
                    <div className="gcal-swatches" role="radiogroup" aria-label={ar ? "لون الحدث" : "Event color"}>
                      <button type="button" role="radio" aria-checked={draft.color_id == null} className={draft.color_id == null ? "is-on" : ""} style={{ background: calendar?.color ?? "#2e0e5c" }} title={ar ? "لون التقويم" : "Calendar color"} onClick={() => setDraft((current) => ({ ...current, color_id: null }))} />
                      {GOOGLE_COLORS.map((color) => (
                        <button key={color.id} type="button" role="radio" aria-checked={draft.color_id === color.id} className={draft.color_id === color.id ? "is-on" : ""} style={{ background: color.hex }} title={color.name[locale]} onClick={() => setDraft((current) => ({ ...current, color_id: color.id }))} />
                      ))}
                    </div>
                  </div>
                </div>

                <div className="gcal-line">
                  <MapPin size={17} aria-hidden />
                  <input className="field gcal-line-body" placeholder={ar ? "أضف مكاناً" : "Add location"} value={draft.location} onChange={(event) => patch({ location: event.target.value })} maxLength={1024} />
                </div>

                <div className="gcal-line">
                  <Video size={17} aria-hidden />
                  <div className="gcal-line-body">
                    <label className="checkbox-row">
                      <input type="checkbox" checked={draft.meet} disabled={editing?.meet_url != null} onChange={(event) => patch({ meet: event.target.checked })} />
                      {editing?.meet_url ? (ar ? "للحدث رابط Google Meet" : "Has a Google Meet link") : ar ? "أضف رابط Google Meet" : "Add Google Meet video conferencing"}
                    </label>
                  </div>
                </div>

                <div className="gcal-line">
                  <Users size={17} aria-hidden />
                  <div className="gcal-line-body">
                    <div className="gcal-row">
                      <input className="field" type="email" placeholder={ar ? "أضف مدعوين بالبريد" : "Add guests by email"} value={guest} onChange={(event) => setGuest(event.target.value)} onKeyDown={onGuestKey} onBlur={addGuest} />
                      <button type="button" className="btn btn-ghost" onClick={addGuest}>{ar ? "أضف" : "Add"}</button>
                    </div>
                    {draft.attendees.length ? (
                      <ul className="gcal-chips">
                        {draft.attendees.map((email) => (
                          <li key={email}>
                            <span dir="ltr">{email}</span>
                            <button type="button" aria-label={ar ? `إزالة ${email}` : `Remove ${email}`} onClick={() => setDraft((current) => ({ ...current, attendees: current.attendees.filter((item) => item !== email) }))}>
                              <X size={13} aria-hidden />
                            </button>
                          </li>
                        ))}
                      </ul>
                    ) : null}
                    {draft.attendees.length ? (
                      <label className="checkbox-row">
                        <input type="checkbox" checked={draft.notify} onChange={(event) => patch({ notify: event.target.checked })} />
                        {ar ? "أرسل دعوات وتحديثات للمدعوين" : "Send invitations and updates to guests"}
                      </label>
                    ) : null}
                  </div>
                </div>

                <div className="gcal-line">
                  <Bell size={17} aria-hidden />
                  <div className="gcal-line-body">
                    <label className="checkbox-row">
                      <input type="checkbox" checked={draft.reminders.use_default} onChange={(event) => setDraft((current) => ({ ...current, reminders: { use_default: event.target.checked, overrides: event.target.checked ? [] : current.reminders.overrides.length ? current.reminders.overrides : [{ method: "popup", minutes: 30 }] } }))} />
                      {ar ? "تنبيهات التقويم الافتراضية" : "Calendar default notifications"}
                    </label>
                    {!draft.reminders.use_default ? (
                      <>
                        {draft.reminders.overrides.map((reminder, index) => {
                          const { amount, unit } = splitMinutes(reminder.minutes);
                          return (
                            <div className="gcal-row" key={index}>
                              <select className="field" value={reminder.method} onChange={(event) => setReminder(index, { method: event.target.value as CalendarReminder["method"] })}>
                                <option value="popup">{ar ? "إشعار" : "Notification"}</option>
                                <option value="email">{ar ? "بريد" : "Email"}</option>
                              </select>
                              <input className="field gcal-num" type="number" min={0} max={40320} value={amount} onChange={(event) => setReminder(index, { minutes: Math.max(0, Number(event.target.value) || 0) * unit })} />
                              <select className="field" value={unit} onChange={(event) => setReminder(index, { minutes: amount * Number(event.target.value) })}>
                                {REMINDER_UNITS.map((item) => <option key={item.id} value={item.id}>{item.label[locale]}</option>)}
                              </select>
                              <span className="muted">{ar ? "قبل" : "before"}</span>
                              <button type="button" className="btn btn-ghost gcal-icon-btn" aria-label={ar ? "حذف التنبيه" : "Remove notification"} onClick={() => setDraft((current) => ({ ...current, reminders: { ...current.reminders, overrides: current.reminders.overrides.filter((_, position) => position !== index) } }))}>
                                <X size={14} aria-hidden />
                              </button>
                            </div>
                          );
                        })}
                        {draft.reminders.overrides.length < 5 ? (
                          <button type="button" className="btn btn-ghost" onClick={() => setDraft((current) => ({ ...current, reminders: { ...current.reminders, overrides: [...current.reminders.overrides, { method: "popup", minutes: 10 }] } }))}>
                            <Plus size={14} aria-hidden /> {ar ? "أضف تنبيهاً" : "Add notification"}
                          </button>
                        ) : null}
                      </>
                    ) : null}
                  </div>
                </div>

                <div className="gcal-line">
                  <Lock size={17} aria-hidden />
                  <div className="gcal-line-body gcal-row">
                    <select className="field" value={draft.transparency} onChange={(event) => patch({ transparency: event.target.value as Draft["transparency"] })} aria-label={ar ? "التوفر" : "Availability"}>
                      <option value="opaque">{ar ? "مشغول" : "Busy"}</option>
                      <option value="transparent">{ar ? "متاح" : "Free"}</option>
                    </select>
                    <select className="field" value={draft.visibility} onChange={(event) => patch({ visibility: event.target.value as Draft["visibility"] })} aria-label={ar ? "الخصوصية" : "Visibility"}>
                      <option value="default">{ar ? "خصوصية التقويم الافتراضية" : "Default visibility"}</option>
                      <option value="public">{ar ? "عام" : "Public"}</option>
                      <option value="private">{ar ? "خاص" : "Private"}</option>
                    </select>
                  </div>
                </div>

                <div className="gcal-line">
                  <TextAlignStart size={17} aria-hidden />
                  <textarea className="field gcal-line-body" rows={4} placeholder={ar ? "أضف وصفاً" : "Add description"} value={draft.description} onChange={(event) => patch({ description: event.target.value })} maxLength={8000} />
                </div>

                {recurring ? (
                  <fieldset className="gcal-scope">
                    <legend>{ar ? "تطبيق التعديل على" : "Apply the change to"}</legend>
                    {(["this", "following", "all"] as CalendarScope[]).map((item) => (
                      <label key={item} className="checkbox-row">
                        <input type="radio" name="scope" checked={scope === item} onChange={() => setScope(item)} />
                        {scopeLabel(item, locale)}
                      </label>
                    ))}
                  </fieldset>
                ) : null}
              </>
            )}
          </div>
          {error ? <p className="error">{error}</p> : null}
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={busy || (tab === "event" && !canEvent)}>
              {busy ? (ar ? "جارٍ الحفظ…" : "Saving…") : ar ? "حفظ" : "Save"}
            </button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>{ar ? "إلغاء" : "Cancel"}</button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function scopeLabel(scope: CalendarScope, locale: Locale) {
  const labels: Record<CalendarScope, { ar: string; en: string }> = {
    this: { ar: "هذا الحدث فقط", en: "This event" },
    following: { ar: "هذا الحدث والأحداث التالية", en: "This and following events" },
    all: { ar: "كل الأحداث", en: "All events" },
  };
  return labels[scope][locale];
}
