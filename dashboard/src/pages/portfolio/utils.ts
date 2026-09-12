import type { PortfolioCategory } from "../../api";
import type { Locale } from "../../i18n";

export function categoryLabel(category: PortfolioCategory | undefined, locale: Locale) {
  if (!category) return "—";
  return locale === "ar" ? category.name_ar : category.name_en;
}
