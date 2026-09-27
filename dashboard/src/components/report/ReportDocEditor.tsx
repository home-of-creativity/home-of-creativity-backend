import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState, type ReactNode } from "react";
import { DocxEditor, normalizeImageBytes, useFonts, type DocxEditorRef } from "@docx-editor.dev/react";
import { setHarfBuzzWasmUrl } from "@docx-editor.dev/core/layout";
import harfbuzzWasm from "@docx-editor.dev/core/harfbuzz.wasm?url";
import "@docx-editor.dev/core/styles/editor.css";
import type { Locale } from "../../i18n";
import { editorArabic } from "./editorArabic";
import { reportFontConfiguration, type ExtraFont } from "./fonts";
import { pagesToPdf } from "./pdf";

// The text shaper is WebAssembly; point it at the copy Vite emits so dev and production both find it.
setHarfBuzzWasmUrl(harfbuzzWasm);

export type ReportDocHandle = {
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
  /** Put plain text at the start of a page. */
  insertOnPage(page: number, text: string): boolean;
  /**
   * Insert a picture as its own block (text does not run through it) at the chosen width.
   * `widthPercent` is a share of the page's text width.
   */
  insertImage(bytes: Uint8Array, widthPercent: number, page: number): Promise<"ok" | "unsupported" | "refused">;
  focus(): void;
};

const PAGE_TEXT_WIDTH_PT = 460;
const EMU_PER_POINT = 12700;

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
  const renderPdf = useCallback(async (onProgress?: (done: number, total: number) => void) => {
    if (!root.current) throw new Error("editor not mounted");
    const host = root.current;
    setPrintLight(true);
    try {
      await new Promise((resolve) => setTimeout(resolve, 120));
      return await pagesToPdf(host, editor.current?.snapshot().zoom ?? 1, onProgress);
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
    }),
    [onSave, renderPdf],
  );

  const placeCaret = useCallback((page: number) => {
    const current = editor.current?.getEditor();
    const host = root.current;
    if (!current || !host) return;
    const total = Math.max(1, current.getTotalPages());
    const targetPage = Math.min(Math.max(1, page), total);
    current.setActiveScope({ kind: "body" });
    current.scrollToPage(targetPage);
    const outline = current.getOutline();
    const blockId = outline.find((item) => {
      current.scrollToBlock(item.blockId);
      return current.getCurrentPage("viewport") === targetPage;
    })?.blockId ?? outline[0]?.blockId;
    if (blockId) {
      const caret = { paragraphId: blockId, offset: 0 };
      const moved = current.exec({ type: "setSelection", range: { anchor: caret, head: caret } });
      if (moved.ok) return;
      current.exec({ type: "setSelection", anchor: { paraId: blockId } });
      return;
    }
    const pageNode = host.querySelector<HTMLElement>(`.docx-page[data-page-index="${targetPage - 1}"]`)
      ?? host.querySelectorAll<HTMLElement>(".docx-page")[targetPage - 1];
    const content = pageNode?.querySelector<HTMLElement>(".docx-page-content") ?? pageNode;
    if (!content) return;
    const rect = content.getBoundingClientRect();
    const x = rect.left + Math.min(48, rect.width / 3);
    const y = rect.top + Math.min(56, rect.height / 4);
    const hit = document.elementFromPoint(x, y) ?? content;
    hit.dispatchEvent(new PointerEvent("pointerdown", {
      bubbles: true,
      cancelable: true,
      clientX: x,
      clientY: y,
      pointerId: 1,
      pointerType: "mouse",
      button: 0,
    }));
  }, []);

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
    insertOnPage(page, text) {
      placeCaret(page);
      const current = editor.current?.getEditor();
      if (!current) return false;
      return current.exec({ type: "paste", text, html: paragraphHtml(text) }).ok;
    },
    async insertImage(bytes, widthPercent, page) {
      const current = editor.current?.getEditor();
      if (!current) return "refused";
      const normalized = normalizeImageBytes(bytes);
      if (!normalized.ok) return "unsupported";
      placeCaret(page);
      const percent = Math.min(100, Math.max(15, widthPercent));
      const width = PAGE_TEXT_WIDTH_PT * (percent / 100);
      const height = Math.max(1, normalized.heightPoints * (width / Math.max(1, normalized.widthPoints)));
      const command = {
        type: "insertImage" as const,
        data: normalized.bytes,
        mime: normalized.mime,
        widthPoints: width,
        heightPoints: height,
      };
      let result = await current.executeImageCommand(command);
      if (!result.ok) {
        placeCaret(page);
        result = await current.executeImageCommand(command);
      }
      if (!result.ok) return "refused";
      current.exec({ type: "setImageWrapType", target: "topAndBottom" });
      current.exec({
        type: "setImageProperties",
        widthEmu: Math.round(width * EMU_PER_POINT),
        heightEmu: Math.round(height * EMU_PER_POINT),
        wrap: "topAndBottom",
      });
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
    </div>
  );
});
