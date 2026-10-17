import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";

// A text box and the cover page. The text box is a floating Word shape (rectangle, rounded
// rectangle, or ellipse) anchored to the page; `TextBoxChrome` moves, resizes, edits and deletes
// it. It is written into the .docx and the document is reloaded. The cover is a new first
// page: the picture behind it, ordinary lines of text, no header or footer. The closing page is
// a last page the picture covers.

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
export type TextBoxShape = "rect" | "roundRect" | "ellipse";

export type TextBoxOptions = {
  kind: TextBoxShape;
  text: string;
  widthPt: number;
  heightPt: number;
  /** Distance from the page's left edge, in points. */
  xPt: number;
  /** Distance from the page's top edge, in points. */
  yPt: number;
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

const PAGE_BREAK = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

/**
 * A blank page after the caret's paragraph.
 *
 * The break alone does not paint a page when it is the last thing in the story, so an empty
 * paragraph follows it. A second break is added only when more content follows, so that content
 * starts on the page after the blank one.
 */
export function addBlankPage(docx: Uint8Array, paraId: string): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const start = doc.xml.search(new RegExp(`<w:p\\b[^>]*w14:paraId="${paraId}"`));
  if (start < 0) return null;
  const end = paragraphEnd(doc.xml, start);
  if (end < 0) return null;
  const after = doc.xml.slice(end).replace(/<w:sectPr\b[\s\S]*?<\/w:sectPr>/g, "");
  const hasMore = /<w:p\b|<w:tbl\b/.test(after);
  // A page break that is already next does the second split. Adding another one leaves an extra empty page.
  const nextIsBreak = /^\s*<w:p\b[^>]*>\s*(?:<w:pPr\b[\s\S]*?<\/w:pPr>\s*)?<w:r\b[^>]*>\s*<w:br\b[^>]*w:type="page"/.test(after);
  const blank = '<w:p><w:pPr><w:bidi/></w:pPr></w:p>';
  const next = insertAfterParagraph(doc.xml, paraId, PAGE_BREAK + blank + (hasMore && !nextIsBreak ? PAGE_BREAK : ""));
  return next ? pack(doc.files, next) : null;
}

/**
 * Set or clear right-to-left on one paragraph. Other paragraphs stay as they are.
 * `null` when that paragraph is not in the file.
 */
export function setParagraphDirection(docx: Uint8Array, paraId: string, direction: "rtl" | "ltr"): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const start = doc.xml.search(new RegExp(`<w:p\\b[^>]*w14:paraId="${paraId}"`));
  if (start < 0) return null;
  const end = paragraphEnd(doc.xml, start);
  if (end < 0) return null;
  const paragraph = doc.xml.slice(start, end);
  const next = direction === "rtl" ? withBidi(paragraph) : withoutBidi(paragraph);
  if (next === paragraph) return docx;
  return pack(doc.files, doc.xml.slice(0, start) + next + doc.xml.slice(end));
}

function withBidi(paragraph: string) {
  if (/<w:bidi\b/.test(paragraph)) return paragraph.replace(/<w:bidi\b[^>]*\/>/, "<w:bidi/>");
  const properties = paragraph.match(/<w:pPr\b[^>]*>/);
  if (!properties) return paragraph.replace(/^(<w:p\b[^>]*>)/, "$1<w:pPr><w:bidi/></w:pPr>");
  return paragraph.replace(properties[0], `${properties[0]}<w:bidi/>`);
}

function withoutBidi(paragraph: string) {
  return paragraph.replace(/<w:bidi\b[^>]*\/>/, "");
}

function paragraphBounds(xml: string, paraId: string) {
  const start = xml.search(new RegExp(`<w:p\\b[^>]*w14:paraId="${paraId}"`));
  if (start < 0) return null;
  const end = paragraphEnd(xml, start);
  return end < 0 ? null : { start, end };
}

