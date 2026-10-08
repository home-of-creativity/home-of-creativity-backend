import { toSvg } from "html-to-image";
import { PDFDocument } from "pdf-lib";
import { REPORT_FONT_FILES } from "./fonts";

const PX_TO_PT = 72 / 96;
const IMAGE_PLACEHOLDER =
  "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==";
const WHITE_JPEG =
  "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=";
const FALLBACK_PDF = Uint8Array.from(atob(
  "JVBERi0xLjcKJYGBgYEKCjUgMCBvYmoKPDwKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL1R5cGUgL09ialN0bQovTiA0Ci9GaXJzdCAyMAovTGVuZ3RoIDI2OAo+PgpzdHJlYW0KeJzVkslqwzAQhu96ijm2l2i0WJaLMaReLqUQQk8NPYhYBEOJghdo374jK23pofRcxI+W+UbbPwIQJGgNCnILGjIloSwZf3q/eOA7d/IT4w9DP8GBogh7eGG8Dst5BsGqin2ztZvdazixlAQiwp/Ebgz9cvQjlF3bdYg5IhpNMoiyob4mFSRJc4pJS2NSrq+itVwhqi3FuiSTp5wYX9nsmt9ST6yJTJNYbdP869x4Vpv2kH/dp6gYfwx942YPN82dRGkEohVKaa2eb+k7Ru/m8H8ft95/COdfX/jD52hvNHn0sQZWl/neT2EZj2Q7cVX8L98P7j68UdUgtazINtKC1WJjC6ogQj4Ak0SPHQplbmRzdHJlYW0KZW5kb2JqCgo2IDAgb2JqCjw8Ci9TaXplIDcKL1Jvb3QgMiAwIFIKL0luZm8gMyAwIFIKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL1R5cGUgL1hSZWYKL0xlbmd0aCAzNAovVyBbIDEgMiAyIF0KL0luZGV4IFsgMCA3IF0KPj4Kc3RyZWFtCnicFcQxDgAgCASwHsbdN/txCB2K7nLZstV24pF8BkOhArYKZW5kc3RyZWFtCmVuZG9iagoKc3RhcnR4cmVmCjM4NgolJUVPRg==",
), (char) => char.charCodeAt(0));

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
  try {
    const used = familiesOn(page);
    fontData ??= Promise.all([fileAsDataUrl(REPORT_FONT_FILES.regular), fileAsDataUrl(REPORT_FONT_FILES.bold)])
      .then(([regular, bold]) => ({ regular, bold }));
    const data = await fontData;
    const families = new Set<string>();
    for (const face of document.fonts) {
      const family = face.family.replace(/["']/g, "");
      if (used.has(family)) families.add(family);
    }
    for (const name of used) {
      if (name.startsWith("docx-embedded-")) families.add(name);
    }
    const ranked = [...families].sort((a, b) => fontRank(b) - fontRank(a)).slice(0, 2);
    return ranked
      .map((family) =>
        `@font-face{font-family:"${family}";font-weight:400;font-style:normal;src:url(${data.regular}) format("truetype");}`
        + `@font-face{font-family:"${family}";font-weight:700;font-style:normal;src:url(${data.bold}) format("truetype");}`)
      .join("");
  } catch {
    return "";
  }
}

function fontRank(name: string): number {
  if (name.startsWith("docx-embedded-")) return 3;
  if (/plex|arabic/i.test(name)) return 2;
  return 1;
}

/** Standard properties only. Custom properties keep color-mix() text, which makes the page image fail to load. */
function styleProperties(): string[] {
  return Array.from(getComputedStyle(document.documentElement)).filter((name) => !name.startsWith("--") && name !== "content");
}

const COLOR_FN = /(?:color-mix|oklch|oklab|lab|lch|color)\((?:[^()]|\([^()]*\))*\)/g;

/** Turn modern color functions into a computed rgb() the SVG image can paint. */
function resolveColors(markup: string): string {
  let current = markup.replace(/style="([^"]*)"/g, (_match, css: string) => {
    const cleaned = css.replace(/(?:^|;)\s*content:\s*[^;"]*/g, "");
    return `style="${cleaned}"`;
  });
  const probe = document.createElement("span");
  document.body.appendChild(probe);
  try {
    for (let pass = 0; pass < 4; pass += 1) {
      COLOR_FN.lastIndex = 0;
      if (!COLOR_FN.test(current)) break;
      COLOR_FN.lastIndex = 0;
      current = current.replace(COLOR_FN, (fn) => {
        probe.style.color = "";
        probe.style.color = fn;
        const resolved = getComputedStyle(probe).color;
        return resolved && !/(?:color-mix|oklch|oklab)/.test(resolved) ? resolved : "#1a0838";
      });
    }
  } finally {
    probe.remove();
  }
  COLOR_FN.lastIndex = 0;
  return current.replace(COLOR_FN, "#1a0838");
}

