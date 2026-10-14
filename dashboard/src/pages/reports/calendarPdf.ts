import { getFontEmbedCSS, toJpeg } from "html-to-image";
import { PDFDocument } from "pdf-lib";
import type { ReportDeadlineRow, ReportDeadlineStatus, ReportVersion, ReportVersionEvent } from "../../api";
import type { Locale } from "../../i18n";
import { ZONE, contrast, intlTag } from "../photography/model";
import { EVENT_COLORS, EVENT_COPY, REPEAT_COPY, STATUS_COLORS, STATUS_COPY, clientLabel } from "./model";

/** A calendar the reports page shows (main calendar is id 0). */
export type PdfCalendar = { id: number; name: string; color: string | null };

export type CalendarPdfInput = {
  locale: Locale;
  /** First day, `YYYY-MM-DD`. */
  from: string;
  /** Last day, inclusive, `YYYY-MM-DD`. */
  to: string;
  deadlines: ReportDeadlineRow[];
  activity: ReportVersion[];
  calendars: PdfCalendar[];
  clientName: string | null;
  generatedBy: string | null;
  /** 0 Sunday, 1 Monday, 6 Saturday: the first column of the month grids. */
  firstDay: 0 | 1 | 6;
  monthGrids: boolean;
  details: boolean;
  onProgress?: (done: number, total: number) => void;
};

// A4 at 96 dpi (CSS pixels) and in PDF points.
const PX = { width: 794, height: 1123 };
const PT = { width: 595.28, height: 841.89 };
const STATUSES: ReportDeadlineStatus[] = ["upcoming", "overdue", "done", "skipped"];
const CELL_LINES = 5;

