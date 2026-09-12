import { copy, socialStatuses, type Copy } from "../../i18n";

export const socialPlatforms = ["facebook", "instagram", "linkedin", "x", "tiktok", "youtube"] as const;

export function platformLabel(platform: string, t: (c: Copy) => string) {
  if (platform === "instagram") return t(copy.instagram);
  if (platform === "facebook") return t(copy.facebook);
  if (platform === "linkedin") return t(copy.linkedin);
  if (platform === "x") return t(copy.xTwitter);
  if (platform === "tiktok") return t(copy.tiktok);
  if (platform === "youtube") return t(copy.youtube);
  return platform;
}

export function socialStatusLabel(status: string, t: (c: Copy) => string) {
  return socialStatuses[status] ? t(socialStatuses[status]) : status;
}

export const socialTimezone = "Asia/Damascus";

export function toLocalInput(iso: string | null | undefined) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: socialTimezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? "00";
  return `${pick("year")}-${pick("month")}-${pick("day")}T${pick("hour")}:${pick("minute")}`;
}

export function fromLocalInput(value: string) {
  return value;
}

export function formatWhen(iso: string | null | undefined, locale: string) {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat(locale === "ar" ? "ar" : "en", {
    timeZone: socialTimezone,
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}