/** Heading 1, 2, or 3 on one paragraph. The style ids match the report template. */
export function setParagraphStyle(docx: Uint8Array, paraId: string, style: "Heading1" | "Heading2" | "Heading3"): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const bounds = paragraphBounds(doc.xml, paraId);
  if (!bounds) return null;
  const tag = `<w:pStyle w:val="${style}"/>`;
  let paragraph = doc.xml.slice(bounds.start, bounds.end);
  if (/<w:pStyle\b/.test(paragraph)) paragraph = paragraph.replace(/<w:pStyle\b[^>]*\/>/, tag);
  else if (/<w:pPr\b[^>]*>/.test(paragraph)) paragraph = paragraph.replace(/(<w:pPr\b[^>]*>)/, `$1${tag}`);
  else paragraph = paragraph.replace(/^(<w:p\b[^>]*>)/, `$1<w:pPr>${tag}</w:pPr>`);
  return pack(doc.files, doc.xml.slice(0, bounds.start) + paragraph + doc.xml.slice(bounds.end));
}

/** A single line under the caret's paragraph. Pressing it again leaves the same line. */
export function setHorizontalRule(docx: Uint8Array, paraId: string): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const bounds = paragraphBounds(doc.xml, paraId);
  if (!bounds) return null;
  const rule = '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="1" w:color="1A1224"/></w:pBdr>';
  let paragraph = doc.xml.slice(bounds.start, bounds.end);
  if (/<w:pBdr\b/.test(paragraph)) {
    if (/<w:bottom\b/.test(paragraph)) return docx;
    paragraph = paragraph.replace(/<w:pBdr\b[^>]*>/, (open) => `${open}<w:bottom w:val="single" w:sz="12" w:space="1" w:color="1A1224"/>`);
  } else if (/<w:pPr\b[^>]*>/.test(paragraph)) {
    paragraph = paragraph.replace(/(<w:pPr\b[^>]*>)/, `$1${rule}`);
  } else {
    paragraph = paragraph.replace(/^(<w:p\b[^>]*>)/, `$1<w:pPr>${rule}</w:pPr>`);
  }
  return pack(doc.files, doc.xml.slice(0, bounds.start) + paragraph + doc.xml.slice(bounds.end));
}

/** A page break after the caret's paragraph. The following text starts on the next page. */
export function insertPageBreak(docx: Uint8Array, paraId: string): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const next = insertAfterParagraph(doc.xml, paraId, PAGE_BREAK);
  return next ? pack(doc.files, next) : null;
}

const COLUMN_MIN_TWIPS = 720;

/**
 * Drag one column wider or narrower. The neighbor takes the difference, so the table
 * stays the same width. `columnIndex` is the cell's place in its row, starting at 0.
 */
export function resizeTableColumn(docx: Uint8Array, paraId: string, columnIndex: number, deltaTwips: number): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc || columnIndex < 0 || deltaTwips === 0) return null;
  const bounds = paragraphBounds(doc.xml, paraId);
  if (!bounds) return null;
  const tableStart = tableOpenBefore(doc.xml, bounds.start);
  if (tableStart < 0) return null;
  const tableEnd = tableClose(doc.xml, tableStart);
  if (tableEnd < 0) return null;
  let table = doc.xml.slice(tableStart, tableEnd);
  const cols = [...table.matchAll(/<w:gridCol\b[^>]*\/>/g)];
  if (columnIndex >= cols.length) return null;
  const widthOf = (tag: string) => Number(tag.match(/\bw:w="(\d+)"/)?.[1] ?? 0);
  const neighbor = columnIndex + 1 < cols.length ? columnIndex + 1 : columnIndex > 0 ? columnIndex - 1 : -1;
  const left = widthOf(cols[columnIndex][0]);
  const right = neighbor >= 0 ? widthOf(cols[neighbor][0]) : 0;
  let nextLeft = left + deltaTwips;
  let nextRight = neighbor >= 0 ? right - deltaTwips : 0;
  if (neighbor < 0) nextLeft = Math.max(COLUMN_MIN_TWIPS, nextLeft);
  else {
    if (nextLeft < COLUMN_MIN_TWIPS) {
      nextRight -= COLUMN_MIN_TWIPS - nextLeft;
      nextLeft = COLUMN_MIN_TWIPS;
    }
    if (nextRight < COLUMN_MIN_TWIPS) {
      nextLeft -= COLUMN_MIN_TWIPS - nextRight;
      nextRight = COLUMN_MIN_TWIPS;
    }
    if (nextLeft < COLUMN_MIN_TWIPS || nextRight < COLUMN_MIN_TWIPS) return null;
  }
  table = replaceGridWidth(table, columnIndex, nextLeft);
  if (neighbor >= 0) table = replaceGridWidth(table, neighbor, nextRight);
  const sum = [...table.matchAll(/<w:gridCol\b[^>]*\bw:w="(\d+)"[^>]*\/>/g)].reduce((total, match) => total + Number(match[1]), 0);
  if (/<w:tblW\b[^>]*w:type="dxa"/.test(table) && sum > 0) {
    table = table.replace(/(<w:tblW\b[^>]*\bw:w=")(\d+)(")/, `$1${sum}$3`);
  }
  if (table === doc.xml.slice(tableStart, tableEnd)) return null;
  return pack(doc.files, doc.xml.slice(0, tableStart) + table + doc.xml.slice(tableEnd));
}

