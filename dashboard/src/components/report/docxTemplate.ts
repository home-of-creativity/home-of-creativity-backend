import { strToU8, zipSync } from "fflate";

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

function table(rows: string[][]): Block {
  const columns = rows[0]?.length ?? 1;
  const width = Math.floor(9638 / columns);
  const grid = Array.from({ length: columns }, () => `<w:gridCol w:w="${width}"/>`).join("");
  const body = rows
    .map((cells, index) => {
      const header = index === 0;
      const tr = cells
        .map((cell) => {
          const shade = header ? '<w:shd w:val="clear" w:color="auto" w:fill="2E0E5C"/>' : "";
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

function templateBody(id: ReportTemplateId, input: ReportTemplateInput): Block[] {
  const intro = [
    para(input.title || "تقرير", "Title"),
    input.client || input.date ? para([input.client, input.date].filter(Boolean).join(" · "), "Subtitle") : "",
  ];
  if (id === "social") {
    return [
      ...intro,
      para("الملخص التنفيذي", "Heading1"),
      para("اكتب هنا أهم ما حدث خلال الفترة وأبرز النتائج."),
      para("مؤشرات الأداء", "Heading1"),
      table([
        ["المؤشر", "هذه الفترة", "الفترة السابقة", "التغيّر"],
        ["الوصول", "", "", ""],
        ["التفاعل", "", "", ""],
        ["المتابعون الجدد", "", "", ""],
        ["النقرات", "", "", ""],
      ]),
      para("أداء المنصات", "Heading1"),
      table([
        ["المنصة", "المنشورات", "الوصول", "التفاعل"],
        ["إنستغرام", "", "", ""],
        ["فيسبوك", "", "", ""],
        ["تيك توك", "", "", ""],
        ["لينكدإن", "", "", ""],
      ]),
      para("أفضل المنشورات", "Heading1"),
      bullets(["المنشور الأول ولماذا نجح", "المنشور الثاني ولماذا نجح"]),
      para("التوصيات للفترة القادمة", "Heading1"),
      bullets(["توصية أولى", "توصية ثانية"]),
    ];
  }
  if (id === "campaign") {
    return [
      ...intro,
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
      ...intro,
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
  return [...intro, para("")];
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
 * A4 page, a header, and a footer with "page X of Y" fields. Built in the browser, no server.
 */
export function buildReportDocx(id: ReportTemplateId, input: ReportTemplateInput): Uint8Array {
  return pack(input, templateBody(id, input));
}

/** A report document whose body is the given plain paragraphs (used for reports saved before DOCX). */
export function buildDocxFromParagraphs(input: ReportTemplateInput, paragraphs: string[]): Uint8Array {
  return pack(input, [para(input.title || "تقرير", "Title"), ...paragraphs.map((line) => para(line))]);
}

/** Turn a legacy HTML report body into plain paragraphs: one per block of text. */
export function legacyHtmlToParagraphs(html: string): string[] {
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html").body;
  const blocks = Array.from(root.querySelectorAll("p, h1, h2, h3, h4, li, td, th, blockquote, pre"));
  const lines = blocks.length > 0 ? blocks.map((block) => block.textContent ?? "") : (root.textContent ?? "").split("\n");
  return lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
}

function pack(input: ReportTemplateInput, blocks: Block[]): Uint8Array {
  const body = blocks.filter(Boolean).join("");
  const document = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document ${W}><w:body>${body}<w:sectPr><w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1134" w:bottom="1304" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/><w:bidi/></w:sectPr></w:body></w:document>`;
  const header = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr ${W}><w:p><w:pPr><w:pStyle w:val="Header"/><w:bidi/></w:pPr>${run(input.header)}</w:p></w:hdr>`;
  const footer = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr ${W}><w:p><w:pPr><w:pStyle w:val="Footer"/><w:bidi/><w:tabs><w:tab w:val="right" w:pos="9638"/></w:tabs></w:pPr>${run(input.footer)}<w:r><w:tab/></w:r>${run("صفحة ")}${field("PAGE", "1")}${run(" من ")}${field("NUMPAGES", "1")}</w:p></w:ftr>`;

  return zipSync({
    "[Content_Types].xml": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>`),
    "_rels/.rels": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>`),
    "word/_rels/document.xml.rels": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/><Relationship Id="rIdHeader" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>`),
    "word/document.xml": strToU8(document),
    "word/styles.xml": strToU8(STYLES),
    "word/numbering.xml": strToU8(NUMBERING),
    "word/header1.xml": strToU8(header),
    "word/footer1.xml": strToU8(footer),
  });
}
