import type { LegalPage, LegalSection } from "../api";

export function flattenLegalBody(sections: LegalSection[], locale: "en" | "ar"): string {
  return sections
    .map((section) => {
      const heading = (locale === "ar" ? section.heading_ar : section.heading_en).trim();
      const html = (locale === "ar" ? section.html_ar : section.html_en).trim();
      const head = heading ? `<h2>${heading}</h2>\n` : "";
      return `${head}${html}`.trim();
    })
    .filter(Boolean)
    .join("\n\n");
}

export function expandLegalSections(bodyEn: string, bodyAr: string): LegalSection[] {
  return [
    {
      id: "content",
      heading_ar: "",
      heading_en: "",
      html_ar: bodyAr.trim(),
      html_en: bodyEn.trim(),
    },
  ];
}

export function emptyLegalPage(slug: "privacy" | "terms"): LegalPage {
  return {
    slug,
    title_ar: slug === "terms" ? "شروط الاستخدام" : "سياسة الخصوصية",
    title_en: slug === "terms" ? "Terms of Use" : "Privacy Policy",
    sections: expandLegalSections("", ""),
  };
}