function replaceGridWidth(table: string, index: number, width: number) {
  let seen = 0;
  return table.replace(/<w:gridCol\b[^>]*\/>/g, (tag) => {
    if (seen++ !== index) return tag;
    return tag.includes('w:w="') ? tag.replace(/\bw:w="\d+"/, `w:w="${width}"`) : tag.replace("/>", ` w:w="${width}"/>`);
  });
}

function tableOpenBefore(xml: string, at: number) {
  const marks = /<w:tbl(?=[\s>])/g;
  let last = -1;
  for (let match = marks.exec(xml); match && match.index < at; match = marks.exec(xml)) last = match.index;
  return last;
}

function tableClose(xml: string, start: number) {
  const marks = /<w:tbl(?=[\s>])|<\/w:tbl>/g;
  marks.lastIndex = start;
  let depth = 0;
  for (let match = marks.exec(xml); match; match = marks.exec(xml)) {
    depth += match[0] === "</w:tbl>" ? -1 : 1;
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
 * A floating text box after the caret's paragraph, positioned on the page. The shape is a
 * rectangle, a rounded rectangle, or an ellipse.
 */
export function addTextBox(docx: Uint8Array, paraId: string, options: TextBoxOptions): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc) return null;
  const id = nextDrawingId(doc.xml);
  const cx = emu(options.widthPt);
  const cy = emu(options.heightPt);
  const geometry = options.kind === "roundRect"
    ? roundRectGeometry(cx, cy)
    : `<a:prstGeom prst="${options.kind === "ellipse" ? "ellipse" : "rect"}"><a:avLst/></a:prstGeom>`;
  const graphic = `<wp:docPr id="${id}" name="HOC Text Box ${id}"/><wp:cNvGraphicFramePr/>`
    + `<a:graphic><a:graphicData uri="${WPS_URI}"><wps:wsp><wps:cNvSpPr txBox="1"/><wps:spPr>`
    + `<a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm>${geometry}`
    + '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="19050"><a:solidFill><a:srgbClr val="2B1A5E"/></a:solidFill></a:ln>'
    + "</wps:spPr><wps:txbx><w:txbxContent>"
    + `<w:p><w:pPr><w:bidi/></w:pPr><w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">${escapeXml(options.text)}</w:t></w:r></w:p>`
    + "</w:txbxContent></wps:txbx>"
    + '<wps:bodyPr wrap="square" lIns="91440" tIns="45720" rIns="91440" bIns="45720" anchor="t"/>'
    + "</wps:wsp></a:graphicData></a:graphic>";
  const extent = `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/>`;
  const block = holder(`<w:r><w:drawing ${NS}><wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="251659264"`
    + ' behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
    + `<wp:positionH relativeFrom="page"><wp:posOffset>${emu(options.xPt)}</wp:posOffset></wp:positionH>`
    + `<wp:positionV relativeFrom="page"><wp:posOffset>${emu(options.yPt)}</wp:posOffset></wp:positionV>`
    + `${extent}<wp:wrapSquare wrapText="bothSides"/>${graphic}</wp:anchor></w:drawing></w:r>`);
  const xml = insertAfterParagraph(doc.xml, paraId, block);
  return xml ? pack(doc.files, xml) : null;
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

/** Store the picture in the package and return its relationship id; null when a part is missing. */
function addPicturePart(files: Record<string, Uint8Array>, image: CoverInput["image"], prefix: "cover" | "closing") {
  const relsPath = "word/_rels/document.xml.rels";
  const rels = files[relsPath] ? strFromU8(files[relsPath]) : null;
  const types = files["[Content_Types].xml"] ? strFromU8(files["[Content_Types].xml"]) : null;
  if (!rels || !types) return null;
  const extension = mediaExtension(image.mime);
  const relationPrefix = prefix === "cover" ? "rIdHocCover" : "rIdHocClosing";
  let n = 1;
  while (files[`word/media/hoc-${prefix}-${n}.${extension}`] || rels.includes(`${relationPrefix}${n}"`)) n += 1;
  const relationshipId = `${relationPrefix}${n}`;
  // A copy on a plain ArrayBuffer, the type fflate's file map expects.
  files[`word/media/hoc-${prefix}-${n}.${extension}`] = new Uint8Array(image.bytes);
  files[relsPath] = strToU8(rels.replace("</Relationships>",
    `<Relationship Id="${relationshipId}" Type="${IMAGE_REL}" Target="media/hoc-${prefix}-${n}.${extension}"/></Relationships>`));
  if (!new RegExp(`<Default Extension="${extension}"`, "i").test(types)) {
    files["[Content_Types].xml"] = strToU8(types.replace("<Default ", `<Default Extension="${extension}" ContentType="${image.mime}"/><Default `));
  }
  return relationshipId;
}

/** Remove a picture relationship and its media file once nothing points at it. */
function dropPicturePart(files: Record<string, Uint8Array>, relationshipId: string) {
  const relsPath = "word/_rels/document.xml.rels";
  const rels = files[relsPath] ? strFromU8(files[relsPath]) : null;
  if (!rels) return;
  const relation = new RegExp(`<Relationship\\b[^>]*\\bId="${relationshipId}"[^>]*/>`).exec(rels);
  if (!relation) return;
  const target = /\bTarget="([^"]+)"/.exec(relation[0])?.[1];
  const remaining = rels.replace(relation[0], "");
  files[relsPath] = strToU8(remaining);
  if (target && !remaining.includes(`Target="${target}"`)) delete files[`word/${target}`];
}