/** Drop effects and outside pictures that stop the browser from painting the page image. */
function softenMarkup(markup: string): string {
  return resolveColors(markup)
    .replace(/backdrop-filter\s*:[^;"]*;?/gi, "")
    .replace(/(?:^|;)(\s*filter\s*:[^;"]*)/gi, "")
    .replace(/url\((['"]?)(?!data:)[^)'"]*\1\)/gi, "none");
}

function captureError(reason: unknown): Error {
  return reason instanceof Error ? reason : new Error("pdf-capture");
}

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    const timer = window.setTimeout(() => {
      image.onload = null;
      image.onerror = null;
      reject(new Error("pdf-capture"));
    }, 8000);
    image.onload = () => {
      window.clearTimeout(timer);
      resolve(image);
    };
    image.onerror = () => {
      window.clearTimeout(timer);
      reject(new Error("pdf-capture"));
    };
    image.src = url;
  });
}

/**
 * Draw one page to a JPEG. html-to-image's own JPEG path loads the page as a data URL and,
 * when that image fails, rejects with a DOM event rather than an Error.
 */
function svgMarkup(svg: string): string {
  const comma = svg.indexOf(",");
  if (comma < 0) throw new Error("pdf-capture");
  const header = svg.slice(0, comma);
  const payload = svg.slice(comma + 1);
  let decoded: string;
  try {
    if (/;base64/i.test(header)) {
      const binary = atob(payload);
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
      decoded = new TextDecoder().decode(bytes);
    } else {
      decoded = decodeURIComponent(payload);
    }
  } catch {
    throw new Error("pdf-capture");
  }
  // Computed styles can contain form feeds and other controls. They are illegal in SVG XML,
  // so the page image fails to load until those characters are removed.
  return resolveColors(decoded).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]/g, "");
}

function skipChrome(node: HTMLElement): boolean {
  try {
    const cls = typeof node?.className === "string" ? node.className : "";
    return !/\b(?:docx-selection|docx-caret|docx-comment|table-select-handle|table-layout)/.test(cls);
  } catch {
    return true;
  }
}

async function pageJpeg(page: HTMLElement, width: number, height: number, pixelRatio: number, fontEmbedCSS: string): Promise<string> {
  let svg: string;
  try {
    svg = await toSvg(page, {
      width,
      height,
      fontEmbedCSS,
      cacheBust: false,
      backgroundColor: "#ffffff",
      imagePlaceholder: IMAGE_PLACEHOLDER,
      includeStyleProperties: styleProperties(),
      filter: skipChrome,
      onImageErrorHandler: () => undefined,
    });
  } catch (reason) {
    throw captureError(reason);
  }
  const markup = softenMarkup(svgMarkup(svg));
  const url = URL.createObjectURL(new Blob([markup], { type: "image/svg+xml;charset=utf-8" }));
  try {
    const image = await loadImage(url);
    const longest = Math.max(width, height);
    const ratio = longest * pixelRatio > 4096 ? 4096 / longest : pixelRatio;
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(width * ratio));
    canvas.height = Math.max(1, Math.round(height * ratio));
    const context = canvas.getContext("2d");
    if (!context) throw new Error("pdf-capture");
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.86);
  } catch (reason) {
    throw captureError(reason);
  } finally {
    URL.revokeObjectURL(url);
  }
}

function reveal(page: HTMLElement) {
  const scroller = scrollParent(page);
  if (!scroller) {
    page.scrollIntoView({ block: "center", inline: "nearest" });
    return;
  }
  const pageBox = page.getBoundingClientRect();
  const box = scroller.getBoundingClientRect();
  scroller.scrollTop += pageBox.top - box.top - (box.height - pageBox.height) / 2;
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
  await document.fonts.ready.catch(() => undefined);
  await frames(2);
}

/** One white page when nothing in the editor can be turned into a PDF. */
export async function fallbackPdf(): Promise<Uint8Array> {
  try {
    const pdf = await PDFDocument.create();
    pdf.addPage([595.28, 841.89]);
    const bytes = await pdf.save();
    if (bytes.byteLength > 32) return bytes;
  } catch {
    // The baked file below is a valid one-page PDF.
  }
  return FALLBACK_PDF;
}

/** A readable page when the painted capture cannot be drawn, so the download still finishes. */
function textJpeg(page: HTMLElement, width: number, height: number): string {
  try {
  const ratio = Math.min(2, 4096 / Math.max(width, height, 1));
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(width * ratio));
  canvas.height = Math.max(1, Math.round(height * ratio));
  const context = canvas.getContext("2d");
  if (!context) return WHITE_JPEG;
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.fillStyle = "#1a0838";
  const fontSize = Math.max(12, Math.round(14 * ratio));
  const family = getComputedStyle(page).fontFamily || '"IBM Plex Sans Arabic", sans-serif';
  context.font = `${fontSize}px ${family}`;
  const rtl = getComputedStyle(page).direction !== "ltr";
  context.direction = rtl ? "rtl" : "ltr";
  context.textAlign = rtl ? "right" : "left";
  const margin = Math.round(36 * ratio);
  const maxWidth = Math.max(40, canvas.width - margin * 2);
  const lines: string[] = [];
  for (const paragraph of (page.innerText || "").split(/\n/)) {
    const words = paragraph.split(/\s+/).filter(Boolean);
    if (words.length === 0) {
      lines.push("");
      continue;
    }
    let line = "";
    for (const word of words) {
      const next = line ? `${line} ${word}` : word;
      if (context.measureText(next).width > maxWidth && line) {
        lines.push(line);
        line = word;
      } else {
        line = next;
      }
    }
    if (line) lines.push(line);
  }
  const step = Math.round(fontSize * 1.45);
  const x = rtl ? canvas.width - margin : margin;
  let y = margin + fontSize;
  for (const line of lines) {
    if (y > canvas.height - margin) break;
    context.fillText(line, x, y);
    y += step;
  }
  return canvas.toDataURL("image/jpeg", 0.86);
  } catch {
    return WHITE_JPEG;
  }
}

async function drawPage(pdf: PDFDocument, image: string, alt: string, width: number, height: number) {
  for (const candidate of [image, alt, WHITE_JPEG]) {
    try {
      const embedded = await pdf.embedJpg(candidate);
      const sheet = pdf.addPage([width, height]);
      sheet.drawImage(embedded, { x: 0, y: 0, width, height });
      return;
    } catch {
      // The next picture is plainer and still fills the page.
    }
  }
  pdf.addPage([width, height]);
}

/**
 * Turn the painted pages of the editor into a PDF, one image per page, sized from each page.
 * The text is an image (not selectable), but it looks exactly like the editor, Arabic included.
 * A page that cannot be painted is written from its text so the file still downloads.
 */
export async function pagesToPdf(root: HTMLElement, zoom: number, onProgress?: (done: number, total: number) => void): Promise<Uint8Array> {
  try {
    const bytes = await assemblePdf(root, zoom, onProgress);
    if (bytes.byteLength > 32) return bytes;
  } catch {
    // Every page path already has its own picture. This covers a failure of the file itself.
  }
  return fallbackPdf();
}

async function assemblePdf(root: HTMLElement, zoom: number, onProgress?: (done: number, total: number) => void): Promise<Uint8Array> {
  // Pages are painted at the editor's zoom; divide it out so the PDF gets the real paper size.
  const scale = zoom > 0 ? zoom : 1;
  const count = root.querySelectorAll(".docx-page").length;
  if (count === 0) return fallbackPdf();
  // The editor may rebuild page elements while painting (theme switch, lazy paint), so look each
  // page up again by its index right before drawing it.
  const pageAt = (index: number) =>
    root.querySelector<HTMLElement>(`.docx-page[data-page-index="${index}"]`) ?? root.querySelectorAll<HTMLElement>(".docx-page")[index] ?? null;
  const first = pageAt(0);
  const scroller = first ? scrollParent(first) : null;
  const scrollTop = scroller?.scrollTop ?? 0;
  const pdf = await PDFDocument.create();

  try {
    for (let index = 0; index < count; index += 1) {
      try {
      let page: HTMLElement | null = null;
      for (let attempt = 0; attempt < 3; attempt += 1) {
        page = pageAt(index);
        if (page) reveal(page);
        if (page) await painted(page);
        page = pageAt(index);
        if (page && page.offsetWidth >= 2 && page.offsetHeight >= 2) break;
      }
      if (!page || page.offsetWidth < 2 || page.offsetHeight < 2) {
        pdf.addPage([595.28, 841.89]);
        onProgress?.(index + 1, count);
        continue;
      }
      const fontEmbedCSS = await embeddedFontCss(page);
      const width = page.offsetWidth;
      const height = page.offsetHeight;
      // Each page sits absolutely positioned down the stack, and html-to-image keeps that offset
      // in its copy (an override on the copy is not applied), so move the page to the origin
      // just for the capture.
      const placed = { top: page.style.top, left: page.style.left, transform: page.style.transform };
      page.style.top = "0px";
      page.style.left = "0px";
      page.style.transform = "none";
      let image = WHITE_JPEG;
      try {
        try {
          image = await pageJpeg(page, width, height, 2 / scale, fontEmbedCSS);
        } catch {
          await painted(page);
          try {
            image = await pageJpeg(page, width, height, 2 / scale, fontEmbedCSS);
          } catch {
            image = textJpeg(page, width, height);
          }
        }
      } finally {
        page.style.top = placed.top;
        page.style.left = placed.left;
        page.style.transform = placed.transform;
      }
      const sheetWidth = (width / scale) * PX_TO_PT;
      const sheetHeight = (height / scale) * PX_TO_PT;
      await drawPage(pdf, image, textJpeg(page, width, height), sheetWidth, sheetHeight);
      onProgress?.(index + 1, count);
      } catch {
        pdf.addPage([595.28, 841.89]);
        onProgress?.(index + 1, count);
      }
    }
  } finally {
    if (scroller) scroller.scrollTop = scrollTop;
  }

  return pdf.save();
}

