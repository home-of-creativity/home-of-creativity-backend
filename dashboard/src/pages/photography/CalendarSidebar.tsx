import { Camera, Copy, Eye, EyeOff, Lock, Plus, TriangleAlert, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import { toast } from "sonner";
import { api, type CalendarDirectory, type PhotographyBoard, type PhotographyRequestRow } from "../../api";
import type { Locale } from "../../i18n";
import { DEFAULT_CALENDAR_COLOR, TONE_COLORS, intlTag, type Layer, type Prefs } from "./model";

const LAYERS: Array<{ id: Layer; label: { ar: string; en: string }; color: string }> = [
  { id: "queue", label: { ar: "بانتظار المصور", en: "Photographer queue" }, color: TONE_COLORS.queue },
  { id: "waiting", label: { ar: "بانتظار العميل", en: "Waiting on client" }, color: TONE_COLORS.waiting },
  { id: "agreed", label: { ar: "مثبتة", en: "Confirmed" }, color: TONE_COLORS.agreed },
  { id: "finished", label: { ar: "منتهية (تم / لم يحضر)", en: "Finished (done / no show)" }, color: TONE_COLORS.done },
];

export function CalendarSidebar({
  locale,
  prefs,
  setPrefs,
  directory,
  errors,
  board,
  current,
  counts,
  canCreate,
  onPickDate,
  onCreate,
  onBook,
  onDirectoryChanged,
  onBoardChanged,
}: {
  locale: Locale;
  prefs: Prefs;
  setPrefs: (next: Prefs) => void;
  directory: CalendarDirectory | null;
  errors: Record<string, string>;
  board: PhotographyBoard | null;
  current: Date;
  counts: Record<Layer, number>;
  canCreate: boolean;
  onPickDate: (date: Date) => void;
  onCreate: () => void;
  onBook: (row: PhotographyRequestRow) => void;
  onDirectoryChanged: () => void;
  onBoardChanged: () => void;
}) {
  const ar = locale === "ar";
  const [month, setMonth] = useState(current);
  const [adding, setAdding] = useState(false);
  const [calendarId, setCalendarId] = useState("");
  const [busy, setBusy] = useState("");
  const [caps, setCaps] = useState<Record<number, string>>({});
  const tag = intlTag(locale);

  useEffect(() => setMonth(current), [current]);

  useEffect(() => {
    setCaps(Object.fromEntries((board?.needs_count ?? []).map((row) => [row.id, String(row.sessions)])));
  }, [board]);

  function toggleCalendar(id: string) {
    setPrefs({ ...prefs, hidden: prefs.hidden.includes(id) ? prefs.hidden.filter((item) => item !== id) : [...prefs.hidden, id] });
  }

  function toggleLayer(id: Layer) {
    setPrefs({ ...prefs, layers: prefs.layers.includes(id) ? prefs.layers.filter((item) => item !== id) : [...prefs.layers, id] });
  }

  async function addCalendar(event: FormEvent) {
    event.preventDefault();
    if (!calendarId.trim()) return;
    setBusy("add");
    try {
      const res = await api.addPhotographyCalendar(calendarId.trim());
      toast.success(res.message || (ar ? "أُضيف التقويم." : "Calendar added."));
      setCalendarId("");
      setAdding(false);
      onDirectoryChanged();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy("");
    }
  }

  async function removeCalendar(id: string) {
    setBusy(`remove:${id}`);
    try {
      const res = await api.removePhotographyCalendar(id);
      toast.success(res.message);
      onDirectoryChanged();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy("");
    }
  }

  async function saveCap(row: PhotographyRequestRow) {
    const sessions = Number(caps[row.id]);
    if (!Number.isInteger(sessions) || sessions < 0 || sessions > 100) {
      toast.error(ar ? "عدد الجلسات من 0 إلى 100." : "Sessions must be from 0 to 100.");
      return;
    }
    setBusy(`cap:${row.id}`);
    try {
      const res = await api.setPhotographySessions(row.id, sessions);
      toast.success(res.message || (ar ? "حُفظ." : "Saved."));
      onBoardChanged();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy("");
    }
  }

  function copyEmail() {
    if (!directory?.service_email) return;
    navigator.clipboard
      ?.writeText(directory.service_email)
      .then(() => toast.success(ar ? "نُسخ البريد." : "Email copied."))
      .catch(() => undefined);
  }

  return (
    <aside className="gcal-side" aria-label={ar ? "لوحة التقويم" : "Calendar panel"}>
      {canCreate ? (
        <button type="button" className="gcal-create" onClick={onCreate}>
          <Plus size={20} aria-hidden />
          <span>{ar ? "إنشاء" : "Create"}</span>
          <kbd>C</kbd>
        </button>
      ) : null}

      <DayPicker
        className="gcal-mini"
        mode="single"
        selected={current}
        month={month}
        onMonthChange={setMonth}
        onSelect={(date) => date && onPickDate(date)}
        weekStartsOn={prefs.firstDay}
        showOutsideDays
        dir={ar ? "rtl" : "ltr"}
        formatters={{
          formatCaption: (value) => new Intl.DateTimeFormat(tag, { month: "long", year: "numeric" }).format(value),
          formatWeekdayName: (value) => new Intl.DateTimeFormat(tag, { weekday: "narrow" }).format(value),
          formatDay: (value) => String(value.getDate()),
        }}
      />

      <section className="gcal-side-section">
        <div className="gcal-side-head">
          <h2>{ar ? "التقاويم" : "Calendars"}</h2>
          {directory?.can_manage && directory.configured ? (
            <button type="button" className="gcal-icon-btn" onClick={() => setAdding((value) => !value)} aria-expanded={adding} title={ar ? "أضف تقويماً" : "Add a calendar"} aria-label={ar ? "أضف تقويماً" : "Add a calendar"}>
              {adding ? <X size={16} aria-hidden /> : <Plus size={16} aria-hidden />}
            </button>
          ) : null}
        </div>

        {adding ? (
          <form className="gcal-add" onSubmit={addCalendar}>
            <p className="muted">
              {ar ? "من إعدادات التقويم في جوجل: شاركه مع هذا البريد بصلاحية «إجراء تغييرات على الأحداث»، ثم الصق معرّف التقويم." : "In the calendar's Google settings, share it with this address with “Make changes to events”, then paste the calendar ID."}
            </p>
            {directory?.service_email ? (
              <button type="button" className="gcal-email" onClick={copyEmail} dir="ltr">
                <Copy size={13} aria-hidden /> {directory.service_email}
              </button>
            ) : null}
            <input className="field" dir="ltr" placeholder="xxxx@group.calendar.google.com" value={calendarId} onChange={(event) => setCalendarId(event.target.value)} required />
            <button type="submit" className="btn btn-primary" disabled={busy === "add"}>{ar ? "أضف التقويم" : "Add calendar"}</button>
          </form>
        ) : null}

        <ul className="gcal-cal-list">
          <li>
            <label className="gcal-cal">
              <input type="checkbox" checked={prefs.showBookings} onChange={() => setPrefs({ ...prefs, showBookings: !prefs.showBookings })} style={{ accentColor: "#e7993a" }} />
              <Camera size={14} aria-hidden />
              <span>{ar ? "مواعيد التصوير" : "Photography shoots"}</span>
            </label>
          </li>
          {(directory?.calendars ?? []).map((calendar) => {
            const visible = !prefs.hidden.includes(calendar.id);
            return (
              <li key={calendar.id}>
                <label className="gcal-cal" title={calendar.id}>
                  <input type="checkbox" checked={visible} onChange={() => toggleCalendar(calendar.id)} style={{ accentColor: calendar.color ?? DEFAULT_CALENDAR_COLOR }} />
                  {visible ? <Eye size={14} aria-hidden /> : <EyeOff size={14} aria-hidden />}
                  <span>{calendar.summary}{calendar.main ? (ar ? " · رئيسي" : " · main") : ""}</span>
                  {!calendar.writable ? <Lock size={12} aria-label={ar ? "قراءة فقط" : "Read only"} /> : null}
                  {errors[calendar.id] ? <TriangleAlert size={13} className="is-warn" aria-label={errors[calendar.id]} /> : null}
                </label>
                {directory?.can_manage && !calendar.main ? (
                  <button type="button" className="gcal-icon-btn gcal-cal-remove" disabled={busy === `remove:${calendar.id}`} onClick={() => removeCalendar(calendar.id)} title={ar ? "أزل من القائمة" : "Remove from list"} aria-label={ar ? `أزل ${calendar.summary}` : `Remove ${calendar.summary}`}>
                    <X size={13} aria-hidden />
                  </button>
                ) : null}
              </li>
            );
          })}
        </ul>
        {directory && !directory.configured ? <p className="muted">{ar ? "جوجل كالندر غير مضبوط على السيرفر، فتظهر مواعيد التصوير فقط." : "Google Calendar is not configured on the server, so only shoots show."}</p> : null}
      </section>

      {prefs.showBookings ? (
        <section className="gcal-side-section">
          <h2>{ar ? "طبقات التصوير" : "Shoot layers"}</h2>
          <ul className="gcal-cal-list">
            {LAYERS.map((layer) => (
              <li key={layer.id}>
                <label className="gcal-cal">
                  <input type="checkbox" checked={prefs.layers.includes(layer.id)} onChange={() => toggleLayer(layer.id)} style={{ accentColor: layer.color }} />
                  <span className="gcal-swatch" style={{ background: layer.color }} aria-hidden />
                  <span>{layer.label[locale]}</span>
                  <small className="muted">{counts[layer.id]}</small>
                </label>
              </li>
            ))}
            <li>
              <label className="gcal-cal">
                <input type="checkbox" checked={prefs.showClosed} onChange={() => setPrefs({ ...prefs, showClosed: !prefs.showClosed })} />
                <span className="gcal-swatch" style={{ background: TONE_COLORS.closed }} aria-hidden />
                <span>{ar ? "الملغاة والمرفوضة" : "Cancelled and declined"}</span>
              </label>
            </li>
            {board?.me.employee_id != null ? (
              <li>
                <label className="gcal-cal">
                  <input type="checkbox" checked={prefs.mineOnly} onChange={() => setPrefs({ ...prefs, mineOnly: !prefs.mineOnly })} />
                  <span className="gcal-swatch" style={{ background: TONE_COLORS.mine }} aria-hidden />
                  <span>{ar ? "مواعيدي فقط" : "Only my shoots"}</span>
                </label>
              </li>
            ) : null}
          </ul>
        </section>
      ) : null}

      {board ? (
        <details className="gcal-side-section" open={board.unbooked.length > 0 && board.unbooked.length < 8}>
          <summary>
            <h2>{ar ? `بدون موعد (${board.unbooked.length})` : `Unbooked (${board.unbooked.length})`}</h2>
          </summary>
          {board.unbooked.length === 0 ? <p className="muted">{ar ? "كل الطلبات المدفوعة لها موعد." : "Every paid request has a shoot."}</p> : null}
          <ul className="gcal-unbooked">
            {board.unbooked.map((row) => (
              <li key={row.id}>
                <div>
                  <strong>{row.client_name ?? row.number}</strong>
                  <small className="muted">{row.number} · {row.remaining}/{row.sessions}</small>
                </div>
                {board.me.can_all || board.me.is_photographer ? (
                  <button type="button" className="btn btn-ghost" onClick={() => onBook(row)}>{ar ? "احجز" : "Book"}</button>
                ) : null}
              </li>
            ))}
          </ul>
        </details>
      ) : null}

      {board?.me.can_all ? (
        <details className="gcal-side-section" open={board.needs_count.length > 0}>
          <summary>
            <h2>{ar ? `عدد الجلسات (${board.needs_count.length})` : `Session count (${board.needs_count.length})`}</h2>
          </summary>
          {board.needs_count.length === 0 ? <p className="muted">{ar ? "كل الطلبات المؤهلة لها عدد جلسات." : "Every eligible request has a session count."}</p> : null}
          {board.needs_count.map((row) => (
            <div className="gcal-cap" key={row.id}>
              <div>
                <strong>{row.number}</strong>
                <small className="muted">{row.client_name}</small>
              </div>
              <input className="field gcal-num" type="number" min={0} max={100} value={caps[row.id] ?? ""} onChange={(event) => setCaps((value) => ({ ...value, [row.id]: event.target.value }))} aria-label={ar ? `عدد جلسات ${row.number}` : `Sessions for ${row.number}`} />
              <button type="button" className="btn btn-primary" disabled={busy === `cap:${row.id}`} onClick={() => saveCap(row)}>{ar ? "حفظ" : "Save"}</button>
            </div>
          ))}
        </details>
      ) : null}
    </aside>
  );
}
