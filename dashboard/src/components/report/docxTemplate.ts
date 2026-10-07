import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";
import { cachedReportFontFamilies } from "./fontStore";

/** Font every new report starts with. Served from public/fonts and loaded into the editor. */
export const REPORT_FONT = "IBM Plex Sans Arabic";

export type ReportTemplateId = "blank" | "social" | "campaign" | "minutes";

export type ReportTemplateInput = {
  title: string;
  header: string;
  footer: string;
  client?: string;
  date?: string;
};

type Block = string;

const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

function esc(text: string) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function run(text: string, props = "") {
  return `<w:r><w:rPr><w:rtl/>${props}</w:rPr><w:t xml:space="preserve">${esc(text)}</w:t></w:r>`;
}

function para(text: string, style?: string, extra = ""): Block {
  const pStyle = style ? `<w:pStyle w:val="${style}"/>` : "";
  return `<w:p><w:pPr>${pStyle}<w:bidi/>${extra}</w:pPr>${text ? run(text) : ""}</w:p>`;
}

function bullets(items: string[]): Block {
  return items.map((item) => `<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr><w:bidi/></w:pPr>${run(item)}</w:p>`).join("");
}

function table(rows: string[][], headerFill = "2E0E5C"): Block {
  const columns = rows[0]?.length ?? 1;
  const width = Math.floor(9638 / columns);
  const grid = Array.from({ length: columns }, () => `<w:gridCol w:w="${width}"/>`).join("");
  const body = rows
    .map((cells, index) => {
      const header = index === 0;
      const tr = cells
        .map((cell) => {
          const shade = header ? `<w:shd w:val="clear" w:color="auto" w:fill="${headerFill}"/>` : "";
          const props = header ? '<w:b/><w:bCs/><w:color w:val="FFFFFF"/>' : "";
          return `<w:tc><w:tcPr><w:tcW w:w="${width}" w:type="dxa"/>${shade}</w:tcPr><w:p><w:pPr><w:bidi/><w:spacing w:after="0"/></w:pPr>${run(cell, props)}</w:p></w:tc>`;
        })
        .join("");
      return `<w:tr>${header ? '<w:trPr><w:tblHeader/></w:trPr>' : ""}${tr}</w:tr>`;
    })
    .join("");
  return `<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:bidiVisual/><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid>${grid}</w:tblGrid>${body}</w:tbl>${para("")}`;
}

function field(code: string, placeholder: string) {
  return `<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ${code} </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>${placeholder}</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>`;
}

/** Page 1 is a cover. The report body starts after a page break. */
function coverBlocks(input: ReportTemplateInput): Block[] {
  const line = [input.client, input.date].filter(Boolean).join(" · ");
  return [
    para("", undefined, '<w:jc w:val="center"/><w:spacing w:before="3200" w:after="0"/>'),
    para(input.title || "تقرير", "Title", '<w:jc w:val="center"/>'),
    line ? para(line, "Subtitle", '<w:jc w:val="center"/>') : "",
    para("دار الإبداع", "Heading1", '<w:jc w:val="center"/><w:spacing w:before="1400" w:after="40"/>'),
    para("Home of Creativity", "Subtitle", '<w:jc w:val="center"/>'),
    '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
  ];
}

const MISSING = "غير متوفر";
const PURPLE = "2B1A5E";
const ORANGE = "F7A833";
const BLUE = "1A3CFF";

function paint(text: string, color: string, halfPoints: number, bold = false) {
  const weight = bold ? "<w:b/><w:bCs/>" : "";
  return run(text, `${weight}<w:color w:val="${color}"/><w:sz w:val="${halfPoints}"/><w:szCs w:val="${halfPoints}"/>`);
}

function socialLine(text: string, color: string, halfPoints: number, bold = false, extra = ""): Block {
  return `<w:p><w:pPr><w:bidi/>${extra}</w:pPr>${paint(text, color, halfPoints, bold)}</w:p>`;
}

function labeled(label: string, value: string): Block {
  return `<w:p><w:pPr><w:bidi/><w:spacing w:after="80"/></w:pPr>${paint(`${label}: `, PURPLE, 24, true)}${run(value)}</w:p>`;
}

