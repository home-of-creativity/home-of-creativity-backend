import type { PhotographyBookingRow } from "../../api";
import type { Locale } from "../../i18n";

export type Copy = { ar: string; en: string };

export const ZONE = "Asia/Damascus";

/** Google Calendar's event palette; the ids are Google's colorId values. */
export const GOOGLE_COLORS: Array<{ id: string; hex: string; name: Copy }> = [
  { id: "1", hex: "#7986cb", name: { ar: "خزامى", en: "Lavender" } },
  { id: "2", hex: "#33b679", name: { ar: "مريمية", en: "Sage" } },
  { id: "3", hex: "#8e24aa", name: { ar: "عنب", en: "Grape" } },
  { id: "4", hex: "#e67c73", name: { ar: "فلامنغو", en: "Flamingo" } },
  { id: "5", hex: "#f6bf26", name: { ar: "موز", en: "Banana" } },
  { id: "6", hex: "#f4511e", name: { ar: "يوسفي", en: "Tangerine" } },
  { id: "7", hex: "#039be5", name: { ar: "طاووس", en: "Peacock" } },
  { id: "8", hex: "#616161", name: { ar: "رمادي", en: "Graphite" } },
  { id: "9", hex: "#3f51b5", name: { ar: "توت", en: "Blueberry" } },
  { id: "10", hex: "#0b8043", name: { ar: "ريحان", en: "Basil" } },
  { id: "11", hex: "#d50000", name: { ar: "طماطم", en: "Tomato" } },
];

export const DEFAULT_CALENDAR_COLOR = "#2e0e5c";

export function eventColor(colorId: string | null, calendarColor: string | null) {
  return GOOGLE_COLORS.find((color) => color.id === colorId)?.hex ?? calendarColor ?? DEFAULT_CALENDAR_COLOR;
}

/** Dark ink on light colors, white on dark ones. */
export function contrast(hex: string) {
  const value = hex.replace("#", "");
  if (value.length !== 6) return "#ffffff";
  const [r, g, b] = [0, 2, 4].map((index) => parseInt(value.slice(index, index + 2), 16) / 255);
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return luminance > 0.6 ? "#1a1224" : "#ffffff";
}

