import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";

// Text boxes, shapes and the cover page. The editor draws shapes but has no command that creates
// them, and it cannot put a caret inside a floating text box, so these are written into the .docx
// XML and the document is reloaded:
// - a text box is a one-cell table, so staff type in it like any other text;
// - a shape is a drawing in a 1pt paragraph of its own (a right-to-left paragraph that holds a
//   drawing next to its words is drawn with the words reversed); it cannot be selected in the
//   editor afterwards, so the panel places it and removes the last one;
// - the cover is a new first page: the picture behind it, ordinary lines of text, no header or footer.

const EMU_PER_POINT = 12700;
const NS = [
  'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"',
  'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"',
  'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"',
  'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"',
  'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"',
].join(" ");
const WPS_URI = "http://schemas.microsoft.com/office/word/2010/wordprocessingShape";
const PIC_URI = "http://schemas.openxmlformats.org/drawingml/2006/picture";
const IMAGE_REL = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/image";
const SHAPE_NAME = "HOC Shape";
/** A line is drawn as a thin filled bar: the editor paints a zero-height stroke invisible. */
const LINE_THICKNESS_PT = 2.5;

export type ShapeKind = "rect" | "roundRect" | "ellipse" | "line";

export type ShapeOptions = {
  kind: ShapeKind;
  colorHex: string;
  widthPt: number;
  /** Ignored for a line. */
  heightPt: number;
  /** `true`: behind the text, which runs over it. `false`: on its own band, text above and below. */
  behind: boolean;
};

export type TextBoxOptions = {
  widthPt: number;
  border: boolean;
  text: string;
};

/** Crop per edge, in percent. */
export type CropPercent = { left: number; top: number; right: number; bottom: number };

export type CoverInput = {
  image: { bytes: Uint8Array; mime: "image/png" | "image/jpeg" | "image/gif"; crop: CropPercent };
  pageWidthPt: number;
  pageHeightPt: number;
  /** Title, then smaller lines (client, date). Empty lines are skipped. */
  lines: string[];
};

const emu = (points: number) => Math.round(points * EMU_PER_POINT);
const twips = (points: number) => Math.round(points * 20);

function escapeXml(text: string) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function unpack(docx: Uint8Array) {
  const files = unzipSync(docx);
  const part = files["word/document.xml"];
  return part ? { files, xml: strFromU8(part) } : null;
}

function pack(files: Record<string, Uint8Array>, xml: string) {
  files["word/document.xml"] = strToU8(xml);
  return zipSync(files);
}

/** Drawing ids (`wp:docPr id`) must be unique in the document. */
function nextDrawingId(xml: string) {
  return Math.max(0, ...Array.from(xml.matchAll(/<wp:docPr\b[^>]*\bid="(\d+)"/g), (m) => Number(m[1]))) + 1;
}

/** End of the paragraph that opens at `start`, counting paragraphs nested inside it. */
function paragraphEnd(xml: string, start: number) {
  const tags = /<w:p(?=[\s>/])[^>]*?(\/?)>|<\/w:p>/g;
  tags.lastIndex = start;
  let depth = 0;
  for (let match = tags.exec(xml); match; match = tags.exec(xml)) {
    if (match[0] === "</w:p>") depth -= 1;
    else if (match[1] !== "/") depth += 1;
    if (depth === 0) return match.index + match[0].length;
  }
  return -1;
}

/** Insert `block` after the body paragraph with this `w14:paraId`; null when it is not found. */
function insertAfterParagraph(xml: string, paraId: string, block: string) {
  const start = xml.search(new RegExp(`<w:p\\b[^>]*w14:paraId="${paraId}"`));
  if (start < 0) return null;
  const end = paragraphEnd(xml, start);
  if (end < 0) return null;
  // A table cannot be the last thing in a cell or before the section properties.
  const after = xml.slice(end);
  const tail = block.endsWith("</w:tbl>") && /^(?:<w:sectPr\b|<\/w:tc>|<\/w:body>)/.test(after)
    ? '<w:p><w:pPr><w:bidi/></w:pPr></w:p>'
    : "";
  return xml.slice(0, end) + block + tail + after;
}

/** A 1pt paragraph that only carries drawings (and optionally a page break). */
function holder(content: string) {
  return '<w:p><w:pPr><w:bidi/><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/><w:rPr><w:sz w:val="2"/></w:rPr></w:pPr>'
    + `${content}</w:p>`;
}

