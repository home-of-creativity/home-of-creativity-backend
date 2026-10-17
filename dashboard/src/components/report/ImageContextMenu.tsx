import { useEffect, useState, type RefObject } from "react";
import type { DocxEditorRef } from "@docx-editor.dev/react";
import { copy, type Locale } from "../../i18n";

type Menu = { x: number; y: number; drawingId: string };

/**
 * Right-click on a picture. The editor's own menu stays on the previous caret, so a
 * click on the picture selects it first and this menu rotates, wraps, or deletes it.
 */
export function ImageContextMenu({
  editor,
  host,
  locale,
  onChange,
}: {
  editor: RefObject<DocxEditorRef | null>;
  host: RefObject<HTMLDivElement | null>;
  locale: Locale;
  onChange: () => void;
}) {
  const text = locale === "ar" ? ar : en;
  const [menu, setMenu] = useState<Menu | null>(null);

  useEffect(() => {
    const root = host.current;
    if (!root) return;
    const onContext = (event: MouseEvent) => {
      const target = event.target instanceof Element ? event.target : null;
      const frame = target?.closest<HTMLElement>(".docx-drawing-image, .docx-drawing-image-frame, .docx-drawing-transform-stage");
      if (!frame || frame.closest(".docx-drawing-textbox-box")) return;
      event.preventDefault();
      event.stopPropagation();
      const image = frame.querySelector("img") ?? frame;
      const box = image.getBoundingClientRect();
      const point = {
        bubbles: true,
        cancelable: true,
        clientX: box.left + box.width / 2,
        clientY: box.top + box.height / 2,
        button: 0,
      };
      image.dispatchEvent(new PointerEvent("pointerdown", { ...point, pointerId: 1, pointerType: "mouse" }));
      image.dispatchEvent(new MouseEvent("mousedown", point));
      image.dispatchEvent(new PointerEvent("pointerup", { ...point, pointerId: 1, pointerType: "mouse" }));
      image.dispatchEvent(new MouseEvent("mouseup", point));
      const drawingId = editor.current?.getEditor()?.getSelectedImage()?.id ?? "";
      const width = 220;
      const height = 240;
      setMenu({
        x: Math.min(event.clientX, window.innerWidth - width - 8),
        y: Math.min(event.clientY, window.innerHeight - height - 8),
        drawingId,
      });
    };
    const close = () => setMenu(null);
    root.addEventListener("contextmenu", onContext, true);
    window.addEventListener("resize", close);
    return () => {
      root.removeEventListener("contextmenu", onContext, true);
      window.removeEventListener("resize", close);
    };
  }, [editor, host]);

  if (!menu) return null;

  function run(command: Parameters<DocxEditorRef["exec"]>[0]) {
    const current = editor.current?.getEditor();
    if (!current) return;
    let ok = false;
    try {
      ok = current.can(command).ok && current.exec(command).ok;
    } catch {
      ok = false;
    }
    if (!ok) return;
    onChange();
    current.focus();
    setMenu(null);
  }

  const drawingId = editor.current?.getEditor()?.getSelectedImage()?.id || menu.drawingId;
  const id = drawingId ? { drawingNodeId: drawingId } : {};

  return (
    <div className="report-image-menu" dir={locale === "ar" ? "rtl" : "ltr"} style={{ left: menu.x, top: menu.y }} role="menu">
      <button type="button" role="menuitem" onClick={() => run({ type: "transformImage", action: "rotateCW", ...id })}>{text.rotateCw}</button>
      <button type="button" role="menuitem" onClick={() => run({ type: "transformImage", action: "rotateCCW", ...id })}>{text.rotateCcw}</button>
      <button type="button" role="menuitem" onClick={() => run({ type: "setImageWrapType", target: "square", ...id })}>{text.wrap}</button>
      <button type="button" role="menuitem" onClick={() => run({ type: "setImageWrapType", target: "inline", ...id })}>{text.inline}</button>
      <button type="button" role="menuitem" onClick={() => run({ type: "deleteImage", ...id })}>{text.remove}</button>
      <button type="button" className="report-image-menu-close" onClick={() => { setMenu(null); editor.current?.focus(); }}>{text.close}</button>
    </div>
  );
}

const ar = {
  rotateCw: copy.reportImageRotateCw.ar,
  rotateCcw: copy.reportImageRotateCcw.ar,
  wrap: copy.reportImageWrapText.ar,
  inline: copy.reportImageInline.ar,
  remove: copy.reportImageDelete.ar,
  close: copy.reportReplaceClose.ar,
};

const en = {
  rotateCw: copy.reportImageRotateCw.en,
  rotateCcw: copy.reportImageRotateCcw.en,
  wrap: copy.reportImageWrapText.en,
  inline: copy.reportImageInline.en,
  remove: copy.reportImageDelete.en,
  close: copy.reportReplaceClose.en,
};