function srcRect(crop: CropPercent) {
  const value = (percent: number) => Math.round(Math.max(0, percent) * 1000);
  return `<a:srcRect l="${value(crop.left)}" t="${value(crop.top)}" r="${value(crop.right)}" b="${value(crop.bottom)}"/>`;
}

/** A picture anchored at the page's top-left corner and as large as the page. */
function fullPagePicture(options: { id: number; name: string; relationshipId: string; crop: CropPercent; widthPt: number; heightPt: number; behind: boolean }) {
  const cx = emu(options.widthPt);
  const cy = emu(options.heightPt);
  return `<w:drawing ${NS}><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="${options.behind ? 251658240 : 251660288}"`
    + ` behindDoc="${options.behind ? 1 : 0}" locked="0" layoutInCell="0" allowOverlap="1"><wp:simplePos x="0" y="0"/>`
    + '<wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    + '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>'
    + `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>`
    + `<wp:docPr id="${options.id}" name="${options.name}"/><wp:cNvGraphicFramePr/>`
    + `<a:graphic><a:graphicData uri="${PIC_URI}"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="${options.name}"/><pic:cNvPicPr/></pic:nvPicPr>`
    + `<pic:blipFill><a:blip r:embed="${options.relationshipId}"/>${srcRect(options.crop)}`
    + `<a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm>`
    + '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing>';
}

