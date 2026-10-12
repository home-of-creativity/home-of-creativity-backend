import { CalendarDays, FileClock, List } from "lucide-react";
import { useSearchParams } from "react-router-dom";
import type { Locale } from "../../i18n";

export type ReportTab = "list" | "calendar" | "history";

const TABS: ReportTab[] = ["list", "calendar", "history"];

/** The tab lives in ?tab= so a refresh or a shared link opens the same view. */
export function useReportTab(): [ReportTab, (tab: ReportTab) => void] {
  const [params, setParams] = useSearchParams();
  const value = params.get("tab");
  const tab = TABS.includes(value as ReportTab) ? (value as ReportTab) : "list";
  function setTab(next: ReportTab) {
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
  const items: Array<{ id: ReportTab; label: string; icon: typeof List }> = [
    { id: "list", label: first[locale], icon: List },
    { id: "calendar", label: ar ? "التقويم" : "Calendar", icon: CalendarDays },
    { id: "history", label: ar ? "سجل التعديلات" : "Edit history", icon: FileClock },
  ];
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