const CSS = `
.rcp-page { box-sizing: border-box; display: flex; flex-direction: column; background: #fff; color: #1a1224;
  font-family: "IBM Plex Sans Arabic", Inter, system-ui, sans-serif; padding: 34px 38px 24px; overflow: hidden; }
.rcp-page * { box-sizing: border-box; }
.rcp-head { display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding-bottom: 10px; margin-bottom: 14px; border-bottom: 2px solid #e7993a; }
.rcp-head h1 { margin: 0; font-size: 19px; line-height: 1.3; color: #2e0e5c; }
.rcp-head p { margin: 2px 0 0; font-size: 11px; color: #6b6178; }
.rcp-brand { display: flex; align-items: center; gap: 8px; direction: ltr; font-family: Inter, sans-serif;
  font-weight: 700; font-size: 12px; letter-spacing: 0.06em; color: #2e0e5c; white-space: nowrap; }
.rcp-brand img { width: 34px; height: 25px; }
.rcp-brand b { color: #e7993a; }
.rcp-body { flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }
.rcp-foot { display: flex; justify-content: space-between; gap: 12px; margin-top: 10px; padding-top: 6px;
  border-top: 1px solid #ece8f2; font-size: 9.5px; color: #6b6178; }
.rcp-foot span:last-child { direction: ltr; }
.rcp-cover-title { margin: 26px 0 4px; font-size: 30px; font-weight: 700; color: #2e0e5c; }
.rcp-cover-sub { margin: 0 0 22px; font-size: 14px; color: #3c3550; }
.rcp-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 22px; margin-bottom: 22px; font-size: 12px; }
.rcp-facts div { display: flex; gap: 8px; padding: 7px 10px; border-radius: 8px; background: #f7f4fb; }
.rcp-facts dt { color: #6b6178; min-width: 92px; }
.rcp-facts dd { margin: 0; font-weight: 700; }
.rcp-section { margin: 18px 0 8px; font-size: 14px; font-weight: 700; color: #2e0e5c; }
.rcp-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.rcp-stat { border-radius: 10px; padding: 12px; border: 1px solid #ece8f2; }
.rcp-stat strong { display: block; font-size: 24px; line-height: 1.1; }
.rcp-stat span { font-size: 11px; color: #3c3550; }
.rcp-stat i { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-inline-end: 6px; }
.rcp-legend { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 11px; }
.rcp-legend span { display: inline-flex; align-items: center; gap: 6px; }
.rcp-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.rcp-month { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.rcp-weekdays, .rcp-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.rcp-weekdays div { padding: 4px 6px; font-size: 10.5px; font-weight: 700; color: #6b6178; text-align: center; }
.rcp-grid { flex: 1; min-height: 0; border-top: 1px solid #e6e1ec; border-inline-start: 1px solid #e6e1ec; }
.rcp-cell { min-height: 0; overflow: hidden; padding: 3px 4px; border-bottom: 1px solid #e6e1ec;
  border-inline-end: 1px solid #e6e1ec; font-size: 9px; line-height: 1.35; }
.rcp-cell.is-out { background: #faf8fc; color: #b6aec2; }
.rcp-cell.is-out .rcp-chip { opacity: 0.55; }
.rcp-cell b { display: block; font-size: 10.5px; margin-bottom: 1px; }
.rcp-cell.is-today b { color: #e7993a; }
.rcp-chip { display: block; margin-top: 1.5px; padding: 0 4px; border-radius: 3px; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis; }
.rcp-more { display: block; margin-top: 1px; color: #6b6178; }
.rcp-act { display: block; margin-top: 1px; color: #5b5468; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rcp-day { display: flex; justify-content: space-between; gap: 10px; margin-top: 8px; padding: 6px 10px;
  border-radius: 6px; background: #f4f0fa; font-size: 12px; font-weight: 700; color: #2e0e5c; }
.rcp-day span:last-child { font-weight: 500; color: #6b6178; font-size: 10.5px; }
.rcp-row { display: grid; grid-template-columns: 78px minmax(0, 1fr); gap: 10px; padding: 6px 8px;
  border-bottom: 1px solid #f0ecf4; font-size: 10.5px; }
.rcp-time { font-weight: 700; color: #3c3550; direction: ltr; text-align: end; white-space: nowrap; }
[dir="ltr"] .rcp-time { text-align: start; }
.rcp-row-title { font-weight: 700; font-size: 11.5px; line-height: 1.45; }
.rcp-pill { display: inline-block; margin-inline-end: 6px; padding: 0 8px; border-radius: 999px; font-size: 9.5px;
  font-weight: 700; line-height: 1.7; vertical-align: 1px; }
.rcp-meta { margin-top: 2px; color: #5b5468; font-size: 10px; line-height: 1.55; }
.rcp-meta span + span::before { content: " · "; color: #b6aec2; }
.rcp-notes { margin-top: 3px; padding: 3px 7px; border-radius: 4px; background: #fbf7f0; white-space: pre-wrap;
  overflow-wrap: anywhere; font-size: 10px; line-height: 1.55; }
.rcp-empty { margin: 30px 0; text-align: center; color: #6b6178; font-size: 12px; }
`;

let fontCss: Promise<string | undefined> | null = null;

/**
 * The site fonts (IBM Plex Sans Arabic and Inter, Arabic and Latin) as embedded data, so the
 * pages render the same on any device. The Google Fonts stylesheet is cross-origin, so the
 * renderer cannot read it by itself.
 */
function embeddedFonts(): Promise<string | undefined> {
  fontCss ??= (async () => {
    const link = document.querySelector<HTMLLinkElement>('link[href*="fonts.googleapis.com/css"]');
    if (!link) return undefined;
    const css = await (await fetch(link.href)).text();
    const faces = css
      .split("/*")
      .slice(1)
      .map((part) => {
        const close = part.indexOf("*/");
        return { subset: part.slice(0, close).trim(), rule: part.slice(close + 2).trim() };
      })
      .filter((face) => face.subset === "arabic" || face.subset === "latin");
    const rules = await Promise.all(
      faces.map(async ({ rule }) => {
        const url = /url\((https:[^)]+)\)/.exec(rule)?.[1];
        if (!url) return "";
        const blob = await (await fetch(url)).blob();
        const data = await new Promise<string>((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(String(reader.result));
          reader.onerror = () => reject(reader.error);
          reader.readAsDataURL(blob);
        });
        return rule.replace(url, data);
      }),
    );
    return rules.join("\n");
  })().catch(() => {
    fontCss = null;
    return undefined;
  });
  return fontCss;
}