function pageBreak(): Block {
  return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

/** Seven A4 pages. Missing facts stay «غير متوفر»; nothing is invented. */
function socialPages(input: ReportTemplateInput): Block[] {
  const client = input.client?.trim() || MISSING;
  const blankRow = (cells: number) => Array.from({ length: cells }, () => MISSING);
  return [
    socialLine(input.title?.trim() || "تقرير التواصل الاجتماعي", PURPLE, 64, true, '<w:spacing w:after="200"/>'),
    labeled("اسم العميل", client),
    labeled("الهدف", MISSING),
    socialLine("الاتجاه الإبداعي", PURPLE, 28, true, '<w:spacing w:before="200" w:after="40"/>'),
    socialLine(`«${MISSING}»`, ORANGE, 32, true, '<w:jc w:val="center"/><w:spacing w:before="80" w:after="160"/>'),
    labeled("الفكرة الأساسية", MISSING),
    labeled("الهوية البصرية", MISSING),
    labeled("دور الذكاء الاصطناعي", MISSING),
    pageBreak(),
    socialLine("01 — الاتجاه الاستراتيجي", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    socialLine("الفرصة الحالية", ORANGE, 28, true, '<w:spacing w:before="80" w:after="40"/>'),
    para(MISSING),
    socialLine("الجمهور", PURPLE, 28, true, '<w:spacing w:before="200" w:after="80"/>'),
    table([["الفئة", "الاحتياج", "زاوية المحتوى"], blankRow(3)], PURPLE),
    socialLine("ركائز المحتوى", BLUE, 28, true, '<w:spacing w:before="80" w:after="80"/>'),
    table([["الركيزة", "الهدف"], blankRow(2)], PURPLE),
    socialLine("تحليل المحتوى للفترة", PURPLE, 28, true, '<w:spacing w:before="80" w:after="40"/>'),
    labeled("الملاحظات", MISSING),
    labeled("مراجعة الشهر الأخير", MISSING),
    labeled("الفرصة الاستراتيجية", MISSING),
    pageBreak(),
    socialLine("02 — خطة المحتوى الأسبوعية", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    labeled("المنتج / الخدمة المحورية", MISSING),
    labeled("المنصات", MISSING),
    labeled("الفترة", MISSING),
    socialLine("المنشورات", PURPLE, 28, true, '<w:spacing w:before="160" w:after="80"/>'),
    table([["التكرار", "المحتوى", "الدور"], blankRow(3)], PURPLE),
    labeled("ثيم الأسبوع", MISSING),
    socialLine("الستوريز اليومية", ORANGE, 28, true, '<w:spacing w:before="160" w:after="80"/>'),
    table([["الصيغة", "الاستخدام"], blankRow(2)], PURPLE),
    socialLine("معادلة الكابشن", BLUE, 28, true, '<w:spacing w:before="80" w:after="40"/>'),
    socialLine("Hook → Value → Product → CTA", BLUE, 26, true, '<w:jc w:val="center"/><w:spacing w:before="40" w:after="80"/>'),
    labeled("Hook", MISSING),
    labeled("Value", MISSING),
    labeled("Product", MISSING),
    labeled("CTA", MISSING),
    pageBreak(),
    socialLine("03 — بريف الريل", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    labeled("الفكرة", MISSING),
    labeled("الـ Hook", MISSING),
    socialLine("المشاهد", PURPLE, 28, true, '<w:spacing w:before="160" w:after="80"/>'),
    table([["المشهد", "التوجيه"], blankRow(2)], PURPLE),
    labeled("المبدأ التسويقي", MISSING),
    pageBreak(),
    socialLine("04 — بريف الكاروسيل", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    labeled("الفكرة", MISSING),
    socialLine("الشرائح", ORANGE, 28, true, '<w:spacing w:before="160" w:after="80"/>'),
    table([["رقم الشريحة", "الرسالة"], blankRow(2)], PURPLE),
    labeled("الهدف", MISSING),
    pageBreak(),
    socialLine("05 — المحتوى الإبداعي بالذكاء الاصطناعي", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    table([["الاستخدام", "القيمة"], blankRow(2)], PURPLE),
    labeled("القاعدة", MISSING),
    pageBreak(),
    socialLine("06 — الأداء والتحسين", PURPLE, 56, true, '<w:spacing w:after="160"/>'),
    socialLine("الأرقام", PURPLE, 28, true, '<w:spacing w:before="40" w:after="80"/>'),
    table([
      ["المؤشر", "القيمة"],
      ["Reach", MISSING],
      ["Engagement", MISSING],
      ["Followers", MISSING],
      ["Saves", MISSING],
      ["Shares", MISSING],
      ["DMs", MISSING],
    ], PURPLE),
    socialLine("سير العمل الأسبوعي", ORANGE, 28, true, '<w:spacing w:before="80" w:after="80"/>'),
    table([
      ["الخطوة", "التوجيه"],
      ["Plan", MISSING],
      ["Publish", MISSING],
      ["Measure", MISSING],
      ["Optimize", MISSING],
      ["Convert", MISSING],
    ], PURPLE),
    socialLine("قمع النجاح", BLUE, 28, true, '<w:spacing w:before="80" w:after="40"/>'),
    socialLine("Attention → Interest → Consideration → Inquiry → Conversion", BLUE, 24, true, '<w:jc w:val="center"/><w:spacing w:before="40" w:after="120"/>'),
    labeled("التوجيه النهائي", MISSING),
  ];
}

function templateBody(id: ReportTemplateId): Block[] {
  if (id === "social") return [];
  if (id === "campaign") {
    return [
      para("هدف الحملة", "Heading1"),
      para("صف هدف الحملة والجمهور المستهدف."),
      para("الميزانية والنتائج", "Heading1"),
      table([
        ["البند", "المخطط", "الفعلي"],
        ["الميزانية", "", ""],
        ["مرات الظهور", "", ""],
        ["النقرات", "", ""],
        ["التحويلات", "", ""],
        ["تكلفة التحويل", "", ""],
      ]),
      para("ما نجح", "Heading1"),
      bullets(["نقطة", "نقطة"]),
      para("ما يحتاج تحسيناً", "Heading1"),
      bullets(["نقطة", "نقطة"]),
      para("الخطوات التالية", "Heading1"),
      bullets(["خطوة", "خطوة"]),
    ];
  }
  if (id === "minutes") {
    return [
      para("الحضور", "Heading1"),
      bullets(["الاسم — الجهة", "الاسم — الجهة"]),
      para("جدول الأعمال", "Heading1"),
      bullets(["بند أول", "بند ثانٍ"]),
      para("القرارات والمهام", "Heading1"),
      table([
        ["المهمة", "المسؤول", "الموعد"],
        ["", "", ""],
        ["", "", ""],
      ]),
    ];
  }
  return [para("")];
}

const STYLES = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles ${W}>
<w:docDefaults>
<w:rPrDefault><w:rPr><w:rFonts w:ascii="${REPORT_FONT}" w:hAnsi="${REPORT_FONT}" w:cs="${REPORT_FONT}" w:eastAsia="${REPORT_FONT}"/><w:color w:val="1A0838"/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="en-US" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>
<w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="120" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault>
</w:docDefaults>
<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>
<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="80"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="2E0E5C"/><w:sz w:val="52"/><w:szCs w:val="52"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="360"/></w:pPr><w:rPr><w:color w:val="6B6178"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="2E0E5C"/><w:sz w:val="34"/><w:szCs w:val="34"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="2E0E5C"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="60"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="E7993A"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:ind w:left="567" w:right="567"/></w:pPr><w:rPr><w:i/><w:iCs/><w:color w:val="3D2F55"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="60"/><w:ind w:left="720"/></w:pPr></w:style>
<w:style w:type="paragraph" w:styleId="Header"><w:name w:val="header"/><w:basedOn w:val="Normal"/><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="E7993A"/></w:pBdr><w:spacing w:after="0"/></w:pPr><w:rPr><w:color w:val="6B6178"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Normal"/><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="E7993A"/></w:pBdr><w:spacing w:after="0"/></w:pPr><w:rPr><w:color w:val="6B6178"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>
<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>
<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:basedOn w:val="TableNormal"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/><w:left w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/><w:right w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="D9D0E6"/></w:tblBorders><w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>
</w:styles>`;

/** Stable style id for an uploaded face. The visible name stays the family name. */
export function fontStyleId(family: string) {
  let hash = 2166136261;
  for (const char of family) hash = Math.imul(hash ^ (char.codePointAt(0) ?? 0), 16777619);
  return `HocFont${(hash >>> 0).toString(16)}`;
}

export function fontStyleXml(family: string) {
  const name = esc(family);
  return `<w:style w:type="paragraph" w:customStyle="1" w:styleId="${fontStyleId(family)}"><w:name w:val="${name}"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:rPr><w:rFonts w:ascii="${name}" w:hAnsi="${name}" w:cs="${name}" w:eastAsia="${name}"/></w:rPr></w:style>`;
}

function stylesDocument() {
  const extra = cachedReportFontFamilies().map(fontStyleXml).join("");
  return STYLES.replace("</w:styles>", `${extra}</w:styles>`);
}

/** Add a paragraph style that uses this family. Returns the same bytes when the style is already there. */
export function addParagraphFontStyle(docx: Uint8Array, family: string): Uint8Array {
  const files = unzipSync(docx);
  const stylesFile = files["word/styles.xml"];
  if (!stylesFile) return docx;
  const styles = strFromU8(stylesFile);
  if (styles.includes(`w:styleId="${fontStyleId(family)}"`) || !styles.includes("</w:styles>")) return docx;
  files["word/styles.xml"] = strToU8(styles.replace("</w:styles>", `${fontStyleXml(family)}</w:styles>`));
  return zipSync(files);
}

const NUMBERING = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering ${W}>
<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>
<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>
<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="◦"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr></w:lvl>
</w:abstractNum>
<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
</w:numbering>`;

/**
 * A Word document (.docx) for a new report: Arabic font, right-to-left paragraphs and tables,
 * a cover on page 1 (no header or footer), then A4 body pages with a header and a footer
 * with "page X of Y" fields. Built in the browser, no server.
 */
export function buildReportDocx(id: ReportTemplateId, input: ReportTemplateInput): Uint8Array {
  if (id === "social") return pack(input, socialPages(input), "social");
  return pack(input, templateBody(id));
}

/** A report document whose body is the given plain paragraphs (used for reports saved before DOCX). */
export function buildDocxFromParagraphs(input: ReportTemplateInput, paragraphs: string[]): Uint8Array {
  return pack(input, paragraphs.map((line) => para(line)));
}

/** Turn a legacy HTML report body into plain paragraphs: one per block of text. */
export function legacyHtmlToParagraphs(html: string): string[] {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body;
  const blocks = Array.from(root.querySelectorAll("p, h1, h2, h3, h4, li, td, th, blockquote, pre"));
  const lines = blocks.length > 0 ? blocks.map((block) => block.textContent ?? "") : (root.textContent ?? "").split("\n");
  return lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
}

function pack(input: ReportTemplateInput, blocks: Block[], kind: "default" | "social" = "default"): Uint8Array {
  const social = kind === "social";
  const body = (social ? blocks : [...coverBlocks(input), ...blocks]).filter(Boolean).join("");
  // Social pages leave the letterhead's logo band and contact band empty.
  const margins = social
    ? '<w:pgMar w:top="2268" w:right="1134" w:bottom="1985" w:left="1134" w:header="284" w:footer="1134" w:gutter="0"/>'
    : '<w:pgMar w:top="1440" w:right="1134" w:bottom="1304" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>';
  const document = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document ${W}><w:body>${body}<w:sectPr><w:headerReference w:type="first" r:id="rIdHeaderFirst"/><w:footerReference w:type="first" r:id="rIdFooterFirst"/><w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="11906" w:h="16838"/>${margins}<w:titlePg/><w:bidi/></w:sectPr></w:body></w:document>`;
  const client = input.client?.trim() || "غير متوفر";
  const footerRuns = social
    ? `${run(`${client} | غير متوفر | `)}${field("PAGE", "1")}`
    : `${run(input.footer)}<w:r><w:tab/></w:r>${run("صفحة ")}${field("PAGE", "1")}${run(" من ")}${field("NUMPAGES", "1")}`;
  const header = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr ${W}><w:p><w:pPr><w:pStyle w:val="Header"/><w:bidi/></w:pPr>${social ? "" : run(input.header)}</w:p></w:hdr>`;
  const footer = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr ${W}><w:p><w:pPr><w:pStyle w:val="Footer"/><w:bidi/><w:tabs><w:tab w:val="right" w:pos="9638"/></w:tabs></w:pPr>${footerRuns}</w:p></w:ftr>`;
  const blankHeader = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr ${W}><w:p><w:pPr><w:bidi/></w:pPr></w:p></w:hdr>`;
  const blankFooter = social ? footer : `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr ${W}><w:p><w:pPr><w:bidi/></w:pPr></w:p></w:ftr>`;

  return zipSync({
    "[Content_Types].xml": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/header2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>`),
    "_rels/.rels": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>`),
    "word/_rels/document.xml.rels": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/><Relationship Id="rIdHeader" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/><Relationship Id="rIdHeaderFirst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header2.xml"/><Relationship Id="rIdFooterFirst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/></Relationships>`),
    "word/document.xml": strToU8(document),
    "word/styles.xml": strToU8(stylesDocument()),
    "word/numbering.xml": strToU8(NUMBERING),
    "word/header1.xml": strToU8(header),
    "word/footer1.xml": strToU8(footer),
    "word/header2.xml": strToU8(blankHeader),
    "word/footer2.xml": strToU8(blankFooter),
  });
}
