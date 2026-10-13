import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";

// The packaged editor paints Word text boxes but does not select, move, delete or type in them.
// These edits rewrite the box in document.xml; the editor then reloads the file. A box is found
// by the node id the editor paints on it (`data-drawing-node-id`): element child indexes from
// the document root, for example `/word/document.xml#0.0.1.1.0`.

const W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main";
const WP_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing";
const A_NS = "http://schemas.openxmlformats.org/drawingml/2006/main";
const EMU_PER_POINT = 12700;

export type TextBoxFrame = { x: number; y: number; width: number; height: number };

type Loaded = { files: Record<string, Uint8Array>; doc: XMLDocument; declaration: string; drawing: Element };

function elementChildren(node: Node) {
  return Array.from(node.childNodes).filter((child): child is Element => child.nodeType === 1);
}

function byLocalName(root: Element, namespace: string, name: string) {
  return Array.from(root.getElementsByTagNameNS(namespace, name));
}

function load(docx: Uint8Array, nodeId: string): Loaded | null {
  const [part, path] = nodeId.split("#");
  if (part !== "/word/document.xml" || !path) return null;
  const files = unzipSync(docx);
  const raw = files["word/document.xml"];
  if (!raw) return null;
  const xml = strFromU8(raw);
  const doc = new DOMParser().parseFromString(xml, "application/xml");
  if (doc.getElementsByTagName("parsererror").length > 0) return null;
  let node: Node = doc;
  for (const index of path.split(".").map(Number)) {
    const next = elementChildren(node)[index];
    if (!next) return null;
    node = next;
  }
  const element = node as Element;
  let drawing: Element | null = element;
  while (drawing && !(drawing.localName === "drawing" && drawing.namespaceURI === W_NS)) drawing = drawing.parentElement;
  drawing ??= byLocalName(element, W_NS, "drawing")[0] ?? null;
  if (!drawing || byLocalName(drawing, W_NS, "txbxContent").length === 0) return null;
  const declaration = /^<\?xml[^>]*\?>/.exec(xml)?.[0] ?? "";
  return { files, doc, declaration, drawing };
}

function pack({ files, doc, declaration }: Loaded) {
  const body = new XMLSerializer().serializeToString(doc).replace(/^<\?xml[^>]*\?>/, "");
  files["word/document.xml"] = strToU8(declaration + body);
  return zipSync(files);
}

/** The run that carries the box; Word may wrap the drawing in `mc:AlternateContent`. */
function owningRun(drawing: Element) {
  let node: Element | null = drawing;
  while (node && !(node.localName === "r" && node.namespaceURI === W_NS)) node = node.parentElement;
  return node;
}

/** Every story of the box: the drawing and, when Word wrote one, its VML fallback. */
function stories(drawing: Element) {
  const run = owningRun(drawing) ?? drawing;
  return byLocalName(run, W_NS, "txbxContent");
}

export function textBoxText(docx: Uint8Array, nodeId: string): string | null {
  const loaded = load(docx, nodeId);
  const story = loaded ? byLocalName(loaded.drawing, W_NS, "txbxContent")[0] : null;
  if (!story) return null;
  return elementChildren(story)
    .filter((child) => child.localName === "p")
    .map((paragraph) => byLocalName(paragraph, W_NS, "t").map((t) => t.textContent ?? "").join(""))
    .join("\n");
}

/** Replace the words in the box. The first paragraph's and first run's formatting carry over. */
export function setTextBoxText(docx: Uint8Array, nodeId: string, text: string): Uint8Array | null {
  const loaded = load(docx, nodeId);
  if (!loaded) return null;
  const { doc } = loaded;
  for (const story of stories(loaded.drawing)) {
    const first = elementChildren(story).find((child) => child.localName === "p");
    const pPr = first ? elementChildren(first).find((child) => child.localName === "pPr") : undefined;
    const rPr = first ? byLocalName(first, W_NS, "rPr").find((props) => props.parentElement?.localName === "r") : undefined;
    while (story.firstChild) story.removeChild(story.firstChild);
    for (const line of text.split("\n")) {
      const paragraph = doc.createElementNS(W_NS, "w:p");
      const arabic = /[\u0600-\u06FF]/.test(line);
      if (pPr) paragraph.appendChild(pPr.cloneNode(true));
      else if (arabic) paragraph.appendChild(doc.createElementNS(W_NS, "w:pPr")).appendChild(doc.createElementNS(W_NS, "w:bidi"));
      if (line !== "") {
        const run = paragraph.appendChild(doc.createElementNS(W_NS, "w:r"));
        if (rPr) run.appendChild(rPr.cloneNode(true));
        else if (arabic) run.appendChild(doc.createElementNS(W_NS, "w:rPr")).appendChild(doc.createElementNS(W_NS, "w:rtl"));
        const t = run.appendChild(doc.createElementNS(W_NS, "w:t"));
        t.setAttributeNS("http://www.w3.org/XML/1998/namespace", "xml:space", "preserve");
        t.textContent = line;
      }
      story.appendChild(paragraph);
    }
  }
  return pack(loaded);
}