/**
 * A new first page: the picture covers it behind the text, then the lines as ordinary text
 * (title first) around the middle of the page, and no header or footer. The rest of the
 * document starts on the next page.
 */
export function addCover(docx: Uint8Array, input: CoverInput): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc || !doc.xml.includes("<w:body>")) return null;
  const { files } = doc;
  const relationshipId = addPicturePart(files, input.image, "cover");
  if (!relationshipId) return null;
  const background = fullPagePicture({
    id: nextDrawingId(doc.xml),
    name: "Cover picture",
    relationshipId,
    crop: input.image.crop,
    widthPt: input.pageWidthPt,
    heightPt: input.pageHeightPt,
    behind: true,
  });

  const lines = input.lines.map((line) => line.trim()).filter(Boolean);
  const text = lines.map((line, index) => (index === 0
    ? coverLine(line, 30, true, input.pageHeightPt * 0.28)
    : coverLine(line, 16, false, index === 1 ? 18 : 4))).join("");
  const cover = holder(`<w:r>${background}</w:r>`) + text + holder('<w:r><w:br w:type="page"/></w:r>');
  const xml = withTitlePage(doc.xml.replace("<w:body>", `<w:body>${cover}`));
  return pack(files, xml);
}

const CLOSING_NAME = "Closing page picture";

export type ClosingPageInput = Omit<CoverInput, "lines">;

/**
 * A last page covered by the picture, in front of the header and footer. When the report
 * already has one, its picture is replaced and no second page is added.
 */
export function addClosingPage(docx: Uint8Array, input: ClosingPageInput): Uint8Array | null {
  const doc = unpack(docx);
  if (!doc || !doc.xml.includes("</w:body>")) return null;
  const { files } = doc;
  const relationshipId = addPicturePart(files, input.image, "closing");
  if (!relationshipId) return null;

  const named = doc.xml.indexOf(`name="${CLOSING_NAME}"`);
  if (named >= 0) {
    const start = doc.xml.lastIndexOf("<wp:anchor", named);
    const end = doc.xml.indexOf("</wp:anchor>", named);
    if (start >= 0 && end > start) {
      const old = doc.xml.slice(start, end);
      const previous = /<a:blip\b[^>]*\br:embed="([^"]*)"/.exec(old)?.[1];
      const anchor = old
        .replace(/(<a:blip\b[^>]*\br:embed=")[^"]*"/, `$1${relationshipId}"`)
        .replace(/<a:srcRect\b[^>]*\/>/, srcRect(input.image.crop));
      const xml = doc.xml.slice(0, start) + anchor + doc.xml.slice(end);
      if (previous && !xml.includes(`r:embed="${previous}"`)) dropPicturePart(files, previous);
      return pack(files, xml);
    }
  }

  const picture = fullPagePicture({
    id: nextDrawingId(doc.xml),
    name: CLOSING_NAME,
    relationshipId,
    crop: input.image.crop,
    widthPt: input.pageWidthPt,
    heightPt: input.pageHeightPt,
    behind: false,
  });
  // The editor starts no page for a break followed only by a floating picture in one paragraph.
  const page = `<w:p><w:pPr><w:bidi/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>${holder(`<w:r>${picture}</w:r>`)}`;
  // The last section's properties close the body; the page goes right before them.
  const bodyEnd = doc.xml.lastIndexOf("</w:body>");
  const section = doc.xml.lastIndexOf("<w:sectPr", bodyEnd);
  const sectionClose = section >= 0 ? doc.xml.indexOf("</w:sectPr>", section) : -1;
  const closesBody = sectionClose >= 0 && /^<\/w:sectPr>\s*<\/w:body>/.test(doc.xml.slice(sectionClose));
  const at = closesBody ? section : bodyEnd;
  return pack(files, doc.xml.slice(0, at) + page + doc.xml.slice(at));
}