function tx(locale: Locale, ar: string, en: string) {
  return locale === "ar" ? ar : en;
}

function el<K extends keyof HTMLElementTagNameMap>(tag: K, className?: string, text?: string): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text != null) node.textContent = text;
  return node;
}

function utcDay(key: string) {
  return new Date(`${key}T12:00:00Z`);
}

function keyOf(date: Date) {
  return date.toISOString().slice(0, 10);
}

function addDay(key: string, days: number) {
  const date = utcDay(key);
  date.setUTCDate(date.getUTCDate() + days);
  return keyOf(date);
}

function zoneDay(value: string) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date(value));
}

function zoneClock(value: string, locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date(value));
}

function longDay(key: string, locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(utcDay(key));
}

function shortDay(key: string, locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: "UTC", day: "numeric", month: "long", year: "numeric" }).format(utcDay(key));
}

function monthTitle(key: string, locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: "UTC", month: "long", year: "numeric" }).format(utcDay(key));
}

function stampNow(locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: ZONE, dateStyle: "medium", timeStyle: "short", hourCycle: "h23" }).format(new Date());
}

function minutes(from: string, to: string | null) {
  return Math.max(0, Math.round((new Date(to ?? from).getTime() - new Date(from).getTime()) / 60000));
}

function calendarIdOf(row: ReportDeadlineRow) {
  return (row as ReportDeadlineRow & { calendar_id?: number | null }).calendar_id ?? 0;
}

function deadlineColor(row: ReportDeadlineRow, calendars: Map<number, PdfCalendar>) {
  if (row.status !== "upcoming") return STATUS_COLORS[row.status];
  return row.color ?? calendars.get(calendarIdOf(row))?.color ?? STATUS_COLORS.upcoming;
}

function sortDeadlines(rows: ReportDeadlineRow[]) {
  return [...rows].sort((a, b) => (a.date === b.date ? (a.time ?? "").localeCompare(b.time ?? "") : a.date.localeCompare(b.date)));
}

/**
 * The reports calendar of a period as an A4 PDF: a summary, a month grid per month, and every
 * deadline and report activity of each day with its details. Pages are laid out in an off-screen
 * container (so Arabic text and the site fonts render as on screen) and drawn into the PDF.
 */
