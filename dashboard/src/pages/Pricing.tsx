import { useSearchParams } from "react-router-dom";
import { copy, type Locale } from "../i18n";
import { PricingCategoriesPanel } from "./pricing/PricingCategoriesPanel";
import { PricingPackagesPanel } from "./pricing/PricingPackagesPanel";
import { PricingSubcategoriesPanel } from "./pricing/PricingSubcategoriesPanel";

type Tab = "categories" | "subcategories" | "packages";
const tabs: Tab[] = ["categories", "subcategories", "packages"];

function readTab(value: string | null): Tab {
  return tabs.includes(value as Tab) ? (value as Tab) : "categories";
}

function tabLabel(tab: Tab, t: (c: { ar: string; en: string }) => string) {
  switch (tab) {
    case "subcategories":
      return t(copy.pricingTabSubcategories);
    case "packages":
      return t(copy.pricingTabPackages);
    default:
      return t(copy.pricingTabCategories);
  }
}

export function Pricing({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = readTab(searchParams.get("tab"));

  function setTab(next: Tab) {
    if (next === "categories") {
      setSearchParams({});
      return;
    }
    setSearchParams({ tab: next });
  }

  return (
    <>
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.pricingTitle)}</h1>
          <p className="page-lede">{t(copy.pricingLede)}</p>
        </div>
      </header>

      <div className="tabs" role="tablist" aria-label={t(copy.pricingTitle)}>
        {tabs.map((entry) => (
          <button
            key={entry}
            type="button"
            role="tab"
            aria-selected={tab === entry}
            className={tab === entry ? "tab is-active" : "tab"}
            onClick={() => setTab(entry)}
          >
            {tabLabel(entry, t)}
          </button>
        ))}
      </div>

      {tab === "categories" ? <PricingCategoriesPanel locale={locale} t={t} /> : null}
      {tab === "subcategories" ? <PricingSubcategoriesPanel locale={locale} t={t} /> : null}
      {tab === "packages" ? <PricingPackagesPanel locale={locale} t={t} /> : null}
    </>
  );
}
