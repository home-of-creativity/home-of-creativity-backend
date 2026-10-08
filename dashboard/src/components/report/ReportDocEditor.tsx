import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState, type ReactNode } from "react";
import { DocxEditor, normalizeImageBytes, useFonts, type DocxEditorRef } from "@docx-editor.dev/react";
import { setHarfBuzzWasmUrl } from "@docx-editor.dev/core/layout";
import harfbuzzWasm from "@docx-editor.dev/core/harfbuzz.wasm?url";
import "@docx-editor.dev/core/styles/editor.css";
import type { Locale } from "../../i18n";
import { editorArabic } from "./editorArabic";
import { TableLayoutChrome } from "./TableLayoutChrome";
import { addBlankPage, addCover, addTextBox, type TextBoxShape } from "./pageObjects";
import { addBodyBackground, bodyDrawings, layoutNewDrawing } from "./pictureLayout";
import { reportFontConfiguration, type ExtraFont } from "./fonts";
import { fallbackPdf, pagesToPdf } from "./pdf";

// The text shaper is WebAssembly; point it at the copy Vite emits so dev and production both find it.
setHarfBuzzWasmUrl(harfbuzzWasm);

export type ReportDocHandle = {
  /** The document is loaded and painted, so `save()` returns it and not an empty one. */
  ready(): boolean;
  /** The document as .docx bytes. */
  save(): Promise<Uint8Array>;
  /** The painted pages as a PDF (one image per page). */
  pdf(onProgress?: (done: number, total: number) => void): Promise<Uint8Array>;
  /** Plain text of every page, for search and the report list. Pictures are omitted. */
  text(): string;
  /** Plain text of one page, 1-based. Pictures are omitted. */
  pageText(page: number): string;
  pageCount(): number;
  selectedText(): string;
  /** Replace the current selection (or insert at the caret) with plain text. */
  replaceSelection(text: string): boolean;
  /** Replace the whole document story with plain text. */
  replaceDocument(text: string): boolean;
  /** Put plain text at the start of a page. */
  insertOnPage(page: number, text: string): Promise<boolean>;
  /** Insert a picture as a full-page background or at the caret (see `ImagePlacement`). */
  insertImage(bytes: Uint8Array, placement: ImagePlacement): Promise<"ok" | "unsupported" | "refused">;
  /** A floating text box on the page. The editor drags it and resizes it from its handles. */
  insertTextBox(options: { kind: TextBoxShape; text: string }): Promise<boolean>;
  /** A new first page with the picture behind it, one text box per line and no header or footer. */
  insertCover(bytes: Uint8Array, lines: string[]): Promise<"ok" | "unsupported" | "refused">;
  focus(): void;
};

/**
 * `background`: the picture sits behind the text on every page except the cover. The cover
 * keeps its own header, so no page is chosen. `place`: the picture goes on its own centred
 * line after the paragraph the caret is in (before it when the caret is at its start), at
 * `widthPercent` of the page's text width, with the text above and below it.
 */
export type ImagePlacement =
  | { mode: "background" }
  | { mode: "place"; widthPercent: number };

const PAGE_TEXT_WIDTH_PT = 460;
const A4_WIDTH_PT = 595.3;
const A4_HEIGHT_PT = 841.9;

/** Crop (in percent per edge) that makes a picture cover a box without stretching. */
function coverCrop(imageWidth: number, imageHeight: number, boxWidth: number, boxHeight: number) {
  const image = imageWidth / Math.max(1, imageHeight);
  const box = boxWidth / Math.max(1, boxHeight);
  if (image > box) {
    const side = ((1 - box / image) / 2) * 100;
    return { left: side, right: side, top: 0, bottom: 0 };
  }
  const edge = ((1 - image / box) / 2) * 100;
  return { left: 0, right: 0, top: edge, bottom: edge };
}

function pagePlainText(page: HTMLElement) {
  const copy = page.cloneNode(true) as HTMLElement;
  copy.querySelectorAll("img, svg, canvas, [class*='drawing']").forEach((node) => node.remove());
  return copy.innerText.trim();
}

