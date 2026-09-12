import type { PricingCategory, PricingSubcategory } from "../../api";
import type { Locale } from "../../i18n";

export function pricingLabel(item: { name_en: string; name_ar: string }, locale: Locale) {
  return locale === "ar" ? item.name_ar : item.name_en;
}

export function subcategoryLabel(item: PricingSubcategory, locale: Locale) {
  return pricingLabel(item, locale);
}

export function categoryLabel(item: PricingCategory, locale: Locale) {
  return pricingLabel(item, locale);
}

export function calcPrices(monthly: number) {
  return {
    monthly,
    quarterly: Math.round(monthly * 3 * 0.95),
    semiannual: Math.round(monthly * 6 * 0.9),
    yearly: Math.round(monthly * 12 * 0.8),
  };
}

export function featuresToText(features: Array<{ en: string; ar: string }> | undefined, field: "en" | "ar") {
  return (features ?? []).map((row) => row[field]).join("\n");
}

export function textToFeatures(enText: string, arText: string) {
  const enLines = enText.split("\n").map((line) => line.trim()).filter(Boolean);
  const arLines = arText.split("\n").map((line) => line.trim()).filter(Boolean);
  const count = Math.max(enLines.length, arLines.length);
  const features: Array<{ en: string; ar: string }> = [];
  for (let i = 0; i < count; i++) {
    features.push({ en: enLines[i] ?? "", ar: arLines[i] ?? "" });
  }
  return features.filter((row) => row.en || row.ar);
}
