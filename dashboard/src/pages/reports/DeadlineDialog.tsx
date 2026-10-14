import { Building, CalendarDays, Clock, Repeat, StickyNote, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { api, type Client, type ReportCalendar, type ReportDeadlinePayload, type ReportDeadlineRow, type ReportRepeat } from "../../api";
import type { Locale } from "../../i18n";
import { GOOGLE_COLORS } from "../photography/model";
import { REPEATS, REPEAT_COPY, clientLabel } from "./model";

export type DeadlineDraft = {
  id?: number;
  client: { id: number; name: string; company_name: string | null } | null;
  /** Null is the main calendar. */
  calendar_id: number | null;
  title: string;
  starts_on: string;
  all_day: boolean;
  due_time: string;
  repeat: ReportRepeat;
  repeat_until: string;
  color: string | null;
  notes: string;
};

export function emptyDeadline(date: string, time: string | null, client: DeadlineDraft["client"], calendarId: number | null = null): DeadlineDraft {
  return { client, calendar_id: calendarId, title: "", starts_on: date, all_day: time == null, due_time: time ?? "12:00", repeat: "monthly", repeat_until: "", color: null, notes: "" };
}

export function draftFromDeadline(row: ReportDeadlineRow): DeadlineDraft {
  return {
    id: row.deadline_id,
    client: row.client,
    calendar_id: row.calendar_id,
    title: row.title,
    starts_on: row.starts_on,
    all_day: row.due_time == null,
    due_time: row.due_time ?? "12:00",
    repeat: row.repeat,
    repeat_until: row.repeat_until ?? "",
    color: row.color,
    notes: row.notes ?? "",
  };
}

export function deadlinePayload(draft: DeadlineDraft): ReportDeadlinePayload {
  return {
    client_id: draft.client?.id ?? 0,
    report_calendar_id: draft.calendar_id,
    title: draft.title.trim(),
    starts_on: draft.starts_on,
    due_time: draft.all_day ? null : draft.due_time,
    repeat: draft.repeat,
    repeat_until: draft.repeat === "none" || !draft.repeat_until ? null : draft.repeat_until,
    color: draft.color,
    notes: draft.notes.trim() || null,
  };
}

/** Create or edit a report deadline. Editing changes every date of the series. */
export function DeadlineDialog({
  locale,
  initial,
  fixedClient,
  calendars,
  mainVisible = true,
  onClose,
  onSaved,
}: {
  locale: Locale;
  initial: DeadlineDraft;
  fixedClient: boolean;
  /** Extra calendars this staff member sees. */
  calendars: ReportCalendar[];
  /** Whether the main calendar is offered. */
  mainVisible?: boolean;
  onClose: () => void;
  onSaved: () => void;
}) {
  const ar = locale === "ar";
  const [draft, setDraft] = useState(() =>
    !mainVisible && initial.calendar_id == null && calendars.length > 0 ? { ...initial, calendar_id: calendars[0].id } : initial,
  );
  const [saving, setSaving] = useState(false);
  const editing = initial.id != null;

  function patch(change: Partial<DeadlineDraft>) {
    setDraft((current) => ({ ...current, ...change }));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!draft.client) {
      toast.error(ar ? "اختر الزبون." : "Choose the client.");
      return;
    }
    setSaving(true);
    try {
      const payload = deadlinePayload(draft);
      if (draft.id != null) await api.updateReportDeadline(draft.id, payload);
      else await api.createReportDeadline(payload);
      toast.success(ar ? "حُفظ الموعد." : "Deadline saved.");
      onSaved();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-dialog-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg gcal-dialog">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="rc-dialog-title">
            {editing ? (ar ? "تعديل موعد التقرير" : "Edit report deadline") : ar ? "موعد تقرير جديد" : "New report deadline"}
          </h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}>
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={submit}>
          <div className="form-dialog-body gcal-form">
            <input
              className="field gcal-title-input"
              placeholder={ar ? "مثال: التقرير الشهري للسوشال ميديا" : "e.g. Monthly social media report"}
              value={draft.title}
              onChange={(event) => patch({ title: event.target.value })}
              autoFocus
              required
              maxLength={160}
            />

            <div className="gcal-line">
              <Building size={17} aria-hidden />
              <div className="gcal-line-body">
                {fixedClient && draft.client ? (
                  <strong>{clientLabel(draft.client)}</strong>
                ) : (
                  <ClientPicker locale={locale} value={draft.client} onChange={(client) => patch({ client })} />
                )}
              </div>
            </div>

            {calendars.length > 1 || (calendars.length > 0 && mainVisible) ? (
              <div className="gcal-line">
                <CalendarDays size={17} aria-hidden />
                <div className="gcal-line-body">
                  <select
                    className="field"
                    value={draft.calendar_id ?? ""}
                    onChange={(event) => patch({ calendar_id: event.target.value ? Number(event.target.value) : null })}
                    aria-label={ar ? "التقويم" : "Calendar"}
                  >
                    {mainVisible ? <option value="">{ar ? "التقويم الرئيسي" : "Main calendar"}</option> : null}
                    {calendars.map((calendar) => (
                      <option key={calendar.id} value={calendar.id}>{calendar.name}</option>
                    ))}
                  </select>
                </div>
              </div>
            ) : null}

            <div className="gcal-line">
              <Clock size={17} aria-hidden />
              <div className="gcal-line-body">
                <div className="gcal-row gcal-when">
                  <input className="field" type="date" value={draft.starts_on} onChange={(event) => patch({ starts_on: event.target.value })} required aria-label={ar ? "يوم التسليم" : "Due day"} />
                  {!draft.all_day ? <input className="field" type="time" step={300} value={draft.due_time} onChange={(event) => patch({ due_time: event.target.value })} required aria-label={ar ? "وقت التسليم" : "Due time"} /> : null}
                </div>
                <label className="checkbox-row">
                  <input type="checkbox" checked={draft.all_day} onChange={(event) => patch({ all_day: event.target.checked })} />
                  {ar ? "طوال اليوم" : "All day"}
                </label>
              </div>
            </div>

            <div className="gcal-line">
              <Repeat size={17} aria-hidden />
              <div className="gcal-line-body">
                <div className="gcal-row">
                  <select className="field" value={draft.repeat} onChange={(event) => patch({ repeat: event.target.value as ReportRepeat })} aria-label={ar ? "التكرار" : "Repeat"}>
                    {REPEATS.map((item) => (
                      <option key={item} value={item}>{REPEAT_COPY[item][locale]}</option>
                    ))}
                  </select>
                  {draft.repeat !== "none" ? (
                    <label className="field-label">
                      {ar ? "حتى (اختياري)" : "Until (optional)"}
                      <input className="field" type="date" min={draft.starts_on} value={draft.repeat_until} onChange={(event) => patch({ repeat_until: event.target.value })} />
                    </label>
                  ) : null}
                </div>
                {editing && draft.repeat !== "none" ? (
                  <p className="muted gcal-hint">{ar ? "التعديل هنا يغيّر كل مواعيد السلسلة. لتغيير موعد واحد اسحبه في التقويم." : "Changes here apply to the whole series. Drag a single date on the calendar to move only that one."}</p>
                ) : null}
              </div>
            </div>

            <div className="gcal-line">
              <span className="gcal-dot" style={{ background: draft.color ?? "#f0b56a" }} aria-hidden />
              <div className="gcal-line-body">
                <div className="gcal-swatches" role="radiogroup" aria-label={ar ? "اللون" : "Color"}>
                  <button type="button" role="radio" aria-checked={draft.color == null} className={draft.color == null ? "is-on" : ""} style={{ background: "#f0b56a" }} onClick={() => patch({ color: null })} title={ar ? "حسب الحالة" : "By status"} />
                  {GOOGLE_COLORS.map((color) => (
                    <button key={color.id} type="button" role="radio" aria-checked={draft.color === color.hex} className={draft.color === color.hex ? "is-on" : ""} style={{ background: color.hex }} onClick={() => patch({ color: color.hex })} title={color.name[locale]} />
                  ))}
                </div>
              </div>
            </div>

            <div className="gcal-line">
              <StickyNote size={17} aria-hidden />
              <div className="gcal-line-body">
                <textarea className="field" rows={3} maxLength={2000} placeholder={ar ? "ملاحظات: ما يجب أن يحتويه التقرير" : "Notes: what the report should cover"} value={draft.notes} onChange={(event) => patch({ notes: event.target.value })} />
              </div>
            </div>
          </div>
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={saving}>{ar ? "حفظ" : "Save"}</button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>{ar ? "إلغاء" : "Cancel"}</button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ClientPicker({ locale, value, onChange }: { locale: Locale; value: DeadlineDraft["client"]; onChange: (client: DeadlineDraft["client"]) => void }) {
  const ar = locale === "ar";
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<Client[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const timer = window.setTimeout(() => {
      api
        .reportClients(1, { search: query.trim() })
        .then((res) => {
          if (!cancelled) setResults(res.data);
        })
        .catch(() => undefined);
    }, 250);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [query, open]);

  if (value && !open) {
    return (
      <div className="rc-client-chosen">
        <strong>{clientLabel(value)}</strong>
        {value.company_name ? <small className="muted">{value.name}</small> : null}
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setOpen(true)}>{ar ? "تغيير" : "Change"}</button>
      </div>
    );
  }

  return (
    <div className="rc-client-picker">
      <input
        className="field"
        type="search"
        placeholder={ar ? "ابحث عن الزبون بالاسم أو الشركة أو الهاتف" : "Search the client by name, company, or phone"}
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        onFocus={() => setOpen(true)}
        aria-label={ar ? "الزبون" : "Client"}
        autoFocus={value != null}
      />
      {open ? (
        <ul className="rc-client-results" role="listbox">
          {results.length === 0 ? <li className="muted">{ar ? "لا نتائج" : "No results"}</li> : null}
          {results.map((client) => (
            <li key={client.id}>
              <button
                type="button"
                role="option"
                aria-selected={value?.id === client.id}
                onClick={() => {
                  onChange({ id: client.id, name: client.name, company_name: client.company_name ?? null });
                  setOpen(false);
                  setQuery("");
                }}
              >
                <strong>{client.company_name || client.name}</strong>
                {client.company_name ? <small className="muted">{client.name}</small> : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
