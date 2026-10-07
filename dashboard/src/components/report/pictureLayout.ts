import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";

// The editor inserts every picture inline, inside the paragraph at the caret, and only lets an
// already floating picture change its wrap. So after the insert the picture is moved in the
// .docx XML and the document is reloaded. The picture always gets a paragraph of its own: a
// right-to-left paragraph that holds a picture next to its text is drawn with its words reversed.

const EMU_PER_POINT = 12700;
const DRAWING = /<w:drawing\b[\s\S]*?<\/w:drawing>/g;

/** Crop per edge, in percent. */
export type CropPercent = { left: number; top: number; right: number; bottom: number };

export type PictureLayout =
  /** Covers the whole page behind the text, from the page's top-left corner. */
  | { kind: "background"; widthPt: number; heightPt: number; crop: CropPercent }
  /** On its own centred line next to the paragraph the caret was in: text above and below. */
  | { kind: "place" };

/** Every drawing in the body, in document order. */
export function bodyDrawings(docx: Uint8Array): string[] {
  const part = unzipSync(docx)["word/document.xml"];
  return part ? strFromU8(part).match(DRAWING) ?? [] : [];
}

function srcRect(crop: CropPercent) {
  // OOXML crop is in thousandths of a percent.
  const value = (percent: number) => Math.round(Math.max(0, percent) * 1000);
  return `<a:srcRect l="${value(crop.left)}" t="${value(crop.top)}" r="${value(crop.right)}" b="${value(crop.bottom)}"/>`;
}

const IMAGE_REL = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/image";
const BODY_BACKGROUND = "HOC body background";

export type BodyBackground = {
  bytes: Uint8Array;
  mime: "image/png" | "image/jpeg" | "image/gif";
  crop: CropPercent;
  pageWidthPt: number;
  pageHeightPt: number;
};

/**
 * Put the picture behind the text on every page that uses the ordinary header.
 * The cover keeps the first-page header, so it is left alone. No page number is chosen.
 */
export function addBodyBackground(docx: Uint8Array, input: BodyBackground, scope: "body" | "all" = "body"): Uint8Array | null {
  const files = unzipSync(docx);
  const document = files["word/document.xml"];
  const relsFile = files["word/_rels/document.xml.rels"];
  if (!document || !relsFile) return null;

  const targets = relationshipMap(strFromU8(relsFile));
  const first = new Set<string>();
  const defaults: string[] = [];
  for (const ref of headerReferences(strFromU8(document))) {
    const rel = targets.get(ref.id);
    if (!rel || !rel.type.endsWith("/header")) continue;
    const path = wordPath(rel.target);
    if (ref.type === "first") first.add(path);
    else if (ref.type === "default") defaults.push(path);
  }
  const headers = (scope === "all" ? [...new Set([...defaults, ...first])] : [...new Set(defaults)].filter((path) => !first.has(path)))
    .filter((path) => files[path]);
  if (headers.length === 0) return null;

  const extension = input.mime === "image/jpeg" ? "jpeg" : input.mime === "image/gif" ? "gif" : "png";
  let index = 1;
  while (files[`word/media/hoc-body-bg-${index}.${extension}`]) index += 1;
  const mediaPath = `word/media/hoc-body-bg-${index}.${extension}`;
  files[mediaPath] = new Uint8Array(input.bytes);
  ensureImageContentType(files, extension, input.mime);

  const cx = Math.round(input.pageWidthPt * EMU_PER_POINT);
  const cy = Math.round(input.pageHeightPt * EMU_PER_POINT);
  for (const path of headers) {
    const relsPath = `word/_rels/${path.slice("word/".length)}.rels`;
    files[relsPath] = strToU8(upsertImageRel(
      files[relsPath] ? strFromU8(files[relsPath]) : null,
      "rIdHocBodyBg",
      `media/${mediaPath.slice("word/media/".length)}`,
    ));
    files[path] = strToU8(putBackground(strFromU8(files[path]), backgroundDrawing(cx, cy, input.crop, "rIdHocBodyBg")));
  }

  return zipSync(files);
}

function relationshipMap(xml: string) {
  const map = new Map<string, { type: string; target: string }>();
  for (const tag of xml.match(/<Relationship\b[^>]*\/>/g) ?? []) {
    const id = tag.match(/\bId="([^"]+)"/)?.[1];
    const type = tag.match(/\bType="([^"]+)"/)?.[1];
    const target = tag.match(/\bTarget="([^"]+)"/)?.[1];
    if (id && type && target) map.set(id, { type, target });
  }
  return map;
}