function paragraphHtml(text: string) {
  return text.split("\n").map((line) => {
    if (line === "") return "<p><br></p>";
    const dir = /[؀-ۿ]/.test(line) ? "rtl" : "ltr";
    return `<p dir="${dir}">${line.replace(/&/g, "&amp;").replace(/</g, "&lt;")}</p>`;
  }).join("");
}

type Props = {
  document: Uint8Array;
  title: string;
  locale: Locale;
  onTitleChange: (title: string) => void;
  onChange: () => void;
  onSave: () => void;
  titleBarStart?: () => ReactNode;
  titleBarEnd?: () => ReactNode;
  /** Uploaded faces. Changing this remounts the editor; font bytes are fixed for one mount. */
  extraFonts?: ExtraFont[];
};

function useDashboardTheme() {
  const read = () => (document.documentElement.dataset.theme === "dark" ? "dark" : "light");
  const [theme, setTheme] = useState<"light" | "dark">(read);
  useEffect(() => {
    const observer = new MutationObserver(() => setTheme(read()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
    return () => observer.disconnect();
  }, []);
  return theme;
}

export const ReportDocEditor = forwardRef<ReportDocHandle, Props>(function ReportDocEditor(
  { document: bytes, title, locale, onTitleChange, onChange, onSave, titleBarStart, titleBarEnd, extraFonts = [] },
  ref,
) {
  const editor = useRef<DocxEditorRef>(null);
  const root = useRef<HTMLDivElement>(null);
  const fonts = useFonts(useMemo(() => reportFontConfiguration(extraFonts), [extraFonts]));
  const fontKey = extraFonts.map((font) => font.family).join("|");
  const theme = useDashboardTheme();
  // The PDF is always a light page, even when the dashboard (and so the editor) is in dark mode.
  const [printLight, setPrintLight] = useState(false);
  const insertPageRef = useRef<() => Promise<boolean>>(async () => false);
  const renderPdf = useCallback(async (onProgress?: (done: number, total: number) => void) => {
    const host = root.current;
    if (!host) return fallbackPdf();
    setPrintLight(true);
    try {
      await document.fonts.ready.catch(() => undefined);
      const started = performance.now();
      while (
        (host.querySelector(".docx-page") == null || host.querySelector(".table-layout-chrome") != null)
        && performance.now() - started < 2500
      ) {
        await new Promise((resolve) => setTimeout(resolve, 50));
      }
      await new Promise<void>((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
      });
      await new Promise((resolve) => setTimeout(resolve, 180));
      return await pagesToPdf(host, editor.current?.snapshot().zoom ?? 1, onProgress);
    } catch {
      return fallbackPdf();
    } finally {
      setPrintLight(false);
    }
  }, []);
  const menu = useMemo(
    () => ({
      reportIssue: false,
      onSave,
      exporters: {
        pdf: async () => ({ bytes: await renderPdf() }),
      },
      children: (
        <DocxEditor.Menu.Insert>
          <DocxEditor.Menu.Row onSelect={() => { void insertPageRef.current(); }}>
            {locale === "ar" ? "صفحة جديدة" : "New page"}
          </DocxEditor.Menu.Row>
        </DocxEditor.Menu.Insert>
      ),
    }),
    [locale, onSave, renderPdf],
  );

  const placeCaret = useCallback(async (page: number) => {
    const current = editor.current?.getEditor();
    const host = root.current;
    if (!current || !host) return;
    const total = Math.max(1, current.getTotalPages());
    const targetPage = Math.min(Math.max(1, page), total);
    const caretOnPage = () => current.getCurrentPage("caret") === targetPage;
    const collapseAt = (blockId: string, offset: number) => {
      const caret = { paragraphId: blockId, offset };
      const moved = current.exec({ type: "setSelection", range: { anchor: caret, head: caret } });
      if (!moved.ok) current.exec({ type: "setSelection", anchor: { paraId: blockId } });
    };
    current.setActiveScope({ kind: "body" });
    current.focus();
    current.scrollToPage(targetPage);
    const frame = () => new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
    await frame();
    await frame();
    const pageNode = host.querySelector<HTMLElement>(`.docx-page[data-page-index="${targetPage - 1}"]`)
      ?? host.querySelectorAll<HTMLElement>(".docx-page")[targetPage - 1];
    const content = pageNode?.querySelector<HTMLElement>(".docx-page-content") ?? pageNode;
    const body = content ? pagePlainText(content).replace(/\s+/g, " ").trim() : "";
    if (body.length >= 3) {
      const start = Math.min(12, Math.max(0, body.length - 24));
      const needle = body.slice(start, start + 40).trim();
      if (needle.length >= 3) {
        for (const match of current.findMatches(needle)) {
          current.selectMatch(match);
          collapseAt(match.blockId, match.start);
          if (caretOnPage()) return;
        }
      }
    }
    if (content) {
      const rect = content.getBoundingClientRect();
      const x = Math.min(window.innerWidth - 8, Math.max(8, rect.left + rect.width / 2));
      const y = Math.min(window.innerHeight - 8, Math.max(8, rect.top + 72));
      const hit = document.elementFromPoint(x, y) ?? content;
      const point = { bubbles: true, cancelable: true, clientX: x, clientY: y, button: 0 };
      hit.dispatchEvent(new PointerEvent("pointerdown", { ...point, pointerId: 1, pointerType: "mouse" }));
      hit.dispatchEvent(new MouseEvent("mousedown", point));
      hit.dispatchEvent(new PointerEvent("pointerup", { ...point, pointerId: 1, pointerType: "mouse" }));
      hit.dispatchEvent(new MouseEvent("mouseup", point));
      if (caretOnPage()) return;
    }
    for (const item of current.getOutline()) {
      collapseAt(item.blockId, 0);
      if (caretOnPage()) return;
    }
    if (!content) return;
    const again = content.getBoundingClientRect();
    const clickX = Math.min(window.innerWidth - 8, Math.max(8, again.left + again.width / 2));
    const clickY = Math.min(window.innerHeight - 8, Math.max(8, again.top + 72));
    const target = document.elementFromPoint(clickX, clickY) ?? content;
    const click = { bubbles: true, cancelable: true, clientX: clickX, clientY: clickY, button: 0 };
    target.dispatchEvent(new PointerEvent("pointerdown", { ...click, pointerId: 1, pointerType: "mouse" }));
    target.dispatchEvent(new MouseEvent("mousedown", click));
  }, []);

  /** Load rewritten bytes, wait until they are painted, and return to the page the user was on. */
  const reload = useCallback(async (next: Uint8Array, page: number) => {
    const host = editor.current;
    if (!host) return;
    host.load(next);
    for (let tries = 0; tries < 100; tries += 1) {
      await new Promise((resolve) => setTimeout(resolve, 50));
      const snapshot = host.snapshot();
      if (!snapshot.isLoading && snapshot.isOpening !== true && root.current?.querySelector(".docx-page")) break;
    }
    host.getEditor()?.scrollToPage(page);
  }, []);

  /**
   * Save, rewrite the XML next to the caret's body paragraph, and reload. Without a caret in the
   * body (nothing clicked yet, or it sits in a header), the page on screen is used.
   */
  const rewriteAtCaret = useCallback(async (edit: (docx: Uint8Array, paraId: string) => Uint8Array | null) => {
    const host = editor.current;
    const current = host?.getEditor();
    if (!host || !current) return false;
    const caretParagraph = () => {
      const from = current.snapshot().selection?.from;
      return current.snapshot().scope.kind === "body" && from && "paraId" in from ? from.paraId : null;
    };
    if (!caretParagraph() || current.getSelectedImage()) await placeCaret(current.getCurrentPage("viewport"));
    const paraId = caretParagraph();
    const saved = paraId ? await host.save() : null;
    const next = paraId && saved ? edit(new Uint8Array(saved), paraId) : null;
    if (!next) return false;
    await reload(next, current.getCurrentPage("caret"));
    return true;
  }, [placeCaret, reload]);

  insertPageRef.current = async () => {
    const ok = await rewriteAtCaret((docx, paraId) => addBlankPage(docx, paraId));
    if (!ok) return false;
    const current = editor.current?.getEditor();
    const page = current?.getCurrentPage("caret") ?? 1;
    current?.scrollToPage(page + 1);
    return true;
  };

  useEffect(() => {
    const host = root.current;
    if (!host) return;
    const onPointerDown = (event: PointerEvent) => {
      const control = event.target instanceof Element
        ? event.target.closest("button, [role='menuitem']")
        : null;
      if (!control) return;
      const name = (control.getAttribute("aria-label") || control.textContent || "").replace(/\s+/g, " ").trim();
      if (/خصائص|التفاف|بديل|properties|wrap|alt/i.test(name)) return;
      if (!/(صورة|image|picture)/i.test(name)) return;
      placeCaret(editor.current?.getEditor()?.getCurrentPage("viewport") ?? 1);
    };
    const previousAlert = window.alert.bind(window);
    window.alert = (message?: unknown) => {
      if (message === "invalid-range") {
        previousAlert(locale === "ar"
          ? "تعذر إدراج الصورة في هذا الموضع. اختر الصفحة والحجم من اللوحة الجانبية."
          : "The image could not be inserted here. Choose the page and size in the side panel.");
        return;
      }
      previousAlert(message);
    };
    host.addEventListener("pointerdown", onPointerDown, true);
    return () => {
      host.removeEventListener("pointerdown", onPointerDown, true);
      window.alert = previousAlert;
    };
  }, [locale, placeCaret]);

  useImperativeHandle(ref, () => ({
    ready() {
      const snapshot = editor.current?.getEditor() ? editor.current.snapshot() : null;
      return snapshot !== null
        && !snapshot.isLoading
        && snapshot.isOpening !== true
        && snapshot.parseError === null
        && root.current?.querySelector(".docx-page") != null;
    },
    async save() {
      const buffer = await editor.current?.save();
      if (!buffer) throw new Error("editor not ready");
      return new Uint8Array(buffer);
    },
    pdf(onProgress) {
      return renderPdf(onProgress);
    },
    text() {
      return Array.from(root.current?.querySelectorAll<HTMLElement>(".docx-page") ?? [])
        .map(pagePlainText)
        .filter(Boolean)
        .join("\n\n");
    },
    pageText(page) {
      const node = root.current?.querySelectorAll<HTMLElement>(".docx-page")[page - 1];
      return node ? pagePlainText(node) : "";
    },
    pageCount() {
      return editor.current?.getEditor()?.getTotalPages()
        ?? root.current?.querySelectorAll(".docx-page").length
        ?? 1;
    },
    selectedText() {
      const current = editor.current?.getEditor();
      if (!current) return "";
      const value = current.query({ type: "selectedText" });
      return typeof value === "string" ? value : "";
    },
    replaceSelection(text) {
      const current = editor.current?.getEditor();
      if (!current) return false;
      current.focus();
      return current.exec({ type: "paste", text, html: paragraphHtml(text) }).ok;
    },
    replaceDocument(text) {
      const current = editor.current?.getEditor();
      if (!current) return false;
      current.focus();
      if (!current.exec({ type: "selectAll" }).ok) return false;
      return current.exec({ type: "paste", text, html: paragraphHtml(text) }).ok;
    },
    async insertOnPage(page, text) {
      await placeCaret(page);
      const current = editor.current?.getEditor();
      if (!current) return false;
      return current.exec({ type: "paste", text, html: paragraphHtml(text) }).ok;
    },
    async insertImage(bytes, placement) {
      const host = editor.current;
      const current = host?.getEditor();
      if (!host || !current) return "refused";
      const normalized = normalizeImageBytes(bytes);
      if (!normalized.ok) return "unsupported";
      const background = placement.mode === "background";
      const setup = current.getPageSetup();
      const pageWidth = setup ? setup.pageWidthTwips / 20 : A4_WIDTH_PT;
      const pageHeight = setup ? setup.pageHeightTwips / 20 : A4_HEIGHT_PT;
      const width = background
        ? pageWidth
        : PAGE_TEXT_WIDTH_PT * (Math.min(100, Math.max(15, placement.widthPercent)) / 100);
      const height = background
        ? pageHeight
        : Math.max(1, normalized.heightPoints * (width / Math.max(1, normalized.widthPoints)));
      const saved = await host.save();
      if (!saved) return "refused";
      if (background) {
        if (normalized.mime !== "image/png" && normalized.mime !== "image/jpeg" && normalized.mime !== "image/gif") return "unsupported";
        const next = addBodyBackground(new Uint8Array(saved), {
          bytes: normalized.bytes,
          mime: normalized.mime,
          crop: coverCrop(normalized.widthPoints, normalized.heightPoints, pageWidth, pageHeight),
          pageWidthPt: pageWidth,
          pageHeightPt: pageHeight,
        });
        if (!next) return "refused";
        await reload(next, current.getCurrentPage("viewport"));
        return "ok";
      }
      const before = bodyDrawings(new Uint8Array(saved));
      // A click on a picture selects it rather than placing the caret; use the page on screen then.
      if (current.getSelectedImage()) await placeCaret(current.getCurrentPage("viewport"));
      else current.focus();
      const command = {
        type: "insertImage" as const,
        data: normalized.bytes,
        mime: normalized.mime,
        widthPoints: width,
        heightPoints: height,
      };
      let result = await current.executeImageCommand(command);
      if (!result.ok) {
        // No usable caret (nothing clicked yet): fall back to the page on screen.
        await placeCaret(current.getCurrentPage("viewport"));
        result = await current.executeImageCommand(command);
      }
      if (!result.ok) return "refused";

      const page = current.getCurrentPage("caret");
      const inserted = await host.save();
      const laidOut = inserted
        ? layoutNewDrawing(before, new Uint8Array(inserted), { kind: "place" })
        : null;
      // If the XML could not be rewritten the picture stays inline where it was inserted.
      if (!laidOut) return "ok";
      await reload(laidOut, page);
      return "ok";
    },
    insertTextBox({ kind, text }) {
      return rewriteAtCaret((docx, paraId) => addTextBox(docx, paraId, {
        kind,
        text,
        widthPt: 240,
        heightPt: 96,
        xPt: 72,
        yPt: 160,
      }));
    },
    async insertCover(bytes, lines) {
      const host = editor.current;
      const current = host?.getEditor();
      if (!host || !current) return "refused";
      const normalized = normalizeImageBytes(bytes);
      if (!normalized.ok) return "unsupported";
      const mime = normalized.mime;
      if (mime !== "image/png" && mime !== "image/jpeg" && mime !== "image/gif") return "unsupported";
      const setup = current.getPageSetup();
      const pageWidth = setup ? setup.pageWidthTwips / 20 : A4_WIDTH_PT;
      const pageHeight = setup ? setup.pageHeightTwips / 20 : A4_HEIGHT_PT;
      const saved = await host.save();
      const next = saved
        ? addCover(new Uint8Array(saved), {
            image: { bytes: normalized.bytes, mime, crop: coverCrop(normalized.widthPoints, normalized.heightPoints, pageWidth, pageHeight) },
            pageWidthPt: pageWidth,
            pageHeightPt: pageHeight,
            lines,
          })
        : null;
      if (!next) return "refused";
      await reload(next, 1);
      return "ok";
    },
    focus() {
      editor.current?.focus();
    },
  }));

  return (
    // The editor positions right-to-left words itself and assumes a left-to-right host; inside an
    // RTL page the browser would reverse them again, so the host box stays dir="ltr".
    <div ref={root} className="report-doc" dir="ltr">
      <DocxEditor
        key={fontKey}
        ref={editor}
        document={bytes}
        fonts={fonts}
        title={title}
        onTitleChange={onTitleChange}
        onChange={(change) => {
          // Loading or refreshing a document is not an edit.
          if (!change.source) onChange();
        }}
        onSave={onSave}
        menu={menu}
        colorMode={printLight ? "light" : theme}
        locale={locale === "ar" ? "ar-SA" : "en-US"}
        i18n={locale === "ar" ? editorArabic : undefined}
        renderTitleBarLeft={titleBarStart}
        renderTitleBarRight={titleBarEnd}
      />
      {printLight ? null : <TableLayoutChrome editor={editor} host={root} locale={locale} />}
    </div>
  );
});
