import { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState, type ReactNode } from "react";
import { DocxEditor, useFonts, type DocxEditorRef } from "@docx-editor.dev/react";
import { setHarfBuzzWasmUrl } from "@docx-editor.dev/core/layout";
import harfbuzzWasm from "@docx-editor.dev/core/harfbuzz.wasm?url";
import "@docx-editor.dev/core/styles/editor.css";
import type { Locale } from "../../i18n";
import { editorArabic } from "./editorArabic";
import { reportFonts } from "./fonts";
import { pagesToPdf } from "./pdf";

// The text shaper is WebAssembly; point it at the copy Vite emits so dev and production both find it.
setHarfBuzzWasmUrl(harfbuzzWasm);

export type ReportDocHandle = {
  /** The document as .docx bytes. */
  save(): Promise<Uint8Array>;
  /** The painted pages as a PDF (one image per page). */
  pdf(onProgress?: (done: number, total: number) => void): Promise<Uint8Array>;
  /** Plain text of every page, for search and the report list. */
  text(): string;
  selectedText(): string;
  /** Replace the current selection (or insert at the caret) with plain text. */
  replaceSelection(text: string): boolean;
  focus(): void;
};

type Props = {
  document: Uint8Array;
  title: string;
  locale: Locale;
  onTitleChange: (title: string) => void;
  onChange: () => void;
  onSave: () => void;
  titleBarStart?: () => ReactNode;
  titleBarEnd?: () => ReactNode;
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
  { document: bytes, title, locale, onTitleChange, onChange, onSave, titleBarStart, titleBarEnd },
  ref,
) {
  const editor = useRef<DocxEditorRef>(null);
  const root = useRef<HTMLDivElement>(null);
  const fonts = useFonts(reportFonts);
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
        .map((page) => page.innerText.trim())
        .filter(Boolean)
        .join("\n\n");
    },
    selectedText() {
      const current = editor.current?.getEditor();
      if (!current) return "";
      const value = current.query({ type: "selectedText" });
      return typeof value === "string" ? value : "";
    },
    replaceSelection(text) {
      // The editor's command API cannot replace a text range yet, so hand the text to its own
      // paste handler: it replaces the selection (or inserts at the caret) like a real paste,
      // and each line becomes a paragraph.
      editor.current?.focus();
      const target = document.activeElement instanceof HTMLElement && root.current?.contains(document.activeElement)
        ? document.activeElement
        : root.current;
      if (!target) return false;
      const data = new DataTransfer();
      data.setData("text/plain", text);
      // As HTML too, so Arabic lines keep their right-to-left direction (and punctuation side).
      data.setData("text/html", text.split("\n").map((line) => {
        const dir = /[؀-ۿ]/.test(line) ? "rtl" : "ltr";
        return `<p dir="${dir}">${line.replace(/&/g, "&amp;").replace(/</g, "&lt;")}</p>`;
      }).join(""));
      const paste = new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true });
      target.dispatchEvent(paste);
      return paste.defaultPrevented;
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
