import { CalendarDays, FileClock, List } from "lucide-react";
import { useSearchParams } from "react-router-dom";
import type { Locale } from "../../i18n";
import { useCan } from "../../lib/useCan";

export type ReportTab = "list" | "calendar" | "history";

const TABS: ReportTab[] = ["list", "calendar", "history"];

function tabAllowed(tab: ReportTab, can: (ability: string) => boolean): boolean {
  switch (tab) {
    case "calendar":
      return can("ops.reports.calendar_view") || can("ops.reports.calendar_create") || can("ops.reports.calendar_update") || can("ops.reports.calendar_delete");
    case "history":
      return can("ops.reports.history_view") || can("ops.reports.history_restore");
    default:
      return can("ops.reports.view") || can("ops.reports.create") || can("ops.reports.update") || can("ops.reports.delete") || can("ops.reports.send");
  }
}

/** The tab lives in ?tab= so a refresh or a shared link opens the same view. */
export function useReportTab(): [ReportTab, (tab: ReportTab) => void] {
  const can = useCan();
  const [params, setParams] = useSearchParams();
  const value = params.get("tab");
  const requested = TABS.includes(value as ReportTab) ? (value as ReportTab) : null;
  const tab = requested && tabAllowed(requested, can)
    ? requested
    : (TABS.find((item) => tabAllowed(item, can)) ?? "list");

  function setTab(next: ReportTab) {
    if (!tabAllowed(next, can)) return;
    setParams(
      (current) => {
        const copy = new URLSearchParams(current);
        if (next === "list") copy.delete("tab");
        else copy.set("tab", next);
        return copy;
      },
      { replace: true },
    );
  }
  return [tab, setTab];
}

export function ReportTabs({ locale, tab, onTab, first }: { locale: Locale; tab: ReportTab; onTab: (tab: ReportTab) => void; first: { ar: string; en: string } }) {
  const ar = locale === "ar";
  const can = useCan();
  const all: Array<{ id: ReportTab; label: string; icon: typeof List }> = [
    { id: "list", label: first[locale], icon: List },
    { id: "calendar", label: ar ? "التقويم" : "Calendar", icon: CalendarDays },
    { id: "history", label: ar ? "سجل التعديلات" : "Edit history", icon: FileClock },
  ];
  const items = all.filter((item) => tabAllowed(item.id, can));

  return (
    <div className="tabs report-tabs" role="tablist" aria-label={ar ? "عرض التقارير" : "Reports view"}>
      {items.map((item) => (
        <button key={item.id} type="button" role="tab" className="tab" aria-selected={tab === item.id} onClick={() => onTab(item.id)}>
          <item.icon size={15} aria-hidden /> {item.label}
        </button>
      ))}
    </div>
  );
}