/** A one-cell table after the caret's paragraph: staff type inside it like any other text. */
export function addTextBox(docx: Uint8Array, paraId: string, options: TextBoxOptions): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const width = twips(options.widthPt);
  const line = options.border ? '<w:top w:val="single" w:sz="6" w:space="0" w:color="9A93A6"/>'
    + '<w:left w:val="single" w:sz="6" w:space="0" w:color="9A93A6"/><w:bottom w:val="single" w:sz="6" w:space="0" w:color="9A93A6"/>'
    + '<w:right w:val="single" w:sz="6" w:space="0" w:color="9A93A6"/>'
    : '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>';
  const table = `<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="${width}" w:type="dxa"/><w:jc w:val="center"/>`
    + `<w:tblBorders>${line}<w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders><w:tblLayout w:type="fixed"/>`
    + '<w:tblCellMar><w:top w:w="113" w:type="dxa"/><w:left w:w="142" w:type="dxa"/><w:bottom w:w="113" w:type="dxa"/><w:right w:w="142" w:type="dxa"/></w:tblCellMar>'
    + `</w:tblPr><w:tblGrid><w:gridCol w:w="${width}"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="${width}" w:type="dxa"/></w:tcPr>`
    + `<w:p><w:pPr><w:bidi/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">${escapeXml(options.text)}</w:t></w:r></w:p>`
    + "</w:tc></w:tr></w:tbl>";
  const xml = insertAfterParagraph(doc.xml, paraId, table);
  return xml ? pack(doc.files, xml) : null;
}

/** Rounded rectangle as free-form geometry: the editor only paints `rect`, `ellipse` and `line` presets. */
function roundRectGeometry(cx: number, cy: number) {
  const radius = Math.round(Math.min(cx, cy) * 0.18);
  const k = Math.round(radius * 0.4477); // radius × (1 − 0.5523): the control-point inset of a quarter circle
  const pt = (x: number, y: number) => `<a:pt x="${x}" y="${y}"/>`;
  const curve = (a: [number, number], b: [number, number], c: [number, number]) =>
    `<a:cubicBezTo>${pt(...a)}${pt(...b)}${pt(...c)}</a:cubicBezTo>`;
  const line = (x: number, y: number) => `<a:lnTo>${pt(x, y)}</a:lnTo>`;
  const path = `<a:moveTo>${pt(radius, 0)}</a:moveTo>${line(cx - radius, 0)}`
    + curve([cx - k, 0], [cx, k], [cx, radius]) + line(cx, cy - radius)
    + curve([cx, cy - k], [cx - k, cy], [cx - radius, cy]) + line(radius, cy)
    + curve([k, cy], [0, cy - k], [0, cy - radius]) + line(0, radius)
    + curve([0, k], [k, 0], [radius, 0]) + "<a:close/>";
  return '<a:custGeom><a:avLst/><a:gdLst/><a:ahLst/><a:cxnLst/><a:rect l="0" t="0" r="r" b="b"/>'
    + `<a:pathLst><a:path w="${cx}" h="${cy}">${path}</a:path></a:pathLst></a:custGeom>`;
}

/**
 * A filled shape (or a line) after the caret's paragraph. On its own band it is an inline drawing
 * in a centred paragraph (a floating one there is drawn above a heading kept with it); behind the
 * text it floats, centred on the column, and the text runs over it.
 */
export function addShape(docx: Uint8Array, paraId: string, options: ShapeOptions): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const id = nextDrawingId(doc.xml);
  const cx = emu(options.widthPt);
  const cy = emu(options.kind === "line" ? LINE_THICKNESS_PT : options.heightPt);
  const geometry = options.kind === "roundRect"
    ? roundRectGeometry(cx, cy)
    : `<a:prstGeom prst="${options.kind === "ellipse" ? "ellipse" : "rect"}"><a:avLst/></a:prstGeom>`;
  const color = options.colorHex.replace(/^#/, "").toUpperCase();
  const graphic = `<wp:docPr id="${id}" name="${SHAPE_NAME} ${id}"/><wp:cNvGraphicFramePr/>`
    + `<a:graphic><a:graphicData uri="${WPS_URI}"><wps:wsp><wps:cNvSpPr/><wps:spPr>`
    + `<a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm>${geometry}`
    + `<a:solidFill><a:srgbClr val="${color}"/></a:solidFill><a:ln><a:noFill/></a:ln></wps:spPr><wps:bodyPr/></wps:wsp>`
    + "</a:graphicData></a:graphic>";
  const extent = `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/>`;
  const block = options.behind
    ? holder(`<w:r><w:drawing ${NS}><wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="251658240"`
      + ' behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
      + '<wp:positionH relativeFrom="column"><wp:align>center</wp:align></wp:positionH>'
      + '<wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>'
      + `${extent}<wp:wrapNone/>${graphic}</wp:anchor></w:drawing></w:r>`)
    : '<w:p><w:pPr><w:bidi/><w:spacing w:before="120" w:after="120"/><w:jc w:val="center"/></w:pPr>'
      + `<w:r><w:drawing ${NS}><wp:inline distT="0" distB="0" distL="0" distR="0">${extent}${graphic}</wp:inline></w:drawing></w:r></w:p>`;
  const xml = insertAfterParagraph(doc.xml, paraId, block);
  return xml ? pack(doc.files, xml) : null;
}

