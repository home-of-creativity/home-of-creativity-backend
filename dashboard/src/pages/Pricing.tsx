import { useSearchParams } from "react-router-dom";
import { PageHeader } from "../components/PageHeader";
import { Tabs } from "../components/Tabs";
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
      <PageHeader eyebrow={t(copy.brandMark)} title={t(copy.pricingTitle)} lede={t(copy.pricingLede)} />

      <Tabs
        value={tab}
        onValueChange={(next) => setTab(next as Tab)}
        ariaLabel={t(copy.pricingTitle)}
        items={tabs.map((entry) => ({ value: entry, label: tabLabel(entry, t) }))}
      />

      {tab === "categories" ? <PricingCategoriesPanel locale={locale} t={t} /> : null}
      {tab === "subcategories" ? <PricingSubcategoriesPanel locale={locale} t={t} /> : null}
      {tab === "packages" ? <PricingPackagesPanel locale={locale} t={t} /> : null}
    </>
  );
}