/** Remove the box, and the paragraph that only held it when other content remains around it. */
export function deleteTextBox(docx: Uint8Array, nodeId: string): Uint8Array | null {
  const loaded = load(docx, nodeId);
  if (!loaded) return null;
  const run = owningRun(loaded.drawing);
  if (!run) return null;
  const paragraph = run.parentElement;
  run.remove();
  if (paragraph && paragraph.localName === "p") {
    const left = elementChildren(paragraph).filter((child) => child.localName !== "pPr");
    const container = paragraph.parentElement;
    const siblings = container ? elementChildren(container).filter((child) => child.localName === "p" || child.localName === "tbl") : [];
    if (left.length === 0 && siblings.length > 1) paragraph.remove();
  }
  return pack(loaded);
}

/**
 * Place and size the box on its page. The frame is in the editor's painted page units, from the
 * page's top-left corner; `paintedPageWidth` is the page width in those same units.
 */
export function placeTextBox(docx: Uint8Array, nodeId: string, frame: TextBoxFrame, paintedPageWidth: number): Uint8Array | null {
  const loaded = load(docx, nodeId);
  if (!loaded) return null;
  const { doc, drawing } = loaded;
  const anchor = elementChildren(drawing).find((child) => child.localName === "anchor");
  if (!anchor) return null;
  const pageSize = byLocalName(doc.documentElement, W_NS, "pgSz").pop();
  const pageWidthPt = Number(pageSize?.getAttributeNS(W_NS, "w") || pageSize?.getAttribute("w:w")) / 20;
  const pointsPerUnit = pageWidthPt > 0 && paintedPageWidth > 0 ? pageWidthPt / paintedPageWidth : 1;
  const emu = (units: number) => String(Math.round(units * pointsPerUnit * EMU_PER_POINT));
  const setAxis = (name: "positionH" | "positionV", units: number) => {
    let axis = elementChildren(anchor).find((child) => child.localName === name);
    if (!axis) {
      axis = doc.createElementNS(WP_NS, `wp:${name}`);
      const extent = elementChildren(anchor).find((child) => child.localName === "extent");
      anchor.insertBefore(axis, extent ?? null);
    }
    axis.setAttribute("relativeFrom", "page");
    while (axis.firstChild) axis.removeChild(axis.firstChild);
    axis.appendChild(doc.createElementNS(WP_NS, "wp:posOffset")).textContent = emu(units);
  };
  setAxis("positionH", frame.x);
  setAxis("positionV", frame.y);
  anchor.setAttribute("simplePos", "0");
  const cx = emu(frame.width);
  const cy = emu(frame.height);
  for (const extent of elementChildren(anchor).filter((child) => child.localName === "extent")) {
    extent.setAttribute("cx", cx);
    extent.setAttribute("cy", cy);
  }
  for (const ext of byLocalName(drawing, A_NS, "ext").filter((node) => node.parentElement?.localName === "xfrm")) {
    ext.setAttribute("cx", cx);
    ext.setAttribute("cy", cy);
  }
  // A rounded rectangle is drawn as a path sized to the box; stretch it to the new size.
  for (const path of byLocalName(drawing, A_NS, "path")) {
    const oldWidth = Number(path.getAttribute("w"));
    const oldHeight = Number(path.getAttribute("h"));
    if (!oldWidth || !oldHeight) continue;
    path.setAttribute("w", cx);
    path.setAttribute("h", cy);
    for (const point of byLocalName(path, A_NS, "pt")) {
      point.setAttribute("x", String(Math.round(Number(point.getAttribute("x")) * (Number(cx) / oldWidth))));
      point.setAttribute("y", String(Math.round(Number(point.getAttribute("y")) * (Number(cy) / oldHeight))));
    }
  }
  return pack(loaded);
}