export const statusCopy: Record<string, Copy> = {
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

export type Tone = "queue" | "waiting" | "move" | "mine" | "agreed" | "done" | "noshow" | "closed";

export const TONE_COLORS: Record<Tone, string> = {
  queue: "#f0b56a",
  waiting: "#f3de86",
  move: "#d7c4f5",
  mine: "#8edfd6",
  agreed: "#c9d7f5",
  done: "#d9d4e0",
  noshow: "#f2b8b5",
  closed: "#ece8f0",
};

export function bookingTone(row: PhotographyBookingRow, employeeId: number | null): Tone {
  switch (row.status) {
    case "pending_staff":
      return "queue";
    case "needs_client":
      return "waiting";
    case "rescheduling":
      return "move";
    case "confirmed":
      return employeeId != null && row.employee?.id === employeeId ? "mine" : "agreed";
    case "done":
      return "done";
    case "noshow":
      return "noshow";
    default:
      return "closed";
  }
}

export type Layer = "queue" | "waiting" | "agreed" | "finished";

export function bookingLayer(row: PhotographyBookingRow): Layer | "closed" {
  if (row.status === "pending_staff" || row.status === "rescheduling") return "queue";
  if (row.status === "needs_client") return "waiting";
  if (row.status === "confirmed") return "agreed";
  if (row.status === "done" || row.status === "noshow") return "finished";
  return "closed";
}

export type ViewId = "timeGridDay" | "timeGridFourDay" | "timeGridWeek" | "dayGridMonth" | "multiMonthYear" | "listMonth";

export const VIEWS: Array<{ id: ViewId; key: string; label: Copy }> = [
  { id: "timeGridDay", key: "d", label: { ar: "يوم", en: "Day" } },
  { id: "timeGridFourDay", key: "x", label: { ar: "4 أيام", en: "4 days" } },
  { id: "timeGridWeek", key: "w", label: { ar: "أسبوع", en: "Week" } },
  { id: "dayGridMonth", key: "m", label: { ar: "شهر", en: "Month" } },
  { id: "multiMonthYear", key: "y", label: { ar: "سنة", en: "Year" } },
  { id: "listMonth", key: "a", label: { ar: "جدول", en: "Schedule" } },
];

export type Prefs = {
  view: ViewId;
  firstDay: 0 | 1 | 6;
  hideFriday: boolean;
  slotMinutes: 15 | 30 | 60;
  weekNumbers: boolean;
  hidden: string[];
  layers: Layer[];
  mineOnly: boolean;
  showClosed: boolean;
  showBookings: boolean;
};

export const DEFAULT_PREFS: Prefs = {
  view: "timeGridWeek",
  firstDay: 6,
  hideFriday: false,
  slotMinutes: 30,
  weekNumbers: false,
  hidden: [],
  layers: ["queue", "waiting", "agreed", "finished"],
  mineOnly: false,
  showClosed: false,
  showBookings: true,
};

const PREFS_KEY = "hoc:photo-cal:prefs";

export function loadPrefs(): Prefs {
  try {
    const raw = window.localStorage.getItem(PREFS_KEY);
    // Seven columns do not fit a phone; start narrow screens on four days.
    if (!raw) return window.innerWidth < 640 ? { ...DEFAULT_PREFS, view: "timeGridFourDay" } : DEFAULT_PREFS;
    const parsed = JSON.parse(raw) as Partial<Prefs>;
    return {
      ...DEFAULT_PREFS,
      ...parsed,
      view: VIEWS.some((view) => view.id === parsed.view) ? (parsed.view as ViewId) : DEFAULT_PREFS.view,
    };
  } catch {
    return DEFAULT_PREFS;
  }
}

export function savePrefs(prefs: Prefs) {
  try {
    window.localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
  } catch {
    // Private windows refuse storage; the calendar still works with defaults.
  }
}

/** Damascus wall-clock parts of an instant. */
export function zoned(date: Date) {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? "00";
  return { date: `${get("year")}-${get("month")}-${get("day")}`, time: `${get("hour")}:${get("minute")}` };
}

/** "YYYY-MM-DD HH:mm" in Damascus, the format the booking routes take. */
export function wallClock(value: Date | string) {
  const { date, time } = zoned(typeof value === "string" ? new Date(value) : value);
  return `${date} ${time}`;
}

export function todayKey() {
  return zoned(new Date()).date;
}

export function addDays(key: string, days: number) {
  const [year, month, day] = key.split("-").map(Number);
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
}

/** Adds minutes to a Damascus "date + time" pair without touching the browser's zone. */
export function addMinutes(date: string, time: string, minutes: number) {
  const [year, month, day] = date.split("-").map(Number);
  const [hour, minute] = time.split(":").map(Number);
  const moved = new Date(Date.UTC(year, month - 1, day, hour, minute + minutes));
  return { date: moved.toISOString().slice(0, 10), time: moved.toISOString().slice(11, 16) };
}

export function minutesBetween(startDate: string, startTime: string, endDate: string, endTime: string) {
  const toMs = (date: string, time: string) => {
    const [year, month, day] = date.split("-").map(Number);
    const [hour, minute] = time.split(":").map(Number);
    return Date.UTC(year, month - 1, day, hour, minute);
  };
  return Math.round((toMs(endDate, endTime) - toMs(startDate, startTime)) / 60000);
}

export function intlTag(locale: Locale) {
  return locale === "ar" ? "ar-SY-u-nu-latn" : "en-GB";
}

export function formatRangeText(locale: Locale, start: string, end: string, allDay: boolean) {
  const tag = intlTag(locale);
  if (allDay) {
    const first = new Date(`${start}T12:00:00Z`);
    const last = new Date(`${addDays(end, -1)}T12:00:00Z`);
    const day = new Intl.DateTimeFormat(tag, { timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric" });
    return start >= addDays(end, -1) ? day.format(first) : `${day.format(first)} – ${day.format(last)}`;
  }
  const from = new Date(start);
  const to = new Date(end);
  const day = new Intl.DateTimeFormat(tag, { timeZone: ZONE, weekday: "long", day: "numeric", month: "long" });
  const time = new Intl.DateTimeFormat(tag, { timeZone: ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  if (zoned(from).date === zoned(to).date) return `${day.format(from)} · ${time.format(from)} – ${time.format(to)}`;
  return `${day.format(from)} ${time.format(from)} – ${day.format(to)} ${time.format(to)}`;
}

/** Google descriptions may hold HTML; the tab shows them as plain text. */
export function plainText(html: string | null) {
  if (!html) return "";
  const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, "\n").replace(/<\/p>/gi, "\n"), "text/html");
  return (doc.body.textContent ?? "").trim();
}

// ---------------------------------------------------------------- recurrence

export const WEEKDAYS = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"] as const;
export type Weekday = (typeof WEEKDAYS)[number];

export const WEEKDAY_NAMES: Record<Weekday, Copy> = {
  SU: { ar: "الأحد", en: "Sunday" },
  MO: { ar: "الاثنين", en: "Monday" },
  TU: { ar: "الثلاثاء", en: "Tuesday" },
  WE: { ar: "الأربعاء", en: "Wednesday" },
  TH: { ar: "الخميس", en: "Thursday" },
  FR: { ar: "الجمعة", en: "Friday" },
  SA: { ar: "السبت", en: "Saturday" },
};

export type Rule = {
  freq: "DAILY" | "WEEKLY" | "MONTHLY" | "YEARLY";
  interval: number;
  byday: Weekday[];
  monthly: "day" | "nth";
  end: "never" | "until" | "count";
  until: string;
  count: number;
};

export type RepeatPreset = "none" | "daily" | "weekly" | "workdays" | "monthly" | "monthlyNth" | "yearly" | "custom";

function weekdayOf(date: string): Weekday {
  const [year, month, day] = date.split("-").map(Number);
  return WEEKDAYS[new Date(Date.UTC(year, month - 1, day)).getUTCDay()];
}

function nthOf(date: string) {
  return Math.ceil(Number(date.slice(8, 10)) / 7);
}

export function defaultRule(startDate: string): Rule {
  return { freq: "WEEKLY", interval: 1, byday: [weekdayOf(startDate)], monthly: "day", end: "never", until: addDays(startDate, 30), count: 10 };
}

export function parseRule(lines: string[] | null | undefined, startDate: string): Rule | null {
  const line = (lines ?? []).find((item) => item.toUpperCase().startsWith("RRULE:"));
  if (!line) return null;
  const parts = Object.fromEntries(
    line
      .slice(6)
      .split(";")
      .map((pair) => pair.split("=") as [string, string])
      .map(([key, value]) => [key.toUpperCase(), value ?? ""]),
  );
  const rule = defaultRule(startDate);
  rule.freq = (["DAILY", "WEEKLY", "MONTHLY", "YEARLY"].includes(parts.FREQ) ? parts.FREQ : "WEEKLY") as Rule["freq"];
  rule.interval = Math.max(1, Number(parts.INTERVAL ?? 1) || 1);
  if (parts.BYDAY) {
    const codes = parts.BYDAY.split(",").map((code: string) => code.replace(/^[+-]?\d+/, "")) as Weekday[];
    rule.byday = codes.filter((code) => WEEKDAYS.includes(code));
    if (rule.freq === "MONTHLY" && /\d/.test(parts.BYDAY)) rule.monthly = "nth";
  }
  if (parts.UNTIL) {
    rule.end = "until";
    rule.until = `${parts.UNTIL.slice(0, 4)}-${parts.UNTIL.slice(4, 6)}-${parts.UNTIL.slice(6, 8)}`;
  } else if (parts.COUNT) {
    rule.end = "count";
    rule.count = Math.max(1, Number(parts.COUNT) || 1);
  }
  return rule;
}

export function buildRule(rule: Rule, startDate: string, allDay = false): string {
  const parts = [`FREQ=${rule.freq}`];
  if (rule.interval > 1) parts.push(`INTERVAL=${rule.interval}`);
  if (rule.freq === "WEEKLY") parts.push(`BYDAY=${(rule.byday.length ? rule.byday : [weekdayOf(startDate)]).join(",")}`);
  if (rule.freq === "MONTHLY" && rule.monthly === "nth") parts.push(`BYDAY=${nthOf(startDate)}${weekdayOf(startDate)}`);
  if (rule.end === "until" && rule.until) parts.push(`UNTIL=${rule.until.replace(/-/g, "")}${allDay ? "" : "T235959Z"}`);
  if (rule.end === "count") parts.push(`COUNT=${Math.max(1, rule.count)}`);
  return `RRULE:${parts.join(";")}`;
}

export function presetRules(preset: RepeatPreset, startDate: string): string[] {
  switch (preset) {
    case "daily":
      return ["RRULE:FREQ=DAILY"];
    case "weekly":
      return [`RRULE:FREQ=WEEKLY;BYDAY=${weekdayOf(startDate)}`];
    case "workdays":
      return ["RRULE:FREQ=WEEKLY;BYDAY=SA,SU,MO,TU,WE,TH"];
    case "monthly":
      return ["RRULE:FREQ=MONTHLY"];
    case "monthlyNth":
      return [`RRULE:FREQ=MONTHLY;BYDAY=${nthOf(startDate)}${weekdayOf(startDate)}`];
    case "yearly":
      return ["RRULE:FREQ=YEARLY"];
    default:
      return [];
  }
}

export function detectPreset(lines: string[], startDate: string): RepeatPreset {
  const rules = lines.filter((line) => line.toUpperCase().startsWith("RRULE:"));
  if (rules.length === 0) return "none";
  if (lines.length !== 1) return "custom";
  const presets: RepeatPreset[] = ["daily", "weekly", "workdays", "monthly", "monthlyNth", "yearly"];
  return presets.find((preset) => presetRules(preset, startDate)[0] === rules[0].toUpperCase()) ?? "custom";
}

export function presetLabel(preset: RepeatPreset, startDate: string, locale: Locale) {
  const day = WEEKDAY_NAMES[weekdayOf(startDate)][locale];
  const date = Number(startDate.slice(8, 10));
  const nth = nthOf(startDate);
  const ordinal = locale === "ar" ? ["", "الأول", "الثاني", "الثالث", "الرابع", "الخامس"][nth] : ["", "first", "second", "third", "fourth", "fifth"][nth];
  const labels: Record<RepeatPreset, Copy> = {
    none: { ar: "لا يتكرر", en: "Does not repeat" },
    daily: { ar: "يومياً", en: "Daily" },
    weekly: { ar: `أسبوعياً يوم ${day}`, en: `Weekly on ${day}` },
    workdays: { ar: "كل أيام العمل (السبت – الخميس)", en: "Every workday (Sat – Thu)" },
    monthly: { ar: `شهرياً في اليوم ${date}`, en: `Monthly on day ${date}` },
    monthlyNth: { ar: `شهرياً في ${day} ${ordinal}`, en: `Monthly on the ${ordinal} ${day}` },
    yearly: { ar: "سنوياً", en: "Annually" },
    custom: { ar: "مخصص…", en: "Custom…" },
  };
  return labels[preset][locale];
}

export function describeRule(lines: string[] | null | undefined, startDate: string, locale: Locale) {
  const rule = parseRule(lines, startDate);
  if (!rule) return "";
  const preset = detectPreset(lines ?? [], startDate);
  if (preset !== "custom" && preset !== "none") return presetLabel(preset, startDate, locale);
  const unit: Record<Rule["freq"], Copy> = {
    DAILY: { ar: "يوم", en: "day" },
    WEEKLY: { ar: "أسبوع", en: "week" },
    MONTHLY: { ar: "شهر", en: "month" },
    YEARLY: { ar: "سنة", en: "year" },
  };
  let text = locale === "ar" ? `كل ${rule.interval > 1 ? `${rule.interval} ` : ""}${unit[rule.freq].ar}` : `Every ${rule.interval > 1 ? `${rule.interval} ${unit[rule.freq].en}s` : unit[rule.freq].en}`;
  if (rule.freq === "WEEKLY" && rule.byday.length) text += ` · ${rule.byday.map((code) => WEEKDAY_NAMES[code][locale]).join(locale === "ar" ? "، " : ", ")}`;
  if (rule.end === "until") text += locale === "ar" ? ` · حتى ${rule.until}` : ` · until ${rule.until}`;
  if (rule.end === "count") text += locale === "ar" ? ` · ${rule.count} مرات` : ` · ${rule.count} times`;
  return text;
}
