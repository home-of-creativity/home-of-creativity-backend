import FullCalendar, {
  useCalendarController,
  type CalendarRef,
  type DateSelectInfo,
  type DatesSetInfo,
  type EventClickInfo,
  type EventDropInfo,
  type EventInput,
  type EventResizeDoneInfo,
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
import { ChevronLeft, ChevronRight, ExternalLink, Menu, RefreshCw, Search, Settings, X } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import {
  api,
  type CalendarDirectory,
  type CalendarEventRow,
  type CalendarScope,
  type PhotographyAction,
  type PhotographyBoard,
  type PhotographyBookingRow,
  type PhotographyRequestRow,
} from "../api";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";
import { CalendarSidebar } from "./photography/CalendarSidebar";
import { EventDialog, draftFromRow, emptyDraft, scopeLabel, type DialogState } from "./photography/EventDialog";
import { BookingDetails, GoogleEventDetails } from "./photography/EventDetails";
import {
  TONE_COLORS,
  VIEWS,
  ZONE,
  bookingLayer,
  bookingTone,
  contrast,
  eventColor,
  loadPrefs,
  savePrefs,
  statusCopy,
  wallClock,
  zoned,
  type Layer,
  type Prefs,
  type ViewId,
} from "./photography/model";

type Pending =
  | { kind: "booking"; row: PhotographyBookingRow; startsAt: string; revert: () => void }
  | { kind: "google"; row: CalendarEventRow; change: { all_day: boolean; start: string; end: string }; revert: () => void };

type ActExtra = { starts_at?: string; direct?: boolean; override_lead?: boolean };

const plugins = [classicTheme, dayGridPlugin, timeGridPlugin, listPlugin, multiMonthPlugin, interactionPlugin];

const views = {
  timeGridFourDay: { type: "timeGrid", duration: { days: 4 } },
};

const SNAP = { minutes: 15 };

const CLOCK = { hour: "2-digit", minute: "2-digit", hour12: false } as const;

function localStamp(date: Date) {
  const { date: day, time } = zoned(date);
  return `${day}T${time}`;
}

function message(err: unknown) {
  return err instanceof Error ? err.message : String(err);
}

export function PhotographyPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const ar = locale === "ar";
  const [prefs, setPrefsState] = useState<Prefs>(loadPrefs);
  const [board, setBoard] = useState<PhotographyBoard | null>(null);
  const [directory, setDirectory] = useState<CalendarDirectory | null>(null);
  const [range, setRange] = useState<{ from: string; to: string } | null>(null);
  const [bookings, setBookings] = useState<PhotographyBookingRow[]>([]);
  const [events, setEvents] = useState<CalendarEventRow[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [viewBeforeSearch, setViewBeforeSearch] = useState<ViewId | null>(null);
  const [selected, setSelected] = useState<{ key: string; rect: DOMRect } | null>(null);
  const [dialog, setDialog] = useState<DialogState | null>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const [pendingScope, setPendingScope] = useState<CalendarScope>("this");
  const [pendingNotify, setPendingNotify] = useState(true);
  const [busy, setBusy] = useState("");
  const [sideOpen, setSideOpen] = useState(false);
  const [error, setError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);
  const [current, setCurrent] = useState(() => new Date());
  const calendarRef = useRef<CalendarRef>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const controller = useCalendarController();

  const updatePrefs = useCallback((change: (value: Prefs) => Prefs) => {
    setPrefsState((value) => {
      const next = change(value);
      savePrefs(next);
      return next;
    });
  }, []);

  const loadBoard = useCallback(async () => {
    const res = await api.photographyBoard();
    setBoard(res.data);
  }, []);

  const loadDirectory = useCallback(async (fresh = false) => {
    try {
      const res = await api.photographyCalendars(fresh);
      setDirectory(res.data);
    } catch (err) {
      setDirectory({ configured: false, calendars: [], service_email: null, can_manage: false });
      toast.error(message(err));
    }
  }, []);

  const reload = useCallback(() => {
    setReloadKey((value) => value + 1);
    loadBoard().catch((err) => toast.error(message(err)));
  }, [loadBoard]);

  useEffect(() => {
    loadBoard().catch((err) => setError(message(err)));
    void loadDirectory();
  }, [loadBoard, loadDirectory]);

  useEffect(() => {
    if (!range) return;
    let cancelled = false;
    setLoading(true);
    const ids = directory?.configured ? directory.calendars.map((calendar) => calendar.id) : [];
    Promise.allSettled([
      api.photographyRange(range.from, range.to, prefs.showClosed),
      ids.length ? api.photographyEvents({ from: range.from, to: range.to, calendars: ids, q: search || undefined }) : Promise.resolve({ data: [] as CalendarEventRow[], errors: [] }),
    ])
      .then(([shoots, google]) => {
        if (cancelled) return;
        if (shoots.status === "fulfilled") setBookings(shoots.value.data);
        else toast.error(message(shoots.reason));
        if (google.status === "fulfilled") {
          setEvents(google.value.data);
          setErrors(Object.fromEntries(google.value.errors.map((item) => [item.calendar_id, item.message])));
        } else {
          toast.error(message(google.reason));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [range, directory, prefs.showClosed, search, reloadKey]);

  // Google Calendar and the bots change shoots too; pick that up while the tab is open.
  useEffect(() => {
    const id = window.setInterval(() => {
      if (document.visibilityState !== "visible" || dialog || pending) return;
      reload();
    }, 60_000);
    return () => window.clearInterval(id);
  }, [dialog, pending, reload]);

  const calendarById = useMemo(() => new Map((directory?.calendars ?? []).map((calendar) => [calendar.id, calendar])), [directory]);
  const writable = useMemo(() => (directory?.calendars ?? []).filter((calendar) => calendar.writable), [directory]);
  const canEvent = writable.length > 0;
  const canShoot = board?.me.can_all === true || board?.me.is_photographer === true;
  const canCreate = canEvent || canShoot;

  const counts = useMemo(() => {
    const result: Record<Layer, number> = { queue: 0, waiting: 0, agreed: 0, finished: 0 };
    for (const row of bookings) {
      const layer = bookingLayer(row);
      if (layer !== "closed") result[layer] += 1;
    }
    return result;
  }, [bookings]);

  const fcEvents = useMemo<EventInput[]>(() => {
    const list: EventInput[] = [];
    const needle = search.trim().toLowerCase();
    const me = board?.me.employee_id ?? null;
    const shootMs = (board?.settings.shoot_hours ?? 3) * 3_600_000;
    if (prefs.showBookings) {
      for (const row of bookings) {
        if (!row.starts_at) continue;
        const layer = bookingLayer(row);
        if (layer === "closed" ? !prefs.showClosed : !prefs.layers.includes(layer)) continue;
        if (prefs.mineOnly && row.employee?.id !== me) continue;
        const name = row.client?.name ?? row.request?.client_name ?? row.request?.number ?? "";
        if (needle && ![name, row.client?.company_name, row.client?.phone, row.request?.number].some((value) => value?.toLowerCase().includes(needle))) continue;
        const tone = bookingTone(row, me);
        const movable = row.can.propose || row.can.reschedule || row.can.reschedule_direct;
        list.push({
          id: `b:${row.id}`,
          title: `${name} · ${statusCopy[row.status]?.[locale] ?? row.status}`,
          start: row.starts_at,
          end: row.ends_at ?? new Date(new Date(row.starts_at).getTime() + shootMs).toISOString(),
          color: TONE_COLORS[tone],
          contrastColor: "#1a1224",
          editable: movable,
          startEditable: movable,
          durationEditable: false,
          className: `gcal-ev is-booking is-${tone}`,
          extendedProps: { kind: "booking", bookingId: row.id },
        });
        if (row.proposed_starts_at && row.proposed_starts_at !== row.starts_at) {
          list.push({
            id: `p:${row.id}`,
            title: `${ar ? "مقترح" : "Proposed"} · ${name}`,
            start: row.proposed_starts_at,
            end: new Date(new Date(row.proposed_starts_at).getTime() + shootMs).toISOString(),
            color: TONE_COLORS[tone],
            contrastColor: "#1a1224",
            editable: false,
            className: "gcal-ev is-proposed",
            extendedProps: { kind: "booking", bookingId: row.id },
          });
        }
      }
    }
    for (const row of events) {
      if (prefs.hidden.includes(row.calendar_id)) continue;
      const color = eventColor(row.color_id, calendarById.get(row.calendar_id)?.color ?? null);
      list.push({
        id: `g:${row.calendar_id}|${row.id}`,
        title: row.title || (ar ? "(بلا عنوان)" : "(No title)"),
        start: row.start,
        end: row.end,
        allDay: row.all_day,
        color,
        contrastColor: contrast(color),
        editable: row.editable,
        className: ["gcal-ev", row.transparency === "transparent" ? "is-free" : "", row.status === "tentative" ? "is-tentative" : ""].join(" "),
        extendedProps: { kind: "google" },
      });
    }
    return list;
  }, [bookings, events, prefs, board, search, locale, ar, calendarById]);

  const selectedBooking = selected?.key.startsWith("b:") ? bookings.find((row) => `b:${row.id}` === selected.key) ?? null : null;
  const selectedEvent = selected?.key.startsWith("g:") ? events.find((row) => `g:${row.calendar_id}|${row.id}` === selected.key) ?? null : null;

  function onDatesSet(info: DatesSetInfo) {
    setRange((value) => (value?.from === info.startStr && value?.to === info.endStr ? value : { from: info.startStr, to: info.endStr }));
    const date = controller.getDate() ?? info.start;
    setCurrent((value) => (value.getTime() === date.getTime() ? value : date));
    const type = info.view.type as ViewId;
    if (VIEWS.some((view) => view.id === type)) updatePrefs((value) => (value.view === type ? value : { ...value, view: type }));
  }

  function openCreate(start: Date, end: Date, allDay: boolean, request?: PhotographyRequestRow) {
    if (!canCreate) return;
    const main = writable.find((calendar) => calendar.main) ?? writable[0];
    setSelected(null);
    setDialog({
      kind: "create",
      draft: emptyDraft(main?.id ?? "", start, end, allDay),
      tab: request || !canEvent ? "shoot" : "event",
      requestId: request?.id,
    });
  }

  function openQuickCreate(request?: PhotographyRequestRow) {
    const start = new Date(Math.ceil(Date.now() / 3_600_000) * 3_600_000);
    openCreate(start, new Date(start.getTime() + 3_600_000), false, request);
  }

  function onSelect(info: DateSelectInfo) {
    calendarRef.current?.getApi().unselect();
    let end = info.end;
    if (!info.allDay && end.getTime() - info.start.getTime() <= prefs.slotMinutes * 60_000) end = new Date(info.start.getTime() + 3_600_000);
    openCreate(info.start, end, info.allDay);
  }

  function onEventClick(info: EventClickInfo) {
    info.jsEvent.preventDefault();
    const id = info.event.id;
    setSelected({ key: id.startsWith("p:") ? `b:${id.slice(2)}` : id, rect: info.el.getBoundingClientRect() });
  }

  function onEventChange(info: EventDropInfo | EventResizeDoneInfo) {
    const { event } = info;
    if (!event.start) {
      info.revert();
      return;
    }
    if (event.extendedProps.kind === "booking") {
      const row = bookings.find((item) => item.id === event.extendedProps.bookingId);
      if (!row || event.allDay) {
        info.revert();
        if (row) toast.error(ar ? "موعد التصوير له ساعة محددة. انقله إلى خانة وقت." : "A shoot needs a time. Drop it on a time slot.");
        return;
      }
      setPending({ kind: "booking", row, startsAt: wallClock(event.start), revert: info.revert });
      return;
    }
    const row = events.find((item) => `g:${item.calendar_id}|${item.id}` === event.id);
    if (!row) {
      info.revert();
      return;
    }
    const end = event.end ?? new Date(event.start.getTime() + (event.allDay ? 86_400_000 : 3_600_000));
    const change = event.allDay
      ? { all_day: true, start: zoned(event.start).date, end: zoned(end).date }
      : { all_day: false, start: localStamp(event.start), end: localStamp(end) };
    if (row.recurring_event_id || row.attendees.length) {
      setPendingScope("this");
      setPendingNotify(true);
      setPending({ kind: "google", row, change, revert: info.revert });
      return;
    }
    void applyGoogleChange(row, change, "this", false, info.revert);
  }

  async function applyGoogleChange(row: CalendarEventRow, change: { all_day: boolean; start: string; end: string }, scope: CalendarScope, notify: boolean, revert: () => void) {
    try {
      const res = await api.updatePhotographyEvent({
        calendar_id: row.calendar_id,
        event_id: row.id,
        scope: row.recurring_event_id ? scope : undefined,
        notify,
        ...change,
      });
      toast.success(res.message || (ar ? "حُفظ." : "Saved."));
      reload();
    } catch (err) {
      revert();
      toast.error(message(err));
    }
  }

  async function act(row: PhotographyBookingRow, action: PhotographyAction, extra?: ActExtra) {
    setBusy(`${row.id}:${action}`);
    try {
      const res = await api.photographyAction(row.id, action, extra);
      toast.success(res.message || t(copy.saved));
      reload();
      return true;
    } catch (err) {
      toast.error(message(err));
      return false;
    } finally {
      setBusy("");
    }
  }

  async function settlePending(choice: "propose" | "reschedule" | "direct" | "google") {
    if (!pending) return;
    const item = pending;
    setPending(null);
    if (item.kind === "google") {
      await applyGoogleChange(item.row, item.change, pendingScope, pendingNotify && item.row.attendees.length > 0, item.revert);
      return;
    }
    const canAll = board?.me.can_all === true;
    const ok =
      choice === "propose"
        ? await act(item.row, "propose", { starts_at: item.startsAt, override_lead: canAll })
        : await act(item.row, "reschedule", { starts_at: item.startsAt, direct: choice === "direct", override_lead: choice === "direct" || canAll });
    if (!ok) item.revert();
  }

  function cancelPending() {
    pending?.revert();
    setPending(null);
  }

  async function openEdit(row: CalendarEventRow) {
    try {
      const res = await api.photographyEvent(row.calendar_id, row.id);
      setSelected(null);
      setDialog({ kind: "edit", row: res.data, draft: draftFromRow(res.data) });
    } catch (err) {
      toast.error(message(err));
    }
  }

  function openDuplicate(row: CalendarEventRow) {
    const draft = draftFromRow(row);
    const main = writable.find((calendar) => calendar.main) ?? writable[0];
    if (!writable.some((calendar) => calendar.id === draft.calendar_id)) draft.calendar_id = main?.id ?? "";
    setSelected(null);
    setDialog({ kind: "create", draft: { ...draft, title: row.title, recurrence: row.recurrence ?? [] }, tab: "event" });
  }

  function submitSearch(event: FormEvent) {
    event.preventDefault();
    const value = query.trim();
    setSearch(value);
    if (value && !viewBeforeSearch) {
      setViewBeforeSearch(prefs.view);
      controller.changeView("listYear");
    }
    if (!value) clearSearch();
  }

  function clearSearch() {
    setQuery("");
    setSearch("");
    if (viewBeforeSearch) controller.changeView(viewBeforeSearch);
    setViewBeforeSearch(null);
  }

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target?.closest("input, textarea, select, [contenteditable='true'], [role='dialog']")) return;
      if (dialog || pending) return;
      const key = event.key.toLowerCase();
      const view = VIEWS.find((item) => item.key === key);
      if (view) controller.changeView(view.id);
      else if (key === "t") controller.today();
      else if (key === "j" || key === "n") controller.next();
      else if (key === "k" || key === "p") controller.prev();
      else if (key === "c" && canCreate) openQuickCreate();
      else if (key === "r") reload();
      else if (key === "/") searchRef.current?.focus();
      else if (key === "escape") setSelected(null);
      else return;
      event.preventDefault();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });

  const openHour = board?.settings.team_hours?.open ?? "09:00";
  const closeHour = board?.settings.team_hours?.close ?? "21:00";
  // FullCalendar re-reads every option whose reference changes, so each object stays stable.
  const calendarLocale = useMemo(() => (ar ? { ...arLocale, code: "ar-SY-u-nu-latn" } : enGbLocale), [ar]);
  const businessHours = useMemo(() => ({ daysOfWeek: [0, 1, 2, 3, 4, 6], startTime: openHour, endTime: closeHour }), [openHour, closeHour]);
  const slotDuration = useMemo(() => ({ minutes: prefs.slotMinutes }), [prefs.slotMinutes]);
  const hiddenDays = useMemo(() => (prefs.hideFriday ? [5] : []), [prefs.hideFriday]);
  const scrollTime = `${String(Math.max(Number(openHour.slice(0, 2)) - 1, 0)).padStart(2, "0")}:00:00`;

  if (!board && !error) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const activeView = (controller.view?.type ?? prefs.view) as string;
  const failed = Object.entries(errors);

  return (
    <>
      <PageHeader title={t(copy.navPhotography)} lede={t(copy.photographyLede)} />
      {error ? <p className="error">{error}</p> : null}

      <div className="gcal-toolbar">
        <button type="button" className="gcal-icon-btn gcal-side-toggle" onClick={() => setSideOpen((value) => !value)} aria-expanded={sideOpen} aria-label={ar ? "لوحة التقاويم" : "Calendar panel"}>
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
        <form className="gcal-search" onSubmit={submitSearch} role="search">
          <Search size={15} aria-hidden />
          <input ref={searchRef} type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={ar ? "ابحث في الأحداث والمواعيد" : "Search events and shoots"} aria-label={ar ? "بحث" : "Search"} />
          {search ? (
            <button type="button" className="gcal-icon-btn" onClick={clearSearch} aria-label={ar ? "مسح البحث" : "Clear search"}>
              <X size={14} aria-hidden />
            </button>
          ) : null}
        </form>
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
        <SettingsMenu locale={locale} prefs={prefs} onChange={(next) => updatePrefs(() => next)} />
        <button type="button" className="gcal-icon-btn" onClick={reload} aria-label={ar ? "تحديث" : "Refresh"} title="R">
          <RefreshCw size={17} aria-hidden />
        </button>
        {board?.calendar_url ? (
          <a className="gcal-icon-btn" href={board.calendar_url} target="_blank" rel="noreferrer" aria-label={ar ? "فتح جوجل كالندر" : "Open Google Calendar"} title={ar ? "فتح جوجل كالندر" : "Open Google Calendar"}>
            <ExternalLink size={17} aria-hidden />
          </a>
        ) : null}
      </div>

      {failed.length ? (
        <p className="gcal-warning" role="alert">
          {ar ? "تعذرت قراءة: " : "Could not read: "}
          {failed.map(([id, text]) => `${calendarById.get(id)?.summary ?? id} (${text})`).join(" · ")}
        </p>
      ) : null}

      <div className={sideOpen ? "gcal is-side-open" : "gcal"}>
        <CalendarSidebar
          locale={locale}
          prefs={prefs}
          setPrefs={(next) => updatePrefs(() => next)}
          directory={directory}
          errors={errors}
          board={board}
          current={current}
          counts={counts}
          canCreate={canCreate}
          onPickDate={(date) => {
            // The mini calendar hands back the browser's midnight; the big one reads the day in Damascus.
            controller.gotoDate(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`);
            setSideOpen(false);
          }}
          onCreate={() => openQuickCreate()}
          onBook={(row) => openQuickCreate(row)}
          onDirectoryChanged={() => void loadDirectory(true)}
          onBoardChanged={reload}
        />
        <section className="gcal-main" aria-label={ar ? "تقويم التصوير" : "Photography calendar"}>
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
            hiddenDays={hiddenDays}
            slotDuration={slotDuration}
            snapDuration={SNAP}
            weekNumbers={prefs.weekNumbers}
            slotHeaderFormat={CLOCK}
            eventTimeFormat={CLOCK}
            businessHours={businessHours}
            scrollTime={scrollTime}
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
            eventDrop={onEventChange}
            eventResize={onEventChange}
          />
        </section>
      </div>

      <Popover.Root open={selected != null && (selectedBooking != null || selectedEvent != null)} onOpenChange={(open) => !open && setSelected(null)}>
        {selected ? (
          <Popover.Anchor asChild>
            <span className="gcal-anchor" style={{ left: selected.rect.left, top: selected.rect.top, width: selected.rect.width, height: selected.rect.height }} />
          </Popover.Anchor>
        ) : null}
        <Popover.Portal>
          <Popover.Content className="gcal-popover" side={ar ? "left" : "right"} align="start" sideOffset={8} collisionPadding={12} onOpenAutoFocus={(event) => event.preventDefault()}>
            {selectedEvent ? (
              <GoogleEventDetails
                locale={locale}
                row={selectedEvent}
                calendar={calendarById.get(selectedEvent.calendar_id)}
                onEdit={() => void openEdit(selectedEvent)}
                onDuplicate={canEvent ? () => openDuplicate(selectedEvent) : undefined}
                onDeleted={() => {
                  setSelected(null);
                  reload();
                }}
                onClose={() => setSelected(null)}
              />
            ) : selectedBooking ? (
              <BookingDetails locale={locale} row={selectedBooking} board={board} busy={busy} onAct={(row, action, extra) => void act(row, action, extra)} onClose={() => setSelected(null)} />
            ) : null}
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>

      {pending ? (
        <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="gcal-move-title">
          <div className="form-dialog-backdrop" onClick={cancelPending} />
          <div className="form-dialog-panel gcal-move">
            <div className="form-dialog-head">
              <h2 className="form-dialog-title" id="gcal-move-title">
                {pending.kind === "booking" ? (ar ? "نقل موعد التصوير" : "Move the shoot") : ar ? "تعديل الحدث" : "Change the event"}
              </h2>
              <button type="button" className="form-dialog-close" onClick={cancelPending} aria-label={ar ? "إلغاء" : "Cancel"}>
                <X aria-hidden />
              </button>
            </div>
            <div className="form-dialog-body">
              {pending.kind === "booking" ? (
                <>
                  <p>
                    <strong>{pending.row.client?.name ?? pending.row.request?.number}</strong>
                    <br />
                    {ar ? "الوقت الجديد: " : "New time: "}
                    <span dir="ltr">{pending.startsAt}</span>
                  </p>
                  <div className="gcal-choices">
                    {pending.row.can.reschedule_direct ? (
                      <button type="button" className="gcal-choice is-primary" onClick={() => void settlePending("direct")}>
                        <strong>{ar ? "انقل مباشرة" : "Move now"}</strong>
                        <small>{ar ? "يتغير الموعد فوراً ويُبلغ العميل." : "The shoot moves now and the client is told."}</small>
                      </button>
                    ) : null}
                    {pending.row.can.reschedule ? (
                      <button type="button" className="gcal-choice" onClick={() => void settlePending("reschedule")}>
                        <strong>{ar ? "اطلب التعديل" : "Request the move"}</strong>
                        <small>{ar ? "يبقى الوقت القديم محجوزاً حتى يقبل المصور." : "The old time stays held until the photographer accepts."}</small>
                      </button>
                    ) : null}
                    {pending.row.can.propose ? (
                      <button type="button" className="gcal-choice" onClick={() => void settlePending("propose")}>
                        <strong>{ar ? "اقترح على العميل" : "Propose to the client"}</strong>
                        <small>{ar ? "يصل العميل الوقت ليقبله أو يرفضه." : "The client gets the time to accept or decline."}</small>
                      </button>
                    ) : null}
                  </div>
                </>
              ) : (
                <>
                  <p>
                    <strong>{pending.row.title}</strong>
                    <br />
                    <span dir="ltr">{pending.change.start.replace("T", " ")} – {pending.change.end.replace("T", " ")}</span>
                  </p>
                  {pending.row.recurring_event_id ? (
                    <fieldset className="gcal-scope">
                      <legend>{ar ? "تطبيق على" : "Apply to"}</legend>
                      {(["this", "following", "all"] as CalendarScope[]).map((item) => (
                        <label key={item} className="checkbox-row">
                          <input type="radio" name="pending-scope" checked={pendingScope === item} onChange={() => setPendingScope(item)} />
                          {scopeLabel(item, locale)}
                        </label>
                      ))}
                    </fieldset>
                  ) : null}
                  {pending.row.attendees.length ? (
                    <label className="checkbox-row">
                      <input type="checkbox" checked={pendingNotify} onChange={(event) => setPendingNotify(event.target.checked)} />
                      {ar ? "أرسل التحديث للمدعوين" : "Send the update to guests"}
                    </label>
                  ) : null}
                </>
              )}
            </div>
            <div className="form-dialog-actions">
              {pending.kind === "google" ? <button type="button" className="btn btn-primary" onClick={() => void settlePending("google")}>{ar ? "حفظ" : "Save"}</button> : null}
              <button type="button" className="btn btn-ghost" onClick={cancelPending}>{ar ? "تراجع" : "Undo"}</button>
            </div>
          </div>
        </div>
      ) : null}

      {dialog ? (
        <EventDialog
          locale={locale}
          state={dialog}
          calendars={directory?.calendars ?? []}
          board={board}
          canManage={canEvent}
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

function SettingsMenu({ locale, prefs, onChange }: { locale: Locale; prefs: Prefs; onChange: (next: Prefs) => void }) {
  const ar = locale === "ar";
  const shortcuts: Array<[string, string]> = [
    ["T", ar ? "اليوم" : "Today"],
    ["J / K", ar ? "التالي / السابق" : "Next / previous"],
    ["D · X · W · M · Y · A", ar ? "يوم · 4 أيام · أسبوع · شهر · سنة · جدول" : "Day · 4 days · week · month · year · schedule"],
    ["C", ar ? "إنشاء" : "Create"],
    ["/", ar ? "بحث" : "Search"],
    ["R", ar ? "تحديث" : "Refresh"],
  ];
  return (
    <Popover.Root>
      <Popover.Trigger asChild>
        <button type="button" className="gcal-icon-btn" aria-label={ar ? "إعدادات التقويم" : "Calendar settings"} title={ar ? "إعدادات التقويم" : "Calendar settings"}>
          <Settings size={17} aria-hidden />
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content className="gcal-popover gcal-settings" align="end" sideOffset={6} collisionPadding={12}>
          <h3>{ar ? "إعدادات التقويم" : "Calendar settings"}</h3>
          <label className="field-label">
            {ar ? "بداية الأسبوع" : "Start of week"}
            <select className="field" value={prefs.firstDay} onChange={(event) => onChange({ ...prefs, firstDay: Number(event.target.value) as Prefs["firstDay"] })}>
              <option value={6}>{ar ? "السبت" : "Saturday"}</option>
              <option value={0}>{ar ? "الأحد" : "Sunday"}</option>
              <option value={1}>{ar ? "الاثنين" : "Monday"}</option>
            </select>
          </label>
          <label className="field-label">
            {ar ? "حجم خانة الوقت" : "Time slot size"}
            <select className="field" value={prefs.slotMinutes} onChange={(event) => onChange({ ...prefs, slotMinutes: Number(event.target.value) as Prefs["slotMinutes"] })}>
              <option value={15}>{ar ? "15 دقيقة" : "15 minutes"}</option>
              <option value={30}>{ar ? "30 دقيقة" : "30 minutes"}</option>
              <option value={60}>{ar ? "ساعة" : "1 hour"}</option>
            </select>
          </label>
          <label className="checkbox-row">
            <input type="checkbox" checked={prefs.hideFriday} onChange={(event) => onChange({ ...prefs, hideFriday: event.target.checked })} />
            {ar ? "إخفاء الجمعة" : "Hide Friday"}
          </label>
          <label className="checkbox-row">
            <input type="checkbox" checked={prefs.weekNumbers} onChange={(event) => onChange({ ...prefs, weekNumbers: event.target.checked })} />
            {ar ? "أرقام الأسابيع" : "Week numbers"}
          </label>
          <h4>{ar ? "اختصارات لوحة المفاتيح" : "Keyboard shortcuts"}</h4>
          <dl className="gcal-keys">
            {shortcuts.map(([keys, label]) => (
              <div key={keys}>
                <dt><kbd>{keys}</kbd></dt>
                <dd>{label}</dd>
              </div>
            ))}
          </dl>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
