import FullCalendar, {
  useCalendarController,
  type CalendarRef,
  type DateSelectInfo,
  type DatesSetInfo,
  type EventClickInfo,
  type EventDropInfo,
  type EventInput,
} from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/react/daygrid";
import interactionPlugin from "@fullcalendar/react/interaction";
import listPlugin from "@fullcalendar/react/list";
import arLocale from "@fullcalendar/react/locales/ar";
import enGbLocale from "@fullcalendar/react/locales/en-gb";
import multiMonthPlugin from "@fullcalendar/react/multimonth";
import classicTheme from "@fullcalendar/react/themes/classic";
import timeGridPlugin from "@fullcalendar/react/timegrid";
import "@fullcalendar/react/skeleton.css";
import "@fullcalendar/react/themes/classic/theme.css";
import "@fullcalendar/react/themes/classic/palette.css";
import * as Popover from "@radix-ui/react-popover";
import {
  AlarmClock,
  Building,
  CalendarClock,
  Check,
  ChevronLeft,
  ChevronRight,
  CircleCheck,
  FilePlus,
  FileText,
  Menu,
  Pencil,
  Plus,
  RefreshCw,
  Repeat,
  Search,
  SkipForward,
  StickyNote,
  Trash,
  Undo2,
  User,
  X,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, type ClientReport, type ReportDeadlineRow, type ReportDeadlineStatus, type ReportVersion, type ReportVersionEvent } from "../../api";
import type { Locale } from "../../i18n";
import { useCan } from "../../lib/useCan";
import { VIEWS, ZONE, addDays, contrast, formatRangeText, intlTag, zoned, type ViewId } from "../photography/model";
import { DeadlineDialog, deadlinePayload, draftFromDeadline, emptyDeadline, type DeadlineDraft } from "./DeadlineDialog";
import { EVENT_COLORS, EVENT_COPY, REPEAT_COPY, STATUS_COLORS, STATUS_COPY, clientLabel, stamp } from "./model";

type ActivityLayer = "edits" | "publishes" | "deletes";

type Prefs = {
  view: ViewId;
  firstDay: 0 | 1 | 6;
  statuses: ReportDeadlineStatus[];
  activity: ActivityLayer[];
};

const DEFAULT_PREFS: Prefs = {
  view: "dayGridMonth",
  firstDay: 6,
  statuses: ["upcoming", "overdue", "done", "skipped"],
  activity: ["edits", "publishes"],
};

const PREFS_KEY = "hoc:report-cal:prefs";

function loadPrefs(): Prefs {
  try {
    const raw = window.localStorage.getItem(PREFS_KEY);
    if (!raw) return DEFAULT_PREFS;
    const parsed = JSON.parse(raw) as Partial<Prefs>;
    return { ...DEFAULT_PREFS, ...parsed, view: VIEWS.some((view) => view.id === parsed.view) ? (parsed.view as ViewId) : DEFAULT_PREFS.view };
  } catch {
    return DEFAULT_PREFS;
  }
}

function savePrefs(prefs: Prefs) {
  try {
    window.localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
  } catch {
    // Private windows refuse storage; the calendar still works with defaults.
  }
}

const LAYER_EVENTS: Record<ActivityLayer, ReportVersionEvent[]> = {
  edits: ["created", "edited", "restored"],
  publishes: ["published"],
  deletes: ["deleted"],
};

const LAYER_COPY: Record<ActivityLayer, { ar: string; en: string }> = {
  edits: { ar: "جلسات التعديل", en: "Editing sessions" },
  publishes: { ar: "النشر على Drive", en: "Published to Drive" },
  deletes: { ar: "التقارير المحذوفة", en: "Deleted reports" },
};

const STATUSES: ReportDeadlineStatus[] = ["upcoming", "overdue", "done", "skipped"];

const plugins = [classicTheme, dayGridPlugin, timeGridPlugin, listPlugin, multiMonthPlugin, interactionPlugin];
const views = { timeGridFourDay: { type: "timeGrid", duration: { days: 4 } } };
const CLOCK = { hour: "2-digit", minute: "2-digit", hour12: false } as const;
const QUARTER_HOUR = 15 * 60_000;

type Move = { row: ReportDeadlineRow; date: string; time: string | null; revert: () => void };