function headerReferences(xml: string) {
  return (xml.match(/<w:headerReference\b[^>]*\/>/g) ?? []).flatMap((tag) => {
    const id = tag.match(/\br:id="([^"]+)"/)?.[1];
    if (!id) return [];
    return [{ type: tag.match(/\bw:type="([^"]+)"/)?.[1] ?? "default", id }];
  });
}

function wordPath(target: string) {
  const clean = target.replace(/\\/g, "/");
  if (clean.startsWith("/")) return clean.slice(1);
  if (clean.startsWith("word/")) return clean;
  return `word/${clean.replace(/^\.\//, "")}`;
}

function upsertImageRel(xml: string | null, id: string, target: string) {
  const tag = `<Relationship Id="${id}" Type="${IMAGE_REL}" Target="${target}"/>`;
  if (!xml) {
    return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${tag}</Relationships>`;
  }
  if (xml.includes(`Id="${id}"`)) {
    return xml.replace(new RegExp(`<Relationship\\b[^>]*\\bId="${id}"[^>]*/>`), tag);
  }
  return xml.replace("</Relationships>", `${tag}</Relationships>`);
}

function ensureImageContentType(files: Record<string, Uint8Array>, extension: string, mime: string) {
  const types = files["[Content_Types].xml"];
  if (!types) return;
  const xml = strFromU8(types);
  if (new RegExp(`<Default Extension="${extension}"`, "i").test(xml)) return;
  files["[Content_Types].xml"] = strToU8(xml.replace("<Default ", `<Default Extension="${extension}" ContentType="${mime}"/><Default `));
}

function backgroundDrawing(cx: number, cy: number, crop: CropPercent, relationshipId: string) {
  const value = (percent: number) => Math.round(Math.max(0, percent) * 1000);
  return '<w:drawing xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
    + ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    + ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
    + ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    + '<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240"'
    + ' behindDoc="1" locked="0" layoutInCell="0" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
    + '<wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    + '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>'
    + `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>`
    + `<wp:docPr id="88001" name="${BODY_BACKGROUND}"/><wp:cNvGraphicFramePr/>`
    + `<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>`
    + '<pic:nvPicPr><pic:cNvPr id="0" name="body-background"/><pic:cNvPicPr/></pic:nvPicPr>'
    + `<pic:blipFill><a:blip r:embed="${relationshipId}"/>`
    + `<a:srcRect l="${value(crop.left)}" t="${value(crop.top)}" r="${value(crop.right)}" b="${value(crop.bottom)}"/>`
    + '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
    + `<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm>`
    + '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing>';
}

function putBackground(header: string, drawing: string) {
  const without = header.replace(/<w:p\b[^>]*>[\s\S]*?name="HOC body background"[\s\S]*?<\/w:p>/g, "");
  const open = without.match(/<w:hdr\b[^>]*>/);
  if (!open || open.index === undefined) return header;
  const at = open.index + open[0].length;
  const paragraph = `<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/></w:pPr><w:r>${drawing}</w:r></w:p>`;
  return without.slice(0, at) + paragraph + without.slice(at);
}

/** The inline drawing as a page-sized picture behind the text. */
function toBackground(drawing: string, layout: Extract<PictureLayout, { kind: "background" }>): string | null {
  const inline = drawing.match(/<wp:inline\b[^>]*>([\s\S]*?)<\/wp:inline>/);
  if (!inline) return null;
  const docPr = inline[1].match(/<wp:docPr\b[^>]*?(?:\/>|>[\s\S]*?<\/wp:docPr>)/)?.[0];
  const frame = inline[1].match(/<wp:cNvGraphicFramePr\b[^>]*?(?:\/>|>[\s\S]*?<\/wp:cNvGraphicFramePr>)/)?.[0]
    ?? "<wp:cNvGraphicFramePr/>";
  let graphic = inline[1].match(/<a:graphic\b[\s\S]*<\/a:graphic>/)?.[0];
  if (!docPr || !graphic) return null;

  const cx = Math.round(layout.widthPt * EMU_PER_POINT);
  const cy = Math.round(layout.heightPt * EMU_PER_POINT);
  const rect = srcRect(layout.crop);
  graphic = graphic.replace(/<a:ext cx="\d+" cy="\d+"\/>/, `<a:ext cx="${cx}" cy="${cy}"/>`);
  graphic = /<a:srcRect\b/.test(graphic)
    ? graphic.replace(/<a:srcRect\b[^>]*?(?:\/>|>[\s\S]*?<\/a:srcRect>)/, rect)
    : graphic.replace(/<a:stretch\b/, `${rect}<a:stretch`);

  const anchor = '<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240"'
    + ' behindDoc="1" locked="0" layoutInCell="0" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
    + '<wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    + '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>'
    + `<wp:extent cx="${cx}" cy="${cy}"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>`
    + `${docPr}${frame}${graphic}</wp:anchor>`;
  return drawing.replace(inline[0], anchor);
}

function lastTagStart(xml: string, tag: string, before: number) {
  return Math.max(xml.lastIndexOf(`<${tag}>`, before), xml.lastIndexOf(`<${tag} `, before));
}

function plainText(xml: string) {
  return (xml.match(/<w:t(?:\s[^>]*)?>[^<]*<\/w:t>/g) ?? []).map((t) => t.replace(/<[^>]+>/g, "")).join("").trim();
}

/**
 * Take the drawing at `start` out of the caret's paragraph and give it a paragraph of its own:
 * after that paragraph, or before it when the caret was at its start.
 */
function moveToOwnParagraph(xml: string, start: number, length: number, drawing: string, layout: PictureLayout): string | null {
  const paragraphStart = lastTagStart(xml, "w:p", start);
  if (paragraphStart < 0) return null;
  const textBeforeCaret = plainText(xml.slice(paragraphStart, start));
  let without = xml.slice(0, start) + xml.slice(start + length);
  // Rejoin the text on both sides of the picture: "…الم" + "نصات" back into one <w:t>.
  const nextText = without.slice(start).match(/^<w:t(?:\s[^>]*)?>/);
  if (nextText && without.slice(0, start).endsWith("</w:t>")) {
    without = without.slice(0, start - "</w:t>".length) + without.slice(start + nextText[0].length);
    const textStart = lastTagStart(without, "w:t", start);
    if (without.startsWith("<w:t>", textStart)) {
      without = `${without.slice(0, textStart)}<w:t xml:space="preserve">${without.slice(textStart + "<w:t>".length)}`;
    }
  }
  const paragraphEnd = without.indexOf("</w:p>", start);
  if (paragraphEnd < 0) return null;
  const pPr = without.slice(paragraphStart, paragraphEnd).match(/<w:pPr\b[\s\S]*?<\/w:pPr>/)?.[0] ?? "";
  const bidi = /<w:bidi\/>|<w:bidi w:val="(?:1|true|on)"\/>/.test(pPr) ? "<w:bidi/>" : "";

  let paragraph: string;
  let after = true;
  if (layout.kind === "background") {
    // A 1pt line: holds the anchor without adding visible space.
    paragraph = `<w:p><w:pPr>${bidi}<w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>`
      + `<w:rPr><w:sz w:val="2"/></w:rPr></w:pPr><w:r>${drawing}</w:r></w:p>`;
  } else {
    paragraph = `<w:p><w:pPr>${bidi}<w:spacing w:before="120" w:after="120"/><w:jc w:val="center"/></w:pPr>`
      + `<w:r>${drawing}</w:r></w:p>`;
    after = textBeforeCaret !== "";
  }
  const at = after ? paragraphEnd + "</w:p>".length : paragraphStart;
  return without.slice(0, at) + paragraph + without.slice(at);
}

/**
 * Lay out the one drawing that is in `docx` but not in `before` (the drawings saved just before
 * the insert). Returns null when that drawing cannot be found or is not inline.
 */
export function layoutNewDrawing(before: string[], docx: Uint8Array, layout: PictureLayout): Uint8Array | null {
  const files = unzipSync(docx);
  const part = files["word/document.xml"];
  if (!part) return null;
  const xml = strFromU8(part);
  const remaining = new Map<string, number>();
  for (const drawing of before) remaining.set(drawing, (remaining.get(drawing) ?? 0) + 1);

  for (const match of xml.matchAll(DRAWING)) {
    const count = remaining.get(match[0]) ?? 0;
    if (count > 0) {
      remaining.set(match[0], count - 1);
      continue;
    }
    if (!match[0].includes("<wp:inline")) return null;
    const drawing = layout.kind === "background" ? toBackground(match[0], layout) : match[0];
    if (!drawing) return null;
    const moved = moveToOwnParagraph(xml, match.index ?? 0, match[0].length, drawing, layout);
    if (!moved) return null;
    files["word/document.xml"] = strToU8(moved);
    return zipSync(files);
  }
  return null;
}