/** Remove the most recently added shape (with its paragraph). Null when there is none. */
export function removeLastShape(docx: Uint8Array): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const shapes = Array.from(doc.xml.matchAll(new RegExp(`<wp:docPr\\b[^>]*\\bname="${SHAPE_NAME} (\\d+)"`, "g")));
  if (shapes.length === 0) return null;
  const last = shapes.reduce((best, match) => (Number(match[1]) > Number(best[1]) ? match : best));
  const start = Math.max(doc.xml.lastIndexOf("<w:p>", last.index), doc.xml.lastIndexOf("<w:p ", last.index));
  const end = start < 0 ? -1 : paragraphEnd(doc.xml, start);
  if (end < 0) return null;
  return pack(doc.files, doc.xml.slice(0, start) + doc.xml.slice(end));
}

function mediaExtension(mime: CoverInput["image"]["mime"]) {
  return mime === "image/jpeg" ? "jpeg" : mime === "image/gif" ? "gif" : "png";
}

/** Put `<w:titlePg/>` on the first section so page 1 has its own (empty) header and footer. */
function withTitlePage(xml: string) {
  const start = xml.indexOf("<w:sectPr");
  if (start < 0) return xml;
  const end = xml.indexOf("</w:sectPr>", start);
  if (end < 0) return xml;
  const section = xml.slice(start, end);
  if (/<w:titlePg(?:\/>| w:val="(?:1|true|on)")/.test(section)) return xml;
  const cleaned = section.replace(/<w:titlePg\b[^>]*\/>/, "");
  const later = cleaned.search(/<w:(?:textDirection|bidi|rtlGutter|docGrid|printerSettings)\b/);
  const updated = later < 0 ? `${cleaned}<w:titlePg/>` : `${cleaned.slice(0, later)}<w:titlePg/>${cleaned.slice(later)}`;
  return xml.slice(0, start) + updated + xml.slice(end);
}

function coverLine(text: string, sizePt: number, bold: boolean, beforePt: number) {
  return `<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:before="${twips(beforePt)}" w:after="${twips(8)}"/></w:pPr>`
    + `<w:r><w:rPr>${bold ? "<w:b/><w:bCs/>" : ""}<w:sz w:val="${sizePt * 2}"/><w:szCs w:val="${sizePt * 2}"/><w:rtl/></w:rPr>`
    + `<w:t xml:space="preserve">${escapeXml(text)}</w:t></w:r></w:p>`;
}

/**
 * A new first page: the picture covers it behind the text, then the lines as ordinary text
 * (title first) around the middle of the page, and no header or footer. The rest of the
 * document starts on the next page.
 */
export function addCover(docx: Uint8Array, input: CoverInput): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const { files } = doc;
  const relsPath = "word/_rels/document.xml.rels";
  const rels = files[relsPath] ? strFromU8(files[relsPath]) : null;
  const types = files["[Content_Types].xml"] ? strFromU8(files["[Content_Types].xml"]) : null;
  if (!rels || !types || !doc.xml.includes("<w:body>")) return null;

  const extension = mediaExtension(input.image.mime);
  let n = 1;
  while (files[`word/media/hoc-cover-${n}.${extension}`] || rels.includes(`rIdHocCover${n}"`)) n += 1;
  const relationshipId = `rIdHocCover${n}`;
  // A copy on a plain ArrayBuffer, the type fflate's file map expects.
  files[`word/media/hoc-cover-${n}.${extension}`] = new Uint8Array(input.image.bytes);
  files[relsPath] = strToU8(rels.replace("</Relationships>",
    `<Relationship Id="${relationshipId}" Type="${IMAGE_REL}" Target="media/hoc-cover-${n}.${extension}"/></Relationships>`));
  if (!new RegExp(`<Default Extension="${extension}"`, "i").test(types)) {
    files["[Content_Types].xml"] = strToU8(types.replace("<Default ", `<Default Extension="${extension}" ContentType="${input.image.mime}"/><Default `));
  }

  const id = nextDrawingId(doc.xml);
  const cx = emu(input.pageWidthPt);
  const cy = emu(input.pageHeightPt);
  const crop = (percent: number) => Math.round(Math.max(0, percent) * 1000);
  const { left, top, right, bottom } = input.image.crop;
  const background = `<w:drawing ${NS}><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240"`
    + ' behindDoc="1" locked="0" layoutInCell="0" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
    + '<wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    + '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>'
    + `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>`
    + `<wp:docPr id="${id}" name="Cover picture"/><wp:cNvGraphicFramePr/>`
    + `<a:graphic><a:graphicData uri="${PIC_URI}"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="cover"/><pic:cNvPicPr/></pic:nvPicPr>`
    + `<pic:blipFill><a:blip r:embed="${relationshipId}"/><a:srcRect l="${crop(left)}" t="${crop(top)}" r="${crop(right)}" b="${crop(bottom)}"/>`
    + `<a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm>`
    + '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing>';

  const lines = input.lines.map((line) => line.trim()).filter(Boolean);
  const text = lines.map((line, index) => (index === 0
    ? coverLine(line, 30, true, input.pageHeightPt * 0.28)
    : coverLine(line, 16, false, index === 1 ? 18 : 4))).join("");
  const cover = holder(`<w:r>${background}</w:r>`) + text + holder('<w:r><w:br w:type="page"/></w:r>');
  const xml = withTitlePage(doc.xml.replace("<w:body>", `<w:body>${cover}`));
  return pack(files, xml);
}
