import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent, type RefObject } from "react";
import { createPortal } from "react-dom";
import type { Locale } from "../../i18n";
import { deleteTextBox, placeTextBox, setTextBoxText, textBoxText, type TextBoxFrame } from "./textBoxEdits";

type Corner = "nw" | "ne" | "sw" | "se";
type Rect = { left: number; top: number; width: number; height: number };
type Gesture = {
  mode: "move" | Corner;
  startX: number;
  startY: number;
  /** Box on its page, in painted page units. */
  frame: TextBoxFrame;
  pageWidth: number;
  pageHeight: number;
  /** Screen pixels per painted page unit (the editor zoom). */
  scale: number;
  moved: boolean;
};

const MIN_SIZE = 24;
const CORNERS: Corner[] = ["nw", "ne", "sw", "se"];

function copy(locale: Locale) {
  const ar = locale === "ar";
  return {
    edit: ar ? "تعديل النص" : "Edit text",
    remove: ar ? "حذف" : "Delete",
    save: ar ? "حفظ" : "Save",
    cancel: ar ? "إلغاء" : "Cancel",
    hint: ar ? "اسحب المربع لنقله، واسحب زاويته لتغيير حجمه. انقر مرتين للكتابة." : "Drag the box to move it, drag a corner to resize it. Double-click to type.",
  };
}

function boxAt(host: HTMLElement, x: number, y: number) {
  return document.elementsFromPoint(x, y)
    .map((node) => node.closest<HTMLElement>(".docx-drawing-textbox"))
    .find((node): node is HTMLElement => node !== null && host.contains(node)) ?? null;
}

function findBox(host: HTMLElement, id: string) {
  return host.querySelector<HTMLElement>(`.docx-drawing-textbox[data-drawing-node-id="${CSS.escape(id)}"]`);
}

/** The box and its page in the painted layout's own units, before the editor zoom. */
function measure(box: HTMLElement) {
  const page = box.closest<HTMLElement>(".docx-page");
  if (!page) return null;
  const pageWidth = parseFloat(page.style.width) || page.offsetWidth;
  const pageHeight = parseFloat(page.style.height) || page.offsetHeight;
  const scale = page.getBoundingClientRect().width / Math.max(1, pageWidth);
  const frame = {
    x: parseFloat(box.style.left) || 0,
    y: parseFloat(box.style.top) || 0,
    width: parseFloat(box.style.width) || box.offsetWidth,
    height: parseFloat(box.style.height) || box.offsetHeight,
  };
  return { frame, pageWidth, pageHeight, scale };
}

function sameRect(a: Rect | null, b: Rect | null) {
  return a === b || (!!a && !!b && a.left === b.left && a.top === b.top && a.width === b.width && a.height === b.height);
}

/** Where the box would sit for this gesture, kept on its page. */
function nextFrame(gesture: Gesture, x: number, y: number): TextBoxFrame {
  const dx = (x - gesture.startX) / gesture.scale;
  const dy = (y - gesture.startY) / gesture.scale;
  const { frame, pageWidth, pageHeight } = gesture;
  if (gesture.mode === "move") {
    return {
      ...frame,
      x: Math.min(Math.max(0, frame.x + dx), Math.max(0, pageWidth - frame.width)),
      y: Math.min(Math.max(0, frame.y + dy), Math.max(0, pageHeight - frame.height)),
    };
  }
  let left = frame.x;
  let top = frame.y;
  let right = frame.x + frame.width;
  let bottom = frame.y + frame.height;
  if (gesture.mode.includes("w")) left = Math.min(Math.max(0, left + dx), right - MIN_SIZE);
  if (gesture.mode.includes("e")) right = Math.max(Math.min(pageWidth, right + dx), left + MIN_SIZE);
  if (gesture.mode.includes("n")) top = Math.min(Math.max(0, top + dy), bottom - MIN_SIZE);
  if (gesture.mode.includes("s")) bottom = Math.max(Math.min(pageHeight, bottom + dy), top + MIN_SIZE);
  return { x: left, y: top, width: right - left, height: bottom - top };
}

/**
 * Select, move, resize, type in and delete Word text boxes. The packaged editor paints them
 * but refuses those actions, so each one rewrites the .docx and reloads it.
 */
