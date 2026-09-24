import { toJpeg } from "html-to-image";
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

/**
 * The editor paints text with faces it registers under private names (docx-embedded-*).
 * html-to-image draws each page inside an isolated SVG that cannot see those faces, so give it
 * the same font files as data URLs under the same names.
 */
async function embeddedFontCss(): Promise<string> {
  fontData ??= Promise.all([fileAsDataUrl(REPORT_FONT_FILES.regular), fileAsDataUrl(REPORT_FONT_FILES.bold)])
    .then(([regular, bold]) => ({ regular, bold }));
  const data = await fontData;
  const seen = new Set<string>();
  return Array.from(document.fonts)
    .filter((face) => face.family.replace(/["']/g, "").startsWith("docx-embedded-"))
    .map((face) => {
      const family = face.family.replace(/["']/g, "");
      const heavy = Number.parseInt(face.weight, 10) >= 600;
      const key = `${family}|${face.weight}|${face.style}`;
      if (seen.has(key)) return "";
      seen.add(key);
      return `@font-face{font-family:"${family}";font-weight:${face.weight};font-style:${face.style};src:url(${heavy ? data.bold : data.regular}) format("truetype");}`;
    })
    .join("");
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
  const fontEmbedCSS = await embeddedFontCss();
  const first = pageAt(0);
  const scroller = first ? scrollParent(first) : null;
  const scrollTop = scroller?.scrollTop ?? 0;
  const pdf = await PDFDocument.create();

  try {
    for (let index = 0; index < count; index += 1) {
      pageAt(index)?.scrollIntoView({ block: "nearest" });
      const current = pageAt(index);
      if (!current) continue;
      await painted(current);
      const page = pageAt(index);
      if (!page) continue;
      const width = page.offsetWidth;
      const height = page.offsetHeight;
      // Each page sits absolutely positioned down the stack, and html-to-image keeps that offset
      // in its copy (an override on the copy is not applied), so move the page to the origin
      // just for the capture.
      const top = page.style.top;
      page.style.top = "0px";
      let image: string;
      try {
        image = await toJpeg(page, {
          width,
          height,
          pixelRatio: 2 / scale,
          quality: 0.92,
          backgroundColor: "#ffffff",
          fontEmbedCSS,
          cacheBust: false,
        });
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

