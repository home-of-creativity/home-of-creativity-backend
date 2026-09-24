import { customFonts } from "@docx-editor.dev/core/editor";
import { REPORT_FONT } from "./docxTemplate";

const base = import.meta.env.BASE_URL;

/** IBM Plex Sans Arabic (SIL OFL), served from public/fonts so nothing is fetched from outside. */
export const REPORT_FONT_FILES = {
  regular: `${base}fonts/IBMPlexSansArabic-Regular.ttf`,
  bold: `${base}fonts/IBMPlexSansArabic-Bold.ttf`,
};

/**
 * Families mapped onto the Arabic font. Word files written in Arabic usually name one of these,
 * and the editor needs real Arabic glyph metrics for them to measure lines and pages correctly.
 */
const FAMILIES = [
  REPORT_FONT,
  "Arial",
  "Tahoma",
  "Times New Roman",
  "Calibri",
  "Segoe UI",
  "Simplified Arabic",
  "Traditional Arabic",
  "Sakkal Majalla",
  "Arabic Typesetting",
  "Dubai",
];

export const reportFonts = customFonts({
  sources: FAMILIES.flatMap((family) => [
    { url: REPORT_FONT_FILES.regular, family, weight: 400, style: "normal" as const },
    { url: REPORT_FONT_FILES.bold, family, weight: 700, style: "normal" as const },
  ]),
  onFailure: () => undefined,
});