function message(err: unknown) {
  return err instanceof Error ? err.message : String(err);
}

function deadlineColor(row: ReportDeadlineRow) {
  if (row.status === "upcoming") return row.color ?? STATUS_COLORS.upcoming;
  return STATUS_COLORS[row.status];
}

/**
 * Google Calendar style planner for client reports: due dates (one-off or repeating, each date
 * can be done, skipped, or moved) beside the editing sessions and publishes from the history.
 */
export function ReportsCalendar({ locale, client }: { locale: Locale; client?: { id: number; name: string; company_name: string | null } }) {
  const ar = locale === "ar";
  const can = useCan();
  const canCreate = can("ops.reports.create");
  const canUpdate = can("ops.reports.update");
  const canDelete = can("ops.reports.delete");
  const [prefs, setPrefsState] = useState<Prefs>(loadPrefs);
  const [range, setRange] = useState<{ from: string; to: string } | null>(null);
  const [deadlines, setDeadlines] = useState<ReportDeadlineRow[]>([]);
  const [activity, setActivity] = useState<ReportVersion[]>([]);
  const [loading, setLoading] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [current, setCurrent] = useState(() => new Date());
  const [month, setMonth] = useState(() => new Date());
  const [filter, setFilter] = useState("");
  const [selected, setSelected] = useState<{ key: string; rect: DOMRect } | null>(null);
  const [dialog, setDialog] = useState<DeadlineDraft | null>(null);
  const [move, setMove] = useState<Move | null>(null);
  const [sideOpen, setSideOpen] = useState(false);
  const calendarRef = useRef<CalendarRef>(null);
  const controller = useCalendarController();

  const updatePrefs = useCallback((change: (value: Prefs) => Prefs) => {
    setPrefsState((value) => {
      const next = change(value);
      savePrefs(next);
      return next;
    });
  }, []);

  const reload = useCallback(() => setReloadKey((value) => value + 1), []);

  useEffect(() => {
    if (!range) return;
    let cancelled = false;
    setLoading(true);
    api
      .reportCalendar(range.from, range.to, client?.id)
      .then((res) => {
        if (cancelled) return;
        setDeadlines(res.data.deadlines);
        setActivity(res.data.activity);
      })
      .catch((err) => {
        if (!cancelled) toast.error(message(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [range, client?.id, reloadKey]);

  useEffect(() => setMonth(current), [current]);

  const needle = filter.trim().toLowerCase();
  const matches = useCallback(
    (values: Array<string | null | undefined>) => !needle || values.some((value) => value?.toLowerCase().includes(needle)),
    [needle],
  );

  const counts = useMemo(() => {
    const result: Record<ReportDeadlineStatus, number> = { upcoming: 0, overdue: 0, done: 0, skipped: 0 };
    for (const row of deadlines) result[row.status] += 1;
    return result;
  }, [deadlines]);

  const fcEvents = useMemo<EventInput[]>(() => {
    const list: EventInput[] = [];
    for (const row of deadlines) {
      if (!prefs.statuses.includes(row.status)) continue;
      if (!matches([row.title, row.client?.name, row.client?.company_name])) continue;
      const color = deadlineColor(row);
      const name = client ? row.title : `${clientLabel(row.client)} · ${row.title}`;
      list.push({
        id: `d:${row.key}`,
        title: row.status === "done" ? `✓ ${name}` : name,
        start: row.start,
        end: row.all_day ? undefined : new Date(new Date(row.start).getTime() + 2 * QUARTER_HOUR).toISOString(),
        allDay: row.all_day,
        color,
        contrastColor: contrast(color),
        editable: canUpdate && row.status !== "done",
        durationEditable: false,
        className: `gcal-ev rc-deadline is-${row.status}`,
      });
    }
    const shown = new Set(prefs.activity.flatMap((layer) => LAYER_EVENTS[layer]));
    for (const row of activity) {
      if (!shown.has(row.event) || !row.started_at) continue;
      if (!matches([row.title, row.report_title, row.user?.name, row.client?.name, row.client?.company_name])) continue;
      const start = new Date(row.started_at).getTime();
      const end = Math.max(new Date(row.ended_at ?? row.started_at).getTime(), start + QUARTER_HOUR);
      const who = row.user?.name ?? (ar ? "النظام" : "System");
      list.push({
        id: `a:${row.id}`,
        title: `${who} · ${EVENT_COPY[row.event][locale]} · ${row.report_title ?? row.title}`,
        start: new Date(start).toISOString(),
        end: new Date(end).toISOString(),
        color: EVENT_COLORS[row.event],
        contrastColor: "#1a1224",
        editable: false,
        className: `gcal-ev rc-activity is-${row.event}`,
      });
    }
    return list;
  }, [deadlines, activity, prefs, matches, client, ar, locale, canUpdate]);

  const selectedDeadline = selected?.key.startsWith("d:") ? deadlines.find((row) => `d:${row.key}` === selected.key) ?? null : null;
  const selectedActivity = selected?.key.startsWith("a:") ? activity.find((row) => `a:${row.id}` === selected.key) ?? null : null;

  function onDatesSet(info: DatesSetInfo) {
    setRange((value) => (value?.from === info.startStr && value?.to === info.endStr ? value : { from: info.startStr, to: info.endStr }));
    const date = controller.getDate() ?? info.start;
    setCurrent((value) => (value.getTime() === date.getTime() ? value : date));
    const type = info.view.type as ViewId;
    if (VIEWS.some((view) => view.id === type)) updatePrefs((value) => (value.view === type ? value : { ...value, view: type }));
  }

  function openCreate(date: string, time: string | null) {
    setSelected(null);
    setDialog(emptyDeadline(date, time, client ?? null));
  }

  function onSelect(info: DateSelectInfo) {
    calendarRef.current?.getApi().unselect();
    const { date, time } = zoned(info.start);
    openCreate(info.allDay ? info.startStr.slice(0, 10) : date, info.allDay ? null : time);
  }

  function onEventClick(info: EventClickInfo) {
    info.jsEvent.preventDefault();
    setSelected({ key: info.event.id, rect: info.el.getBoundingClientRect() });
  }

  function onEventDrop(info: EventDropInfo) {
    const { event } = info;
    const row = deadlines.find((item) => `d:${item.key}` === event.id);
    if (!row || !event.start) {
      info.revert();
      return;
    }
    const date = event.allDay ? event.startStr.slice(0, 10) : zoned(event.start).date;
    const time = event.allDay ? null : zoned(event.start).time;
    if (row.repeat === "none") {
      void saveSeries(row, date, time, info.revert);
      return;
    }
    setMove({ row, date, time, revert: info.revert });
  }

  async function saveSeries(row: ReportDeadlineRow, date: string, time: string | null, revert: () => void) {
    // The series keeps its rhythm: its first date moves by as many days as this date did.
    const shift = Math.round((Date.parse(`${date}T00:00:00Z`) - Date.parse(`${row.occurs_on}T00:00:00Z`)) / 86_400_000);
    const draft = draftFromDeadline(row);
    try {
      await api.updateReportDeadline(row.deadline_id, deadlinePayload({ ...draft, starts_on: addDays(row.starts_on, shift), all_day: time == null, due_time: time ?? draft.due_time }));
      toast.success(ar ? "نُقل الموعد." : "Deadline moved.");
      reload();
    } catch (err) {
      revert();
      toast.error(message(err));
    }
  }

  async function occurrence(row: ReportDeadlineRow, payload: Parameters<typeof api.reportOccurrence>[2], done?: string) {
    try {
      await api.reportOccurrence(row.deadline_id, row.occurs_on, payload);
      toast.success(done ?? (ar ? "حُفظ." : "Saved."));
      reload();
      return true;
    } catch (err) {
      toast.error(message(err));
      return false;
    }
  }

  async function settleMove(scope: "this" | "all") {
    if (!move) return;
    const item = move;
    setMove(null);
    if (scope === "all") {
      await saveSeries(item.row, item.date, item.time, item.revert);
      return;
    }
    const ok = await occurrence(item.row, { action: "move", date: item.date, time: item.time }, ar ? "نُقل هذا الموعد فقط." : "Only this date moved.");
    if (!ok) item.revert();
  }

  async function removeSeries(row: ReportDeadlineRow) {
    try {
      await api.deleteReportDeadline(row.deadline_id);
      toast.success(ar ? "حُذف الموعد." : "Deadline deleted.");
      setSelected(null);
      reload();
    } catch (err) {
      toast.error(message(err));
    }
  }

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target?.closest("input, textarea, select, [contenteditable='true'], [role='dialog']")) return;
      if (dialog || move) return;
      const key = event.key.toLowerCase();
      const view = VIEWS.find((item) => item.key === key);
      if (view) controller.changeView(view.id);
      else if (key === "t") controller.today();
      else if (key === "j" || key === "n") controller.next();
      else if (key === "k" || key === "p") controller.prev();
      else if (key === "c" && canCreate) openCreate(zoned(new Date()).date, null);
      else if (key === "r") reload();
      else if (key === "escape") setSelected(null);
      else return;
      event.preventDefault();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });

  const calendarLocale = useMemo(() => (ar ? { ...arLocale, code: "ar-SY-u-nu-latn" } : enGbLocale), [ar]);
  const activeView = (controller.view?.type ?? prefs.view) as string;
  const tag = intlTag(locale);
  const overdue = deadlines.filter((row) => row.status === "overdue");

  return (
    <>
      <div className="gcal-toolbar">
        <button type="button" className="gcal-icon-btn gcal-side-toggle" onClick={() => setSideOpen((value) => !value)} aria-expanded={sideOpen} aria-label={ar ? "لوحة التقويم" : "Calendar panel"}>
          <Menu size={18} aria-hidden />
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => controller.today()} title="T">{ar ? "اليوم" : "Today"}</button>
        <div className="gcal-nav">
          <button type="button" className="gcal-icon-btn" onClick={() => controller.prev()} aria-label={ar ? "السابق" : "Previous"} title="K">
            {ar ? <ChevronRight size={18} aria-hidden /> : <ChevronLeft size={18} aria-hidden />}
          </button>
          <button type="button" className="gcal-icon-btn" onClick={() => controller.next()} aria-label={ar ? "التالي" : "Next"} title="J">
            {ar ? <ChevronLeft size={18} aria-hidden /> : <ChevronRight size={18} aria-hidden />}
          </button>
        </div>
        <h2 className="gcal-title">{controller.view?.title ?? ""}</h2>
        {loading ? <span className="gcal-spinner" role="status" aria-label={ar ? "جارٍ التحميل" : "Loading"} /> : null}
        <label className="gcal-search">
          <Search size={15} aria-hidden />
          <input type="search" value={filter} onChange={(event) => setFilter(event.target.value)} placeholder={client ? (ar ? "ابحث في المواعيد والتعديلات" : "Search deadlines and edits") : ar ? "ابحث بالزبون أو التقرير أو الموظف" : "Search by client, report, or staff"} aria-label={ar ? "بحث" : "Search"} />
          {filter ? (
            <button type="button" className="gcal-icon-btn" onClick={() => setFilter("")} aria-label={ar ? "مسح البحث" : "Clear search"}>
              <X size={14} aria-hidden />
            </button>
          ) : null}
        </label>
        <div className="gcal-views" role="tablist" aria-label={ar ? "طريقة العرض" : "View"}>
          {VIEWS.map((view) => (
            <button key={view.id} type="button" role="tab" aria-selected={activeView === view.id} className={activeView === view.id ? "is-active" : ""} onClick={() => controller.changeView(view.id)} title={`${view.label[locale]} (${view.key.toUpperCase()})`}>
              {view.label[locale]}
            </button>
          ))}
        </div>
        <select className="field gcal-views-select" value={VIEWS.some((view) => view.id === activeView) ? activeView : prefs.view} onChange={(event) => controller.changeView(event.target.value)} aria-label={ar ? "طريقة العرض" : "View"}>
          {VIEWS.map((view) => <option key={view.id} value={view.id}>{view.label[locale]}</option>)}
        </select>
        <button type="button" className="gcal-icon-btn" onClick={reload} aria-label={ar ? "تحديث" : "Refresh"} title="R">
          <RefreshCw size={17} aria-hidden />
        </button>
      </div>

      <div className={sideOpen ? "gcal is-side-open" : "gcal"}>
        <aside className="gcal-side" aria-label={ar ? "لوحة التقويم" : "Calendar panel"}>
          {canCreate ? (
            <button type="button" className="gcal-create" onClick={() => openCreate(zoned(new Date()).date, null)}>
              <Plus size={20} aria-hidden />
              <span>{ar ? "موعد تقرير" : "Report deadline"}</span>
              <kbd>C</kbd>
            </button>
          ) : null}

          <DayPicker
            className="gcal-mini"
            mode="single"
            selected={current}
            month={month}
            onMonthChange={setMonth}
            onSelect={(date) => {
              if (!date) return;
              controller.gotoDate(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`);
              setSideOpen(false);
            }}
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
            <h2>{ar ? "مواعيد التسليم" : "Deadlines"}</h2>
            <ul className="gcal-cal-list">
              {STATUSES.map((status) => (
                <li key={status}>
                  <label className="gcal-cal">
                    <input
                      type="checkbox"
                      checked={prefs.statuses.includes(status)}
                      onChange={() => updatePrefs((value) => ({ ...value, statuses: value.statuses.includes(status) ? value.statuses.filter((item) => item !== status) : [...value.statuses, status] }))}
                      style={{ accentColor: STATUS_COLORS[status] }}
                    />
                    <span className="gcal-swatch" style={{ background: STATUS_COLORS[status] }} aria-hidden />
                    <span>{STATUS_COPY[status][locale]}</span>
                    <small className="muted">{counts[status]}</small>
                  </label>
                </li>
              ))}
            </ul>
          </section>

          <section className="gcal-side-section">
            <h2>{ar ? "سجل التقارير" : "Report history"}</h2>
            <ul className="gcal-cal-list">
              {(Object.keys(LAYER_EVENTS) as ActivityLayer[]).map((layer) => {
                const color = EVENT_COLORS[LAYER_EVENTS[layer][LAYER_EVENTS[layer].length - 1]];
                return (
                  <li key={layer}>
                    <label className="gcal-cal">
                      <input
                        type="checkbox"
                        checked={prefs.activity.includes(layer)}
                        onChange={() => updatePrefs((value) => ({ ...value, activity: value.activity.includes(layer) ? value.activity.filter((item) => item !== layer) : [...value.activity, layer] }))}
                        style={{ accentColor: color }}
                      />
                      <span className="gcal-swatch" style={{ background: color }} aria-hidden />
                      <span>{LAYER_COPY[layer][locale]}</span>
                      <small className="muted">{activity.filter((row) => LAYER_EVENTS[layer].includes(row.event)).length}</small>
                    </label>
                  </li>
                );
              })}
            </ul>
          </section>

          <details className="gcal-side-section" open={overdue.length > 0 && overdue.length < 8}>
            <summary>
              <h2>{ar ? `متأخرة في هذه الفترة (${overdue.length})` : `Overdue in view (${overdue.length})`}</h2>
            </summary>
            {overdue.length === 0 ? <p className="muted">{ar ? "لا تقارير متأخرة." : "No overdue reports."}</p> : null}
            <ul className="gcal-unbooked">
              {overdue.map((row) => (
                <li key={row.key}>
                  <div>
                    <strong>{client ? row.title : clientLabel(row.client)}</strong>
                    <small className="muted">{client ? row.date : `${row.title} · ${row.date}`}</small>
                  </div>
                  <button type="button" className="btn btn-ghost" onClick={() => controller.gotoDate(row.date)}>{ar ? "اذهب" : "Go"}</button>
                </li>
              ))}
            </ul>
          </details>

          <section className="gcal-side-section">
            <label className="field-label">
              {ar ? "بداية الأسبوع" : "Start of week"}
              <select className="field" value={prefs.firstDay} onChange={(event) => updatePrefs((value) => ({ ...value, firstDay: Number(event.target.value) as Prefs["firstDay"] }))}>
                <option value={6}>{ar ? "السبت" : "Saturday"}</option>
                <option value={0}>{ar ? "الأحد" : "Sunday"}</option>
                <option value={1}>{ar ? "الاثنين" : "Monday"}</option>
              </select>
            </label>
          </section>
        </aside>

        <section className="gcal-main" aria-label={ar ? "تقويم التقارير" : "Reports calendar"}>
          <FullCalendar
            ref={calendarRef}
            controller={controller}
            plugins={plugins}
            views={views}
            initialView={prefs.view}
            headerToolbar={false}
            locale={calendarLocale}
            direction={ar ? "rtl" : "ltr"}
            timeZone={ZONE}
            firstDay={prefs.firstDay}
            slotHeaderFormat={CLOCK}
            eventTimeFormat={CLOCK}
            scrollTime="08:00:00"
            nowIndicator
            navLinks
            editable
            selectable={canCreate}
            selectMirror
            dayMaxEvents
            height="100%"
            events={fcEvents}
            datesSet={onDatesSet}
            select={onSelect}
            eventClick={onEventClick}
            eventDrop={onEventDrop}
          />
        </section>
      </div>

      <Popover.Root open={selected != null && (selectedDeadline != null || selectedActivity != null)} onOpenChange={(open) => !open && setSelected(null)}>
        {selected ? (
          <Popover.Anchor asChild>
            <span className="gcal-anchor" style={{ left: selected.rect.left, top: selected.rect.top, width: selected.rect.width, height: selected.rect.height }} />
          </Popover.Anchor>
        ) : null}
        <Popover.Portal>
          <Popover.Content className="gcal-popover" side={ar ? "left" : "right"} align="start" sideOffset={8} collisionPadding={12} onOpenAutoFocus={(event) => event.preventDefault()}>
            {selectedDeadline ? (
              <DeadlineDetails
                key={selectedDeadline.key}
                locale={locale}
                row={selectedDeadline}
                onEdit={canUpdate ? () => {
                  setSelected(null);
                  setDialog(draftFromDeadline(selectedDeadline));
                } : undefined}
                onDelete={canDelete ? () => void removeSeries(selectedDeadline) : undefined}
                onAct={canUpdate ? (payload, done) => occurrence(selectedDeadline, payload, done) : undefined}
                onClose={() => setSelected(null)}
              />
            ) : selectedActivity ? (
              <ActivityDetails locale={locale} row={selectedActivity} onClose={() => setSelected(null)} />
            ) : null}
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>

      {move ? (
        <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-move-title">
          <div
            className="form-dialog-backdrop"
            onClick={() => {
              move.revert();
              setMove(null);
            }}
          />
          <div className="form-dialog-panel gcal-move">
            <div className="form-dialog-head">
              <h2 className="form-dialog-title" id="rc-move-title">{ar ? "نقل موعد متكرر" : "Move a repeating deadline"}</h2>
            </div>
            <div className="form-dialog-body">
              <p>
                <strong>{move.row.title}</strong>
                <br />
                <span dir="ltr">{move.row.date} → {move.date}{move.time ? ` ${move.time}` : ""}</span>
              </p>
              <div className="gcal-choices">
                <button type="button" className="gcal-choice is-primary" onClick={() => void settleMove("this")}>
                  <strong>{ar ? "هذا الموعد فقط" : "This date only"}</strong>
                  <small>{ar ? "تبقى بقية المواعيد في أماكنها." : "The other dates stay where they are."}</small>
                </button>
                <button type="button" className="gcal-choice" onClick={() => void settleMove("all")}>
                  <strong>{ar ? "كل المواعيد" : "All dates"}</strong>
                  <small>{ar ? "تنتقل السلسلة كلها بنفس عدد الأيام." : "The whole series moves by the same number of days."}</small>
                </button>
              </div>
            </div>
            <div className="form-dialog-actions">
              <button
                type="button"
                className="btn btn-ghost"
                onClick={() => {
                  move.revert();
                  setMove(null);
                }}
              >
                {ar ? "تراجع" : "Undo"}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {dialog ? (
        <DeadlineDialog
          locale={locale}
          initial={dialog}
          fixedClient={client != null}
          onClose={() => setDialog(null)}
          onSaved={() => {
            setDialog(null);
            reload();
          }}
        />
      ) : null}
    </>
  );
}

function DeadlineDetails({
  locale,
  row,
  onEdit,
  onDelete,
  onAct,
  onClose,
}: {
  locale: Locale;
  row: ReportDeadlineRow;
  /** Each action is missing when the staff member may not do it. */
  onEdit?: () => void;
  onDelete?: () => void;
  onAct?: (payload: Parameters<typeof api.reportOccurrence>[2], done?: string) => Promise<boolean>;
  onClose: () => void;
}) {
  const ar = locale === "ar";
  const [deleting, setDeleting] = useState(false);
  const [picking, setPicking] = useState(false);
  const [reports, setReports] = useState<ClientReport[] | null>(null);
  const [reportId, setReportId] = useState("");
  const [busy, setBusy] = useState(false);
  const clientId = row.client?.id;
  const end = row.all_day ? addDays(row.date, 1) : new Date(new Date(row.start).getTime() + 30 * 60_000).toISOString();

  useEffect(() => {
    if (!picking || reports || clientId == null) return;
    api
      .clientReports(clientId)
      .then((res) => setReports(res.data))
      .catch(() => setReports([]));
  }, [picking, reports, clientId]);

  async function act(payload: Parameters<typeof api.reportOccurrence>[2], done?: string) {
    if (!onAct) return;
    setBusy(true);
    await onAct(payload, done);
    setBusy(false);
  }

  return (
    <div className="gcal-pop">
      <div className="gcal-pop-tools">
        {onEdit ? <button type="button" className="gcal-icon-btn" onClick={onEdit} title={ar ? "تعديل السلسلة" : "Edit series"} aria-label={ar ? "تعديل السلسلة" : "Edit series"}><Pencil size={16} aria-hidden /></button> : null}
        {onDelete ? <button type="button" className="gcal-icon-btn" onClick={() => setDeleting((value) => !value)} title={ar ? "حذف" : "Delete"} aria-label={ar ? "حذف" : "Delete"}><Trash size={16} aria-hidden /></button> : null}
        <button type="button" className="gcal-icon-btn" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}><X size={16} aria-hidden /></button>
      </div>

      <div className="gcal-pop-head">
        <span className="gcal-dot" style={{ background: deadlineColor(row) }} aria-hidden />
        <div>
          <h3>{row.title}</h3>
          <p>{formatRangeText(locale, row.start, end, row.all_day)}</p>
          <p className="muted">
            <span className="rc-status" style={{ background: STATUS_COLORS[row.status], color: contrast(STATUS_COLORS[row.status]) }}>{STATUS_COPY[row.status][locale]}</span>
            {row.moved ? <> · {ar ? `نُقل من ${row.occurs_on}` : `Moved from ${row.occurs_on}`}</> : null}
          </p>
        </div>
      </div>

      {deleting && onDelete ? (
        <div className="gcal-pop-confirm">
          <p>{row.repeat === "none" ? (ar ? "حذف هذا الموعد؟" : "Delete this deadline?") : ar ? "تُحذف السلسلة كلها. لإلغاء موعد واحد استخدم «تجاوز»." : "The whole series is deleted. To drop one date use Skip."}</p>
          <div className="gcal-row">
            <button type="button" className="btn btn-danger" onClick={onDelete}>{ar ? "حذف" : "Delete"}</button>
            <button type="button" className="btn btn-ghost" onClick={() => setDeleting(false)}>{ar ? "إلغاء" : "Cancel"}</button>
          </div>
        </div>
      ) : null}

      <ul className="gcal-pop-list">
        {row.client ? (
          <li>
            <Building size={16} aria-hidden />
            <Link className="table-link" to={`/reports/clients/${row.client.id}`}>{clientLabel(row.client)}</Link>
          </li>
        ) : null}
        {row.repeat !== "none" ? (
          <li>
            <Repeat size={16} aria-hidden />
            <span>{REPEAT_COPY[row.repeat][locale]}{row.repeat_until ? (ar ? ` حتى ${row.repeat_until}` : ` until ${row.repeat_until}`) : ""}</span>
          </li>
        ) : null}
        {row.notes ? (
          <li>
            <StickyNote size={16} aria-hidden />
            <span className="gcal-pop-desc">{row.notes}</span>
          </li>
        ) : null}
        {row.status === "done" ? (
          <li>
            <CircleCheck size={16} aria-hidden />
            <span>
              {row.report ? <Link className="table-link" to={`/reports/${row.report.id}/edit`}>{row.report.title}</Link> : ar ? "سُلّم" : "Delivered"}
              <small className="muted"> · {row.done_by ? `${row.done_by} · ` : ""}{stamp(row.done_at, locale)}</small>
            </span>
          </li>
        ) : null}
      </ul>

      {!onAct ? null : picking ? (
        <div className="gcal-pop-move">
          <label className="field-label">
            {ar ? "التقرير الذي سُلّم (اختياري)" : "Delivered report (optional)"}
            <select className="field" value={reportId} onChange={(event) => setReportId(event.target.value)}>
              <option value="">{reports == null ? (ar ? "جارٍ التحميل…" : "Loading…") : ar ? "بدون ربط" : "No link"}</option>
              {(reports ?? []).map((report) => (
                <option key={report.id} value={report.id}>{report.title}</option>
              ))}
            </select>
          </label>
          <div className="gcal-row">
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void act({ action: "done", client_report_id: reportId ? Number(reportId) : null }, ar ? "عُلّم كمُسلّم." : "Marked delivered.")}>
              <Check size={15} aria-hidden /> {ar ? "تأكيد" : "Confirm"}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setPicking(false)}>{ar ? "إلغاء" : "Cancel"}</button>
          </div>
        </div>
      ) : (
        <div className="toolbar gcal-pop-actions">
          {row.status === "done" ? (
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void act({ action: "undo" })}><Undo2 size={15} aria-hidden /> {ar ? "إلغاء التسليم" : "Undo delivered"}</button>
          ) : row.status === "skipped" ? (
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void act({ action: "unskip" })}><Undo2 size={15} aria-hidden /> {ar ? "إلغاء التجاوز" : "Undo skip"}</button>
          ) : (
            <>
              <button type="button" className="btn btn-primary" disabled={busy} onClick={() => setPicking(true)}><Check size={15} aria-hidden /> {ar ? "سُلّم" : "Delivered"}</button>
              {row.repeat !== "none" ? (
                <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void act({ action: "skip" }, ar ? "تُجووز هذا الموعد." : "Date skipped.")}><SkipForward size={15} aria-hidden /> {ar ? "تجاوز" : "Skip"}</button>
              ) : null}
              {row.client ? (
                <Link className="btn btn-ghost" to={`/reports/clients/${row.client.id}/new`}><FilePlus size={15} aria-hidden /> {ar ? "اكتب التقرير" : "Write the report"}</Link>
              ) : null}
            </>
          )}
          {row.moved ? (
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void act({ action: "reset" })}><CalendarClock size={15} aria-hidden /> {ar ? "أعده لموعده" : "Back to its date"}</button>
          ) : null}
        </div>
      )}
    </div>
  );
}

function ActivityDetails({ locale, row, onClose }: { locale: Locale; row: ReportVersion; onClose: () => void }) {
  const ar = locale === "ar";
  const session = row.event === "created" || row.event === "edited";
  return (
    <div className="gcal-pop">
      <div className="gcal-pop-tools">
        <button type="button" className="gcal-icon-btn" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}><X size={16} aria-hidden /></button>
      </div>
      <div className="gcal-pop-head">
        <span className="gcal-dot" style={{ background: EVENT_COLORS[row.event] }} aria-hidden />
        <div>
          <h3>{EVENT_COPY[row.event][locale]}</h3>
          <p>{row.started_at && row.ended_at ? formatRangeText(locale, row.started_at, session ? row.ended_at : row.started_at, false) : stamp(row.ended_at, locale)}</p>
        </div>
      </div>
      <ul className="gcal-pop-list">
        <li>
          <FileText size={16} aria-hidden />
          {row.report_id != null ? <Link className="table-link" to={`/reports/${row.report_id}/edit`}>{row.report_title ?? row.title}</Link> : <span>{row.title}</span>}
        </li>
        {row.client ? (
          <li>
            <Building size={16} aria-hidden />
            <Link className="table-link" to={`/reports/clients/${row.client.id}?tab=history`}>{clientLabel(row.client)}</Link>
          </li>
        ) : null}
        <li>
          <User size={16} aria-hidden />
          <span>{row.user?.name ?? (ar ? "النظام" : "System")}</span>
        </li>
        {session && row.saves > 1 ? (
          <li>
            <AlarmClock size={16} aria-hidden />
            <span>{ar ? `${row.saves} حفظاً تلقائياً في هذه الجلسة` : `${row.saves} autosaves in this session`}</span>
          </li>
        ) : null}
      </ul>
    </div>
  );
}
