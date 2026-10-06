import { toSvg } from "html-to-image";
import { PDFDocument } from "pdf-lib";
import { REPORT_FONT_FILES } from "./fonts";

const PX_TO_PT = 72 / 96;

let fontData: Promise<Record<"regular" | "bold", string>> | null = null;

function fileAsDataUrl(url: string): Promise<string> {
  return fetch(url)
    .then((response) => {
      if (!response.ok) throw new Error(`font ${response.status}`);
      return response.blob();
    })
    .then((blob) => new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(blob);
    }));
}

/** Family names actually painted on this page (the editor's private docx-embedded-* names). */
function familiesOn(page: HTMLElement): Set<string> {
  const names = new Set<string>();
  const add = (value: string) => {
    for (const part of value.split(",")) {
      const name = part.trim().replace(/^["']|["']$/g, "");
      if (name) names.add(name);
    }
  };
  add(getComputedStyle(page).fontFamily);
  page.querySelectorAll<HTMLElement>("*").forEach((node) => add(getComputedStyle(node).fontFamily));
  return names;
}

/**
 * The editor paints text with faces it registers under private names (docx-embedded-*).
 * html-to-image draws each page inside an isolated SVG that cannot see those faces, so give it
 * the same font files as data URLs under the same names.
 * Only the faces this page uses are embedded. The editor also registers Arial, Tahoma, and the
 * other fallbacks, and embedding every one of them makes the page image too large to load.
 */
async function embeddedFontCss(page: HTMLElement): Promise<string> {
  const used = familiesOn(page);
  fontData ??= Promise.all([fileAsDataUrl(REPORT_FONT_FILES.regular), fileAsDataUrl(REPORT_FONT_FILES.bold)])
    .then(([regular, bold]) => ({ regular, bold }));
  const data = await fontData;
  const seen = new Set<string>();
  const rule = (family: string, weight: string, style: string, url: string) => {
    const key = `${family}|${weight}|${style}`;
    if (seen.has(key)) return "";
    seen.add(key);
    return `@font-face{font-family:"${family}";font-weight:${weight};font-style:${style};src:url(${url}) format("truetype");}`;
  };
  const faces = Array.from(document.fonts).filter((face) => used.has(face.family.replace(/["']/g, "")));
  if (faces.length > 0) {
    return faces
      .map((face) => {
        const family = face.family.replace(/["']/g, "");
        const heavy = Number.parseInt(face.weight, 10) >= 600;
        return rule(family, face.weight, face.style, heavy ? data.bold : data.regular);
      })
      .join("");
  }
  return [...used]
    .filter((name) => name.startsWith("docx-embedded-"))
    .map((family) => rule(family, "400", "normal", data.regular) + rule(family, "700", "normal", data.bold))
    .join("");
}

/** Standard properties only. Custom properties keep color-mix() text, which makes the page image fail to load. */
function styleProperties(): string[] {
  return Array.from(getComputedStyle(document.documentElement)).filter((name) => !name.startsWith("--") && name !== "content");
}

const COLOR_FN = /(?:color-mix|oklch|oklab|lab|lch|color)\((?:[^()]|\([^()]*\))*\)/g;

/** Turn modern color functions into a computed rgb() the SVG image can paint. */
function resolveColors(markup: string): string {
  const withoutContent = markup.replace(/style="([^"]*)"/g, (_match, css: string) => {
    const cleaned = css.replace(/(?:^|;)\s*content:\s*[^;"]*/g, "");
    return `style="${cleaned}"`;
  });
  if (!/(?:color-mix|oklch|oklab|\blab\(|\blch\(|\bcolor\()/.test(withoutContent)) return withoutContent;
  const probe = document.createElement("span");
  document.body.appendChild(probe);
  try {
    return withoutContent.replace(COLOR_FN, (fn) => {
      probe.style.color = "";
      probe.style.color = fn;
      const resolved = getComputedStyle(probe).color;
      return resolved && !/(?:color-mix|oklch|oklab)/.test(resolved) ? resolved : "#1a0838";
    });
  } finally {
    probe.remove();
  }
}

function captureError(reason: unknown): Error {
  return reason instanceof Error ? reason : new Error("pdf-capture");
}

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error("pdf-capture"));
    image.src = url;
  });
}

/**
 * Draw one page to a JPEG. html-to-image's own JPEG path loads the page as a data URL and,
 * when that image fails, rejects with a DOM event rather than an Error.
 */
async function pageJpeg(page: HTMLElement, width: number, height: number, pixelRatio: number, fontEmbedCSS: string): Promise<string> {
  let svg: string;
  try {
    svg = await toSvg(page, {
      width,
      height,
      fontEmbedCSS,
      cacheBust: false,
      includeStyleProperties: styleProperties(),
      onImageErrorHandler: () => undefined,
    });
  } catch (reason) {
    throw captureError(reason);
  }
  const comma = svg.indexOf(",");
  // Computed styles can contain form feeds and other controls. They are illegal in SVG XML,
  // so the page image fails to load until those characters are removed.
  const markup = resolveColors(decodeURIComponent(svg.slice(comma + 1)))
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]/g, "");
  const url = URL.createObjectURL(new Blob([markup], { type: "image/svg+xml;charset=utf-8" }));
  try {
    const image = await loadImage(url);
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(width * pixelRatio));
    canvas.height = Math.max(1, Math.round(height * pixelRatio));
    const context = canvas.getContext("2d");
    if (!context) throw new Error("pdf-capture");
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.92);
  } catch (reason) {
    throw captureError(reason);
  } finally {
    URL.revokeObjectURL(url);
  }
}

function scrollParent(element: HTMLElement): HTMLElement | null {
  let node = element.parentElement;
  while (node) {
    const style = getComputedStyle(node);
    if (/(auto|scroll)/.test(style.overflowY) && node.scrollHeight > node.clientHeight) return node;
    node = node.parentElement;
  }
  return null;
}

function frames(count: number) {
  return new Promise<void>((resolve) => {
    const step = (left: number) => (left <= 0 ? resolve() : requestAnimationFrame(() => step(left - 1)));
    step(count);
  });
}

/** Wait until a page scrolled into view has painted its content (the editor paints lazily). */
async function painted(page: HTMLElement) {
  await frames(2);
  const started = performance.now();
  while (performance.now() - started < 2500) {
    if ((page.textContent ?? "").trim() !== "" || page.querySelector("img, svg, canvas")) break;
    await new Promise((resolve) => setTimeout(resolve, 60));
  }
  await document.fonts.ready;
  await frames(2);
}

/**
 * Turn the painted pages of the editor into a PDF, one image per page, sized from each page.
 * The text is an image (not selectable), but it looks exactly like the editor, Arabic included.
 */
export async function pagesToPdf(root: HTMLElement, zoom: number, onProgress?: (done: number, total: number) => void): Promise<Uint8Array> {
  // Pages are painted at the editor's zoom; divide it out so the PDF gets the real paper size.
  const scale = zoom > 0 ? zoom : 1;
  const count = root.querySelectorAll(".docx-page").length;
  if (count === 0) throw new Error("no pages");
  // The editor may rebuild page elements while painting (theme switch, lazy paint), so look each
  // page up again by its index right before drawing it.
  const pageAt = (index: number) =>
    root.querySelector<HTMLElement>(`.docx-page[data-page-index="${index}"]`) ?? root.querySelectorAll<HTMLElement>(".docx-page")[index] ?? null;
  const first = pageAt(0);
  const scroller = first ? scrollParent(first) : null;
  const scrollTop = scroller?.scrollTop ?? 0;
  const pdf = await PDFDocument.create();
  let fontEmbedCSS: string | null = null;

  try {
    for (let index = 0; index < count; index += 1) {
      pageAt(index)?.scrollIntoView({ block: "nearest" });
      const current = pageAt(index);
      if (!current) continue;
      await painted(current);
      const page = pageAt(index);
      if (!page) continue;
      fontEmbedCSS ??= await embeddedFontCss(page);
      const width = page.offsetWidth;
      const height = page.offsetHeight;
      // Each page sits absolutely positioned down the stack, and html-to-image keeps that offset
      // in its copy (an override on the copy is not applied), so move the page to the origin
      // just for the capture.
      const top = page.style.top;
      page.style.top = "0px";
      let image: string;
      try {
        image = await pageJpeg(page, width, height, 2 / scale, fontEmbedCSS);
      } finally {
        page.style.top = top;
      }
      const embedded = await pdf.embedJpg(image);
      const sheetWidth = (width / scale) * PX_TO_PT;
      const sheetHeight = (height / scale) * PX_TO_PT;
      const sheet = pdf.addPage([sheetWidth, sheetHeight]);
      sheet.drawImage(embedded, { x: 0, y: 0, width: sheetWidth, height: sheetHeight });
      onProgress?.(index + 1, count);
    }
  } finally {
    if (scroller) scroller.scrollTop = scrollTop;
  }

  return pdf.save();
}