export function TextBoxChrome({
  host,
  locale,
  save,
  apply,
}: {
  host: RefObject<HTMLDivElement | null>;
  locale: Locale;
  save: () => Promise<Uint8Array | null>;
  apply: (next: Uint8Array) => Promise<void>;
}) {
  const text = copy(locale);
  const [selected, setSelected] = useState<string | null>(null);
  const [rect, setRect] = useState<Rect | null>(null);
  const [preview, setPreview] = useState<Rect | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const gesture = useRef<Gesture | null>(null);
  const lastTap = useRef<{ id: string; at: number } | null>(null);
  const selectedRef = useRef<string | null>(null);
  const editingRef = useRef<string | null>(null);
  const busyRef = useRef(false);
  selectedRef.current = selected;
  editingRef.current = editing;
  busyRef.current = busy;

  const run = useCallback(async (edit: (docx: Uint8Array, id: string) => Uint8Array | null, keep: boolean) => {
    const id = selectedRef.current;
    if (!id || busyRef.current) return;
    setBusy(true);
    try {
      const saved = await save();
      const next = saved ? edit(saved, id) : null;
      if (!next) return;
      await apply(next);
      if (!keep) setSelected(null);
    } finally {
      setBusy(false);
    }
  }, [apply, save]);

  const openEditor = useCallback(async () => {
    const id = selectedRef.current;
    if (!id) return;
    const saved = await save();
    const current = saved ? textBoxText(saved, id) : null;
    if (current !== null) setEditing(current);
  }, [save]);

  // Follow the box while the page scrolls or reloads.
  useEffect(() => {
    if (!selected) {
      setRect(null);
      return;
    }
    let frame = 0;
    const tick = () => {
      const root = host.current;
      const box = root ? findBox(root, selected) : null;
      if (!box && !busyRef.current) {
        setSelected(null);
        return;
      }
      if (box) {
        const bounds = box.getBoundingClientRect();
        const next = { left: bounds.left, top: bounds.top, width: bounds.width, height: bounds.height };
        setRect((current) => (sameRect(current, next) ? current : next));
      }
      frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [host, selected]);

  const begin = useCallback((mode: Gesture["mode"], box: HTMLElement, x: number, y: number) => {
    const measured = measure(box);
    if (!measured) return;
    gesture.current = { mode, startX: x, startY: y, moved: false, ...measured };
  }, []);

  // Clicks on a box never reach the editor: it would select the box and then refuse every action.
  useEffect(() => {
    const onDown = (event: PointerEvent) => {
      const root = host.current;
      if (!root || event.button !== 0) return;
      const target = event.target;
      if (target instanceof Element && target.closest(".text-box-chrome")) return;
      const box = boxAt(root, event.clientX, event.clientY);
      if (!box) {
        if (selectedRef.current && !editingRef.current) setSelected(null);
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      const id = box.dataset.drawingNodeId ?? "";
      if (!id || editingRef.current) return;
      const now = Date.now();
      const doubleTap = lastTap.current?.id === id && now - lastTap.current.at < 450;
      lastTap.current = { id, at: now };
      setSelected(id);
      selectedRef.current = id;
      if (doubleTap) {
        void openEditor();
        return;
      }
      begin("move", box, event.clientX, event.clientY);
    };
    const swallow = (event: MouseEvent) => {
      const root = host.current;
      if (!root) return;
      const target = event.target;
      if (target instanceof Element && target.closest(".text-box-chrome")) return;
      if (boxAt(root, event.clientX, event.clientY)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    };
    const onMove = (event: PointerEvent) => {
      const current = gesture.current;
      if (!current) return;
      if (!current.moved && Math.hypot(event.clientX - current.startX, event.clientY - current.startY) < 3) return;
      current.moved = true;
      const page = rect;
      const next = nextFrame(current, event.clientX, event.clientY);
      if (!page) return;
      const originLeft = page.left - current.frame.x * current.scale;
      const originTop = page.top - current.frame.y * current.scale;
      setPreview({
        left: originLeft + next.x * current.scale,
        top: originTop + next.y * current.scale,
        width: next.width * current.scale,
        height: next.height * current.scale,
      });
    };
    const onUp = (event: PointerEvent) => {
      const current = gesture.current;
      gesture.current = null;
      setPreview(null);
      if (!current?.moved) return;
      const frame = nextFrame(current, event.clientX, event.clientY);
      void run((docx, id) => placeTextBox(docx, id, frame, current.pageWidth), true);
    };
    const onKey = (event: KeyboardEvent) => {
      if (!selectedRef.current || editingRef.current) return;
      if (event.key === "Delete" || event.key === "Backspace") {
        event.preventDefault();
        event.stopImmediatePropagation();
        void run(deleteTextBox, false);
      } else if (event.key === "Escape") {
        setSelected(null);
      } else if (event.key === "Enter") {
        event.preventDefault();
        event.stopImmediatePropagation();
        void openEditor();
      }
    };
    window.addEventListener("pointerdown", onDown, true);
    window.addEventListener("mousedown", swallow, true);
    window.addEventListener("click", swallow, true);
    window.addEventListener("dblclick", swallow, true);
    window.addEventListener("pointermove", onMove, true);
    window.addEventListener("pointerup", onUp, true);
    window.addEventListener("keydown", onKey, true);
    return () => {
      window.removeEventListener("pointerdown", onDown, true);
      window.removeEventListener("mousedown", swallow, true);
      window.removeEventListener("click", swallow, true);
      window.removeEventListener("dblclick", swallow, true);
      window.removeEventListener("pointermove", onMove, true);
      window.removeEventListener("pointerup", onUp, true);
      window.removeEventListener("keydown", onKey, true);
    };
  }, [begin, host, openEditor, rect, run]);

  const onCorner = (corner: Corner) => (event: ReactPointerEvent<HTMLSpanElement>) => {
    const root = host.current;
    const id = selectedRef.current;
    const box = root && id ? findBox(root, id) : null;
    if (!box || busy) return;
    event.preventDefault();
    event.stopPropagation();
    begin(corner, box, event.clientX, event.clientY);
  };

  // One commit per editing session: Save, Ctrl+Enter and the blur that follows all end it.
  const closed = useRef(false);
  useEffect(() => {
    if (editing !== null) closed.current = false;
  }, [editing]);

  const cancelText = () => {
    closed.current = true;
    setEditing(null);
  };

  const commitText = async (value: string) => {
    if (closed.current) return;
    closed.current = true;
    const unchanged = value === editing;
    setEditing(null);
    if (!unchanged) await run((docx, id) => setTextBoxText(docx, id, value), true);
  };

  if (!selected || !rect) return null;
  const shown = preview ?? rect;
  return createPortal(
    <div className="text-box-chrome" dir={locale === "ar" ? "rtl" : "ltr"}>
      <div
        className={`text-box-frame${busy ? " is-busy" : ""}`}
        style={{ left: shown.left, top: shown.top, width: shown.width, height: shown.height }}
        onPointerDown={(event) => {
          const root = host.current;
          const box = root ? findBox(root, selected) : null;
          if (!box || busy || editing !== null) return;
          event.preventDefault();
          const now = Date.now();
          if (lastTap.current?.id === selected && now - lastTap.current.at < 450) {
            lastTap.current = null;
            void openEditor();
            return;
          }
          lastTap.current = { id: selected, at: now };
          begin("move", box, event.clientX, event.clientY);
        }}
      >
        {editing === null ? CORNERS.map((corner) => (
          <span key={corner} className={`text-box-handle is-${corner}`} onPointerDown={onCorner(corner)} aria-hidden="true" />
        )) : (
          <textarea
            className="text-box-input"
            autoFocus
            dir="auto"
            defaultValue={editing}
            onKeyDown={(event) => {
              event.stopPropagation();
              if (event.key === "Escape") cancelText();
              if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) void commitText(event.currentTarget.value);
            }}
            onBlur={(event) => { void commitText(event.currentTarget.value); }}
          />
        )}
      </div>
      <div className="text-box-bar" style={{ left: rect.left, top: Math.max(4, rect.top - 40) }}>
        {editing === null ? (
          <>
            <button type="button" disabled={busy} onClick={() => void openEditor()}>{text.edit}</button>
            <button type="button" disabled={busy} onClick={() => void run(deleteTextBox, false)}>{text.remove}</button>
            <span className="text-box-hint">{text.hint}</span>
          </>
        ) : (
          <>
            <button type="button" onPointerDown={(event) => event.preventDefault()} onClick={() => {
              const input = document.querySelector<HTMLTextAreaElement>(".text-box-input");
              void commitText(input?.value ?? editing);
            }}>{text.save}</button>
            <button type="button" onPointerDown={(event) => event.preventDefault()} onClick={cancelText}>{text.cancel}</button>
          </>
        )}
      </div>
    </div>,
    document.body,
  );
}
