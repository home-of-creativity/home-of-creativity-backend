import type { ReportDeadlineStatus, ReportRepeat, ReportVersionEvent } from "../../api";
import type { Locale } from "../../i18n";
import { ZONE, intlTag, type Copy } from "../photography/model";

export const EVENT_COPY: Record<ReportVersionEvent, Copy> = {
  created: { ar: "أنشأ التقرير", en: "Created the report" },
  edited: { ar: "عدّل التقرير", en: "Edited the report" },
  published: { ar: "نشر على Drive", en: "Published to Drive" },
  restored: { ar: "استرجع نسخة سابقة", en: "Restored an earlier version" },
  attachment_added: { ar: "أضاف مرفقات", en: "Added attachments" },
  attachment_removed: { ar: "حذف مرفقات", en: "Removed attachments" },
  deleted: { ar: "حذف التقرير", en: "Deleted the report" },
};

export const EVENT_COLORS: Record<ReportVersionEvent, string> = {
  created: "#7986cb",
  edited: "#c9d7f5",
  published: "#8edfd6",
  restored: "#d7c4f5",
  attachment_added: "#f3de86",
  attachment_removed: "#f3de86",
  deleted: "#f2b8b5",
};

export const STATUS_COPY: Record<ReportDeadlineStatus, Copy> = {
  upcoming: { ar: "قادم", en: "Upcoming" },
  overdue: { ar: "متأخر", en: "Overdue" },
  done: { ar: "سُلّم", en: "Delivered" },
  skipped: { ar: "متجاوز", en: "Skipped" },
};

export const STATUS_COLORS: Record<ReportDeadlineStatus, string> = {
  upcoming: "#f0b56a",
  overdue: "#e5484d",
  done: "#33b679",
  skipped: "#d9d4e0",
};

export const REPEAT_COPY: Record<ReportRepeat, Copy> = {
  none: { ar: "لا يتكرر", en: "Does not repeat" },
  weekly: { ar: "أسبوعياً", en: "Weekly" },
  monthly: { ar: "شهرياً", en: "Monthly" },
  quarterly: { ar: "كل 3 أشهر", en: "Every 3 months" },
  yearly: { ar: "سنوياً", en: "Yearly" },
};

export const REPEATS: ReportRepeat[] = ["none", "weekly", "monthly", "quarterly", "yearly"];

export function dayKey(value: string) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date(value));
}

export function dayTitle(key: string, locale: Locale) {
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: "UTC", weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${key}T12:00:00Z`));
}

export function clock(value: string | null, locale: Locale) {
  if (!value) return "";
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date(value));
}

export function stamp(value: string | null, locale: Locale) {
  if (!value) return "—";
  return new Intl.DateTimeFormat(intlTag(locale), { timeZone: ZONE, dateStyle: "medium", timeStyle: "short", hourCycle: "h23" }).format(new Date(value));
}

export function fileSize(bytes: number | null) {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function clientLabel(client: { name: string; company_name: string | null } | null) {
  if (!client) return "";
  return client.company_name || client.name;
}

export function saveBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
