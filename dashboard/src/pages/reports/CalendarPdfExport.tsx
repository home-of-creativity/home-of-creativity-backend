import { FileDown, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { api, type ReportDeadlineRow, type ReportDeadlineStatus, type ReportVersion, type ReportVersionEvent } from "../../api";
import { useAuth } from "../../auth";
import type { Locale } from "../../i18n";
import { ZONE } from "../photography/model";
import { buildCalendarPdf, type PdfCalendar } from "./calendarPdf";
import { STATUS_COPY, clientLabel, saveBlob } from "./model";

type Preset = "view" | "month" | "quarter" | "year" | "custom";
type Layer = "edits" | "publishes" | "deletes";

const STATUSES: ReportDeadlineStatus[] = ["upcoming", "overdue", "done", "skipped"];
const LAYERS: Record<Layer, { events: ReportVersionEvent[]; ar: string; en: string }> = {
  edits: { events: ["created", "edited", "restored"], ar: "جلسات التعديل", en: "Editing sessions" },
  publishes: { events: ["published"], ar: "النشر على Drive", en: "Published to Drive" },
  deletes: { events: ["deleted"], ar: "التقارير المحذوفة", en: "Deleted reports" },
};
/** Longest period one export takes: three years of month grids and details. */
const MAX_DAYS = 3 * 366;
const PREFS_KEY = "hoc:report-cal:prefs";

type Prefs = { firstDay?: 0 | 1 | 6; statuses?: ReportDeadlineStatus[]; activity?: Layer[]; hiddenCalendars?: number[] };

function readPrefs(): Prefs {
  try {
    return JSON.parse(window.localStorage.getItem(PREFS_KEY) ?? "{}") as Prefs;
  } catch {
    return {};
  }
}

function today() {
  return new Intl.DateTimeFormat("en-CA", { timeZone: ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
}

function shift(key: string, days: number) {
  const date = new Date(`${key}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function monthStart(key: string, offset = 0) {
  const date = new Date(`${key}T12:00:00Z`);
  return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + offset, 1, 12)).toISOString().slice(0, 10);
}

function presetRange(preset: Preset, view: { from: string; to: string } | null): { from: string; to: string } {
  const now = today();
  if (preset === "view" && view) return view;
  if (preset === "quarter") {
    const month = Number(now.slice(5, 7)) - 1;
    const first = `${now.slice(0, 4)}-${String(month - (month % 3) + 1).padStart(2, "0")}-01`;
    return { from: first, to: shift(monthStart(first, 3), -1) };
  }
  if (preset === "year") return { from: `${now.slice(0, 4)}-01-01`, to: `${now.slice(0, 4)}-12-31` };
  return { from: monthStart(now), to: shift(monthStart(now, 1), -1) };
}

/** The months of the period as API ranges (the calendar API takes at most a year per call). */
function chunks(from: string, to: string) {
  const list: Array<{ from: string; to: string }> = [];
  let start = from;
  while (start <= to) {
    const next = monthStart(start, 1);
    const end = next <= to ? next : shift(to, 1);
    list.push({ from: start, to: end });
    start = next;
  }
  return list;
}

/**
 * «تصدير PDF» for the reports calendar: pick a period (the one on screen, this month, quarter,
 * year, or any dates) and what to include, and get the calendar of that period with every
 * deadline and report activity in full detail.
 */
export function CalendarPdfExport({
  locale,
  client,
  range,
  className = "btn btn-ghost btn-sm",
}: {
  locale: Locale;
  client?: { id: number; name: string; company_name: string | null };
  /** The dates the calendar shows now: FullCalendar's `startStr` and exclusive `endStr`. */
  range?: { from: string; to: string } | null;
  className?: string;
}) {
  const ar = locale === "ar";
  const tx = (arText: string, enText: string) => (ar ? arText : enText);
  const { user } = useAuth();
  const view = range ? { from: range.from.slice(0, 10), to: shift(range.to.slice(0, 10), -1) } : null;
  const [open, setOpen] = useState(false);
  const [preset, setPreset] = useState<Preset>(view ? "view" : "month");
  const [from, setFrom] = useState(() => presetRange(view ? "view" : "month", view).from);
  const [to, setTo] = useState(() => presetRange(view ? "view" : "month", view).to);
  const [monthGrids, setMonthGrids] = useState(true);
  const [details, setDetails] = useState(true);
  const [statuses, setStatuses] = useState<ReportDeadlineStatus[]>(STATUSES);
  const [layers, setLayers] = useState<Layer[]>(["edits", "publishes"]);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    // Start from what the calendar shows: its statuses and activity layers.
    const prefs = readPrefs();
    if (prefs.statuses?.length) setStatuses(prefs.statuses.filter((status) => STATUSES.includes(status)));
    if (prefs.activity) setLayers(prefs.activity.filter((layer) => layer in LAYERS));
    const start = view ? "view" : "month";
    setPreset(start);
    const next = presetRange(start, view);
    setFrom(next.from);
    setTo(next.to);
    // Only when the dialog opens; the view range is read at that moment.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !busy) setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, busy]);

  function pick(next: Preset) {
    setPreset(next);
    if (next === "custom") return;
    const span = presetRange(next, view);
    setFrom(span.from);
    setTo(span.to);
  }

  function toggle<T>(list: T[], value: T, on: boolean) {
    return on ? [...list, value] : list.filter((item) => item !== value);
  }

  async function exportPdf(event: FormEvent) {
    event.preventDefault();
    if (!from || !to || from > to) {
      toast.error(tx("اختر فترة صحيحة: البداية قبل النهاية.", "Pick a valid period: the start before the end."));
      return;
    }
    const days = Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1;
    if (days > MAX_DAYS) {
      toast.error(tx("الفترة أطول من ثلاث سنوات. قسّمها على أكثر من ملف.", "The period is longer than three years. Split it into several files."));
      return;
    }
    if (!monthGrids && !details) {
      toast.error(tx("اختر الشبكة الشهرية أو التفاصيل.", "Choose the month grids or the details."));
      return;
    }

    setBusy(tx("تُحمَّل بيانات الفترة…", "Loading the period…"));
    try {
      const deadlines = new Map<string, ReportDeadlineRow>();
      const activity = new Map<number, ReportVersion>();
      const calendars = new Map<number, PdfCalendar>();
      const parts = chunks(from, to);
      for (let index = 0; index < parts.length; index += 3) {
        const results = await Promise.all(parts.slice(index, index + 3).map((part) => api.reportCalendar(part.from, part.to, client?.id)));
        for (const res of results) {
          res.data.deadlines.forEach((row) => deadlines.set(row.key, row));
          res.data.activity.forEach((row) => activity.set(row.id, row));
          // Named calendars, when the API lists them.
          const listed = (res.data as { calendars?: Array<{ id: number; name: string; color: string | null }> }).calendars ?? [];
          listed.forEach((calendar) => calendars.set(calendar.id, { id: calendar.id, name: calendar.name, color: calendar.color }));
        }
        setBusy(tx(`تُحمَّل البيانات… ${Math.min(index + 3, parts.length)} / ${parts.length}`, `Loading… ${Math.min(index + 3, parts.length)} / ${parts.length}`));
      }

      const hidden = new Set(readPrefs().hiddenCalendars ?? []);
      const calendarOf = (row: ReportDeadlineRow) => (row as ReportDeadlineRow & { calendar_id?: number | null }).calendar_id ?? 0;
      const shownEvents = new Set(layers.flatMap((layer) => LAYERS[layer].events));
      const keptDeadlines = [...deadlines.values()].filter((row) => statuses.includes(row.status) && !hidden.has(calendarOf(row)) && row.date >= from && row.date <= to);
      const keptActivity = [...activity.values()].filter((row) => shownEvents.has(row.event));
      if (calendars.size > 0 && !calendars.has(0) && keptDeadlines.some((row) => calendarOf(row) === 0)) {
        calendars.set(0, { id: 0, name: tx("التقويم الرئيسي", "Main calendar"), color: null });
      }

      setBusy(tx("تُجهَّز الصفحات…", "Laying out the pages…"));
      const blob = await buildCalendarPdf({
        locale,
        from,
        to,
        deadlines: keptDeadlines,
        activity: keptActivity,
        calendars: [...calendars.values()].filter((calendar) => !hidden.has(calendar.id)),
        clientName: client ? clientLabel(client) : null,
        generatedBy: user?.name ?? null,
        firstDay: readPrefs().firstDay ?? 6,
        monthGrids,
        details,
        onProgress: (done, total) => setBusy(tx(`تُرسم الصفحة ${done} من ${total}…`, `Drawing page ${done} of ${total}…`)),
      });
      const who = client ? `-${clientLabel(client).replace(/[\\/:*?"<>|\s]+/g, "-")}` : "";
      saveBlob(blob, `reports-calendar${who}-${from}_${to}.pdf`);
      toast.success(tx("نُزّل ملف PDF.", "The PDF was downloaded."));
      setOpen(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }

  const presets: Array<{ id: Preset; label: string }> = [
    ...(view ? [{ id: "view" as const, label: tx("الفترة المعروضة", "Shown period") }] : []),
    { id: "month", label: tx("هذا الشهر", "This month") },
    { id: "quarter", label: tx("هذا الربع", "This quarter") },
    { id: "year", label: tx("هذه السنة", "This year") },
    { id: "custom", label: tx("فترة مخصصة", "Custom") },
  ];

  return (
    <>
      <button type="button" className={className} onClick={() => setOpen(true)} title={tx("تصدير التقويم PDF", "Export the calendar as PDF")}>
        <FileDown size={15} aria-hidden="true" />
        <span>{tx("تصدير PDF", "Export PDF")}</span>
      </button>
      {open ? (
        <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-pdf-title">
          <div className="form-dialog-backdrop" onClick={() => (busy ? undefined : setOpen(false))} />
          <div className="form-dialog-panel">
            <div className="form-dialog-head">
              <h2 className="form-dialog-title" id="rc-pdf-title">{tx("تصدير التقويم PDF", "Export the calendar as PDF")}</h2>
              <button type="button" className="form-dialog-close" onClick={() => setOpen(false)} disabled={busy !== null} aria-label={tx("إغلاق", "Close")}>
                <X aria-hidden />
              </button>
            </div>
            <form className="form-dialog-form" onSubmit={(event) => void exportPdf(event)}>
              <div className="form-dialog-body" style={{ display: "grid", gap: "0.9rem" }}>
                {client ? <p className="muted" style={{ margin: 0 }}>{tx("العميل", "Client")}: <strong>{clientLabel(client)}</strong></p> : null}
                <fieldset style={{ border: 0, margin: 0, padding: 0, display: "grid", gap: "0.45rem" }}>
                  <legend className="field-label" style={{ marginBottom: "0.35rem" }}>{tx("الفترة", "Period")}</legend>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: "0.4rem" }}>
                    {presets.map((item) => (
                      <button key={item.id} type="button" className={preset === item.id ? "btn btn-primary btn-sm" : "btn btn-ghost btn-sm"} aria-pressed={preset === item.id} onClick={() => pick(item.id)}>
                        {item.label}
                      </button>
                    ))}
                  </div>
                  <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0.6rem" }}>
                    <label className="field-label">
                      {tx("من", "From")}
                      <input className="field" type="date" value={from} max={to} onChange={(event) => { setPreset("custom"); setFrom(event.target.value); }} />
                    </label>
                    <label className="field-label">
                      {tx("إلى", "To")}
                      <input className="field" type="date" value={to} min={from} onChange={(event) => { setPreset("custom"); setTo(event.target.value); }} />
                    </label>
                  </div>
                </fieldset>
                <fieldset style={{ border: 0, margin: 0, padding: 0, display: "grid", gap: "0.35rem" }}>
                  <legend className="field-label" style={{ marginBottom: "0.35rem" }}>{tx("محتوى الملف", "Contents")}</legend>
                  <label><input type="checkbox" checked={monthGrids} onChange={(event) => setMonthGrids(event.target.checked)} /> {tx("شبكة لكل شهر (عرض التقويم)", "A grid per month (calendar view)")}</label>
                  <label><input type="checkbox" checked={details} onChange={(event) => setDetails(event.target.checked)} /> {tx("تفاصيل كل يوم: الموعد، الحالة، العميل، التكرار، الملاحظات، التسليم", "Every day in full: time, status, client, repeat, notes, delivery")}</label>
                </fieldset>
                <fieldset style={{ border: 0, margin: 0, padding: 0, display: "grid", gap: "0.35rem" }}>
                  <legend className="field-label" style={{ marginBottom: "0.35rem" }}>{tx("مواعيد التسليم", "Due dates")}</legend>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: "0.35rem 1rem" }}>
                    {STATUSES.map((status) => (
                      <label key={status}><input type="checkbox" checked={statuses.includes(status)} onChange={(event) => setStatuses((list) => toggle(list, status, event.target.checked))} /> {STATUS_COPY[status][locale]}</label>
                    ))}
                  </div>
                </fieldset>
                <fieldset style={{ border: 0, margin: 0, padding: 0, display: "grid", gap: "0.35rem" }}>
                  <legend className="field-label" style={{ marginBottom: "0.35rem" }}>{tx("نشاط التقارير", "Report activity")}</legend>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: "0.35rem 1rem" }}>
                    {(Object.keys(LAYERS) as Layer[]).map((layer) => (
                      <label key={layer}><input type="checkbox" checked={layers.includes(layer)} onChange={(event) => setLayers((list) => toggle(list, layer, event.target.checked))} /> {LAYERS[layer][locale]}</label>
                    ))}
                  </div>
                </fieldset>
                <p className="muted" style={{ margin: 0 }}>{tx("التقاويم المخفية في الصفحة تبقى خارج الملف.", "Calendars hidden on the page stay out of the file.")}</p>
                {busy ? <p role="status" style={{ margin: 0, fontWeight: 600 }}>{busy}</p> : null}
              </div>
              <div className="form-dialog-actions">
                <button type="submit" className="btn btn-primary" disabled={busy !== null} aria-busy={busy !== null}>
                  <FileDown size={15} aria-hidden="true" />
                  {tx("تنزيل PDF", "Download PDF")}
                </button>
                <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)} disabled={busy !== null}>{tx("إلغاء", "Cancel")}</button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </>
  );
}