export async function buildCalendarPdf(input: CalendarPdfInput): Promise<Blob> {
  const { locale } = input;
  const ar = locale === "ar";
  const calendars = new Map(input.calendars.map((calendar) => [calendar.id, calendar]));
  const deadlines = sortDeadlines(input.deadlines);
  const activity = [...input.activity].filter((row) => row.started_at).sort((a, b) => (a.started_at ?? "").localeCompare(b.started_at ?? ""));
  const periodText = input.from === input.to
    ? longDay(input.from, locale)
    : `${shortDay(input.from, locale)} — ${shortDay(input.to, locale)}`;
  const title = tx(locale, "تقويم التقارير", "Reports calendar");

  const host = el("div");
  host.setAttribute("dir", ar ? "rtl" : "ltr");
  host.setAttribute("lang", locale);
  host.style.cssText = "position:fixed;top:0;left:-20000px;z-index:-1;pointer-events:none;";
  const style = el("style");
  style.textContent = CSS;
  host.append(style);
  document.body.append(host);

  const pages: Array<{ node: HTMLElement; landscape: boolean; foot: HTMLElement }> = [];

  function newPage(landscape: boolean, heading: string) {
    const node = el("section", "rcp-page");
    node.style.width = `${landscape ? PX.height : PX.width}px`;
    node.style.height = `${landscape ? PX.width : PX.height}px`;
    const head = el("header", "rcp-head");
    const titles = el("div");
    titles.append(el("h1", undefined, heading), el("p", undefined, [periodText, input.clientName].filter(Boolean).join(" · ")));
    const brand = el("div", "rcp-brand");
    const logo = el("img");
    logo.src = `${import.meta.env.BASE_URL}hummingbird.svg`;
    logo.alt = "";
    const word = el("span");
    word.innerHTML = "HOME <b>of</b> CREATIVITY";
    brand.append(word, logo);
    head.append(titles, brand);
    const body = el("div", "rcp-body");
    const foot = el("footer", "rcp-foot");
    node.append(head, body, foot);
    host.append(node);
    pages.push({ node, landscape, foot });
    return body;
  }

  try {
    // 1. Summary.
    {
      const body = newPage(false, title);
      body.append(el("p", "rcp-cover-title", title), el("p", "rcp-cover-sub", periodText));
      const facts = el("dl", "rcp-facts");
      const fact = (label: string, value: string) => {
        const row = el("div");
        row.append(el("dt", undefined, label), el("dd", undefined, value));
        facts.append(row);
      };
      fact(tx(locale, "العميل", "Client"), input.clientName ?? tx(locale, "كل العملاء", "All clients"));
      fact(tx(locale, "عدد الأيام", "Days"), String(Math.round((utcDay(input.to).getTime() - utcDay(input.from).getTime()) / 86_400_000) + 1));
      fact(tx(locale, "أُنشئ في", "Generated"), stampNow(locale));
      fact(tx(locale, "بواسطة", "By"), input.generatedBy ?? "—");
      body.append(facts);

      body.append(el("p", "rcp-section", tx(locale, "مواعيد التسليم", "Due dates")));
      const stats = el("div", "rcp-stats");
      for (const status of STATUSES) {
        const box = el("div", "rcp-stat");
        const count = el("strong", undefined, String(deadlines.filter((row) => row.status === status).length));
        count.style.color = status === "skipped" ? "#6b6178" : STATUS_COLORS[status];
        const label = el("span");
        const dot = el("i");
        dot.style.background = STATUS_COLORS[status];
        label.append(dot, STATUS_COPY[status][locale]);
        box.append(count, label);
        stats.append(box);
      }
      body.append(stats);

      body.append(el("p", "rcp-section", tx(locale, "نشاط التقارير", "Report activity")));
      const actStats = el("div", "rcp-stats");
      const groups: Array<[string, ReportVersionEvent[], string]> = [
        [tx(locale, "جلسات تعديل", "Editing sessions"), ["created", "edited", "restored"], EVENT_COLORS.edited],
        [tx(locale, "نشر على Drive", "Published to Drive"), ["published"], EVENT_COLORS.published],
        [tx(locale, "تقارير محذوفة", "Deleted reports"), ["deleted"], EVENT_COLORS.deleted],
        [tx(locale, "عمليات حفظ", "Saves"), [], "#d7c4f5"],
      ];
      for (const [label, events, color] of groups) {
        const value = events.length > 0
          ? activity.filter((row) => events.includes(row.event)).length
          : activity.reduce((sum, row) => sum + (row.saves || 0), 0);
        const box = el("div", "rcp-stat");
        const text = el("span");
        const dot = el("i");
        dot.style.background = color;
        text.append(dot, label);
        box.append(el("strong", undefined, String(value)), text);
        actStats.append(box);
      }
      body.append(actStats);

      if (input.calendars.length > 0) {
        body.append(el("p", "rcp-section", tx(locale, "التقاويم", "Calendars")));
        const legend = el("div", "rcp-legend");
        for (const calendar of input.calendars) {
          const item = el("span");
          const swatch = el("i");
          swatch.style.background = calendar.color ?? STATUS_COLORS.upcoming;
          item.append(swatch, `${calendar.name} (${deadlines.filter((row) => calendarIdOf(row) === calendar.id).length})`);
          legend.append(item);
        }
        body.append(legend);
      }

      body.append(el("p", "rcp-section", tx(locale, "دليل الألوان", "Colour key")));
      const key = el("div", "rcp-legend");
      for (const status of STATUSES) {
        const item = el("span");
        const swatch = el("i");
        swatch.style.background = STATUS_COLORS[status];
        item.append(swatch, STATUS_COPY[status][locale]);
        key.append(item);
      }
      for (const event of ["edited", "published", "deleted"] as ReportVersionEvent[]) {
        const item = el("span");
        const swatch = el("i");
        swatch.style.background = EVENT_COLORS[event];
        item.append(swatch, EVENT_COPY[event][locale]);
        key.append(item);
      }
      body.append(key);
    }

    const byDay = new Map<string, { deadlines: ReportDeadlineRow[]; activity: ReportVersion[] }>();
    const bucket = (key: string) => {
      let entry = byDay.get(key);
      if (!entry) {
        entry = { deadlines: [], activity: [] };
        byDay.set(key, entry);
      }
      return entry;
    };
    for (const row of deadlines) bucket(row.date).deadlines.push(row);
    for (const row of activity) bucket(zoneDay(row.started_at as string)).activity.push(row);

    // 2. One month grid per month of the period.
    if (input.monthGrids) {
      const today = zoneDay(new Date().toISOString());
      let monthStart = `${input.from.slice(0, 7)}-01`;
      while (monthStart <= input.to) {
        const first = utcDay(monthStart);
        const nextMonth = keyOf(new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 1, 12)));
        const lead = (first.getUTCDay() - input.firstDay + 7) % 7;
        const gridStart = addDay(monthStart, -lead);
        const days = Math.ceil((lead + (utcDay(nextMonth).getTime() - first.getTime()) / 86_400_000) / 7) * 7;

        const body = newPage(true, `${title} — ${monthTitle(monthStart, locale)}`);
        const month = el("div", "rcp-month");
        const weekdays = el("div", "rcp-weekdays");
        for (let index = 0; index < 7; index += 1) {
          weekdays.append(el("div", undefined, new Intl.DateTimeFormat(intlTag(locale), { timeZone: "UTC", weekday: "long" }).format(utcDay(addDay(gridStart, index)))));
        }
        const grid = el("div", "rcp-grid");
        grid.style.gridTemplateRows = `repeat(${days / 7}, minmax(0, 1fr))`;
        for (let index = 0; index < days; index += 1) {
          const key = addDay(gridStart, index);
          const outside = key < monthStart || key >= nextMonth || key < input.from || key > input.to;
          const cell = el("div", `rcp-cell${outside ? " is-out" : ""}${key === today ? " is-today" : ""}`);
          cell.append(el("b", undefined, String(utcDay(key).getUTCDate())));
          const entry = outside ? undefined : byDay.get(key);
          if (entry) {
            const edits = entry.activity.filter((row) => row.event !== "published" && row.event !== "deleted").length;
            const publishes = entry.activity.filter((row) => row.event === "published").length;
            const room = CELL_LINES - (edits > 0 ? 1 : 0) - (publishes > 0 ? 1 : 0);
            entry.deadlines.slice(0, Math.max(room, 1)).forEach((row) => {
              const color = deadlineColor(row, calendars);
              const who = input.clientName ? "" : `${clientLabel(row.client)} · `;
              const chip = el("span", "rcp-chip", `${row.status === "done" ? "✓ " : ""}${row.time ? `${row.time} ` : ""}${who}${row.title}`);
              chip.style.background = color;
              chip.style.color = contrast(color);
              cell.append(chip);
            });
            const hidden = entry.deadlines.length - Math.max(room, 1);
            if (hidden > 0) cell.append(el("span", "rcp-more", tx(locale, `+${hidden} أخرى`, `+${hidden} more`)));
            if (edits > 0) cell.append(el("span", "rcp-act", `✎ ${edits} ${tx(locale, "جلسة تعديل", "editing")}`));
            if (publishes > 0) cell.append(el("span", "rcp-act", `⬆ ${publishes} ${tx(locale, "نشر", "published")}`));
          }
          grid.append(cell);
        }
        month.append(weekdays, grid);
        body.append(month);
        monthStart = nextMonth;
      }
    }

    // 3. Every day with its full details, flowing over as many pages as it needs.
    if (input.details) {
      const heading = tx(locale, `${title} — التفاصيل`, `${title} — details`);
      let body = newPage(false, heading);
      const keys = [...byDay.keys()].filter((key) => key >= input.from && key <= input.to).sort();
      if (keys.length === 0) body.append(el("p", "rcp-empty", tx(locale, "لا مواعيد ولا نشاط في هذه الفترة.", "No deadlines or activity in this period.")));

      const overflows = () => body.scrollHeight > body.clientHeight + 1;
      const dayHead = (key: string, entry: { deadlines: ReportDeadlineRow[]; activity: ReportVersion[] }, continued: boolean) => {
        const head = el("div", "rcp-day");
        const counts = [
          entry.deadlines.length > 0 ? tx(locale, `${entry.deadlines.length} موعد`, `${entry.deadlines.length} due`) : "",
          entry.activity.length > 0 ? tx(locale, `${entry.activity.length} نشاط`, `${entry.activity.length} activity`) : "",
        ].filter(Boolean).join(" · ");
        head.append(el("span", undefined, `${longDay(key, locale)}${continued ? tx(locale, " (تابع)", " (continued)") : ""}`), el("span", undefined, counts));
        return head;
      };

      for (const key of keys) {
        const entry = byDay.get(key)!;
        const rows: HTMLElement[] = [];
        for (const row of entry.deadlines) {
          const line = el("div", "rcp-row");
          line.append(el("div", "rcp-time", row.all_day || !row.time ? tx(locale, "طوال اليوم", "All day") : row.time));
          const main = el("div");
          const titleLine = el("div", "rcp-row-title");
          const pill = el("span", "rcp-pill", STATUS_COPY[row.status][locale]);
          const color = deadlineColor(row, calendars);
          pill.style.background = row.status === "upcoming" ? color : STATUS_COLORS[row.status];
          pill.style.color = contrast(row.status === "upcoming" ? color : STATUS_COLORS[row.status]);
          titleLine.append(pill, `${input.clientName ? "" : `${clientLabel(row.client)} — `}${row.title}`);
          main.append(titleLine);
          const meta = el("div", "rcp-meta");
          const add = (text: string | null | undefined) => {
            if (text) meta.append(el("span", undefined, text));
          };
          add(`${tx(locale, "التكرار", "Repeat")}: ${REPEAT_COPY[row.repeat][locale]}${row.repeat !== "none" && row.repeat_until ? ` ${tx(locale, "حتى", "until")} ${shortDay(row.repeat_until, locale)}` : ""}`);
          if (input.calendars.length > 0) add(`${tx(locale, "التقويم", "Calendar")}: ${calendars.get(calendarIdOf(row))?.name ?? tx(locale, "الرئيسي", "Main")}`);
          if (row.moved) add(tx(locale, `نُقل من ${shortDay(row.occurs_on, locale)}`, `Moved from ${shortDay(row.occurs_on, locale)}`));
          if (row.report) add(`${tx(locale, "التقرير", "Report")}: ${row.report.title}`);
          if (row.status === "done") add(`${tx(locale, "سُلّم", "Delivered")}${row.done_by ? ` ${tx(locale, "بواسطة", "by")} ${row.done_by}` : ""}${row.done_at ? ` — ${new Intl.DateTimeFormat(intlTag(locale), { timeZone: ZONE, dateStyle: "medium", timeStyle: "short", hourCycle: "h23" }).format(new Date(row.done_at))}` : ""}`);
          main.append(meta);
          if (row.notes?.trim()) main.append(el("div", "rcp-notes", row.notes.trim().slice(0, 1500)));
          line.append(main);
          rows.push(line);
        }
        for (const row of entry.activity) {
          const line = el("div", "rcp-row");
          const start = row.started_at as string;
          line.append(el("div", "rcp-time", row.ended_at && minutes(start, row.ended_at) > 0 ? `${zoneClock(start, locale)}–${zoneClock(row.ended_at, locale)}` : zoneClock(start, locale)));
          const main = el("div");
          const titleLine = el("div", "rcp-row-title");
          const pill = el("span", "rcp-pill", EVENT_COPY[row.event][locale]);
          pill.style.background = EVENT_COLORS[row.event];
          pill.style.color = "#1a1224";
          titleLine.append(pill, `${row.user?.name ?? tx(locale, "النظام", "System")} — ${row.report_title ?? row.title}`);
          main.append(titleLine);
          const meta = el("div", "rcp-meta");
          const add = (text: string | null | undefined) => {
            if (text) meta.append(el("span", undefined, text));
          };
          if (!input.clientName && row.client) add(`${tx(locale, "العميل", "Client")}: ${clientLabel(row.client)}`);
          const span = row.ended_at ? minutes(start, row.ended_at) : 0;
          if (span > 0) add(tx(locale, `${span} دقيقة`, `${span} min`));
          if (row.saves > 1) add(tx(locale, `${row.saves} عملية حفظ`, `${row.saves} saves`));
          if (row.report_title && row.title !== row.report_title) add(`${tx(locale, "العنوان وقتها", "Title then")}: ${row.title}`);
          if (row.detail?.files?.length) add(`${tx(locale, "الملفات", "Files")}: ${row.detail.files.join("، ")}`);
          if (meta.childElementCount > 0) main.append(meta);
          line.append(main);
          rows.push(line);
        }

        // The day title never sits alone at the bottom of a page.
        const head = dayHead(key, entry, false);
        body.append(head, rows[0]);
        if (overflows() && body.childElementCount > 2) {
          head.remove();
          rows[0].remove();
          body = newPage(false, heading);
          body.append(head, rows[0]);
        }
        for (const line of rows.slice(1)) {
          body.append(line);
          if (overflows()) {
            line.remove();
            body = newPage(false, heading);
            body.append(dayHead(key, entry, true), line);
          }
        }
      }
    }

    // Page numbers, now that the count is known.
    pages.forEach((page, index) => {
      page.foot.append(
        el("span", undefined, `${tx(locale, "بيت الإبداع", "Home of Creativity")} · ${tx(locale, "أُنشئ", "Generated")} ${stampNow(locale)}`),
        el("span", undefined, `${index + 1} / ${pages.length}`),
      );
    });

    // Draw the pages into the PDF.
    const pdf = await PDFDocument.create();
    pdf.setTitle(`${title} — ${periodText}`);
    pdf.setCreator("Home of Creativity");
    const fontEmbedCSS = (await embeddedFonts()) ?? (await getFontEmbedCSS(pages[0].node).catch(() => undefined));
    for (let index = 0; index < pages.length; index += 1) {
      const page = pages[index];
      const jpeg = await toJpeg(page.node, { quality: 0.9, pixelRatio: 2, backgroundColor: "#ffffff", fontEmbedCSS, cacheBust: false });
      const image = await pdf.embedJpg(jpeg);
      const size = page.landscape ? { width: PT.height, height: PT.width } : PT;
      pdf.addPage([size.width, size.height]).drawImage(image, { x: 0, y: 0, width: size.width, height: size.height });
      input.onProgress?.(index + 1, pages.length);
    }
    const bytes = await pdf.save();
    return new Blob([bytes as BlobPart], { type: "application/pdf" });
  } finally {
    host.remove();
  }
}
