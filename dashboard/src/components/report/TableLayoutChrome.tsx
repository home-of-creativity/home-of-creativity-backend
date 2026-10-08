import { useEffect, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from "react";
import { createPortal } from "react-dom";
import type { DocxEditorRef } from "@docx-editor.dev/react";
import type { Locale } from "../../i18n";

type Command = Parameters<DocxEditorRef["exec"]>[0];
type ExtraCommand = { type: "distributeColumns" } | { type: "autoFitContents" } | { type: "openTableProperties" };
type BorderScope = "all" | "outside" | "inside" | "top" | "bottom" | "left" | "right";
type BorderStyle = "single" | "dashed" | "dotted" | "double" | "triple" | "thick";
type TableInfo = {
  rows: number;
  columns: number;
  rowIndex: number;
  columnIndex: number;
};

const FILLS = ["2E0E5C", "E7993A", "F4E4C8", "FFFFFF", "1A1224", "E7E0F2"];
const WIDTHS: { eighths: number; label: string }[] = [
  { eighths: 4, label: "0.5" },
  { eighths: 8, label: "1" },
  { eighths: 12, label: "1.5" },
  { eighths: 18, label: "2.25" },
  { eighths: 24, label: "3" },
];

/**
 * Word-style table handle and the contextual tab beside Review.
 * The packaged editor has the commands, but no corner selector and no layout tab.
 */
export function TableLayoutChrome({
  editor,
  host,
  locale,
}: {
  editor: RefObject<DocxEditorRef | null>;
  host: RefObject<HTMLDivElement | null>;
  locale: Locale;
}) {
  const text = copy(locale);
  const [hoverTable, setHoverTable] = useState<HTMLElement | null>(null);
  const [caretTable, setCaretTable] = useState<HTMLElement | null>(null);
  const [activeTable, setActiveTable] = useState<HTMLElement | null>(null);
  const [tableInfo, setTableInfo] = useState<TableInfo | null>(null);
  const [tabOn, setTabOn] = useState(false);
  const [ribbonOn, setRibbonOn] = useState(false);
  const [note, setNote] = useState("");
  const [borderStyle, setBorderStyle] = useState<BorderStyle>("single");
  const [borderSize, setBorderSize] = useState(8);
  const [borderColor, setBorderColor] = useState("1A1224");
  const [fill, setFill] = useState("2E0E5C");
  const [tabBox, setTabBox] = useState<{ top: number; left: number; height: number } | null>(null);
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  const [handleBox, setHandleBox] = useState<{ top: number; left: number } | null>(null);
  const tabRef = useRef<HTMLButtonElement>(null);
  const shifted = useRef<HTMLElement | null>(null);
  const opening = useRef(false);

  const shown = live(hoverTable, host.current) ?? live(caretTable, host.current) ?? (tabOn ? live(activeTable, host.current) : null);

  useEffect(() => {
    const root = host.current;
    if (!root) return;
    const onMove = (event: PointerEvent) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest(".table-layout-chrome")) return;
      const fragment = target.closest<HTMLElement>(".docx-table-fragment");
      setHoverTable((current) => {
        const next = fragment && root.contains(fragment) ? fragment : null;
        return current === next ? current : next;
      });
    };
    root.addEventListener("pointermove", onMove);
    return () => root.removeEventListener("pointermove", onMove);
  }, [host]);

  useEffect(() => {
    let unsub: (() => void) | undefined;
    let engine: ReturnType<NonNullable<DocxEditorRef["getEditor"]>> | null = null;
    let dead = false;
    const sync = () => {
      if (dead) return;
      const next = editor.current?.getEditor() ?? null;
      if (next === engine) return;
      unsub?.();
      unsub = undefined;
      engine = next;
      if (!engine) return;
      unsub = engine.on("selectionChange", (snapshot) => {
        const table = snapshot.table;
        setTableInfo(table);
        if (!table) {
          setCaretTable(null);
          if (!opening.current) {
            setTabOn(false);
            setRibbonOn(false);
          }
          return;
        }
        const node = document.getSelection()?.anchorNode ?? null;
        const el = node instanceof Element ? node : node?.parentElement;
        const fragment = el?.closest<HTMLElement>(".docx-table-fragment") ?? null;
        setCaretTable(fragment && host.current?.contains(fragment) ? fragment : null);
      });
    };
    sync();
    const timer = window.setInterval(sync, 400);
    return () => {
      dead = true;
      window.clearInterval(timer);
      unsub?.();
    };
  }, [editor, host]);

  useEffect(() => {
    const root = host.current;
    if (!root) return;
    const onClick = (event: MouseEvent) => {
      const target = event.target;
      if (!(target instanceof Element) || target.closest(".table-layout-chrome") || target.closest(".table-layout-slot")) return;
      if (target.closest("[data-menu]")) setRibbonOn(false);
    };
    root.addEventListener("click", onClick);
    return () => root.removeEventListener("click", onClick);
  }, [host]);

  useEffect(() => () => {
    const root = host.current;
    if (!root) return;
    restoreToolbar(root);
  }, [host]);

  useLayoutEffect(() => {
    const root = host.current;
    if (!root) return;
    let frame = 0;
    const place = () => {
      const fragment = live(shown, root);
      if (!fragment) setHandleBox(null);
      else {
        const box = fragment.getBoundingClientRect();
        const view = scrollParent(fragment).getBoundingClientRect();
        const hostBox = root.getBoundingClientRect();
        const visible = box.bottom > view.top + 4 && box.top < view.bottom - 4;
        setHandleBox(visible
          ? { top: box.top - hostBox.top + 2, left: box.left - hostBox.left - 18 }
          : null);
      }

      holdToolbar(root, ribbonOn, setSlot);

      if (!tabOn) {
        clearGap(root, shifted);
        setTabBox(null);
        return;
      }

      const menu = root.querySelector<HTMLElement>('[data-menu="review"]');
      const bar = root.querySelector<HTMLElement>('[data-testid="docx-menubar"]');
      if (!menu || !bar) return;
      const hostBox = root.getBoundingClientRect();
      const menuBox = menu.getBoundingClientRect();
      const next = menu.nextElementSibling instanceof HTMLElement ? menu.nextElementSibling : null;
      const tabWidth = tabRef.current?.offsetWidth ?? 132;
      if (next && !next.classList.contains("table-layout-tab")) {
        shifted.current = next;
        next.style.marginLeft = `${tabWidth + 8}px`;
      }
      setTabBox({
        top: menuBox.top - hostBox.top,
        left: menuBox.right - hostBox.left + 4,
        height: menuBox.height,
      });
      bar.style.marginBottom = "";
    };
    place();
    frame = window.requestAnimationFrame(place);
    const onScroll = () => place();
    root.addEventListener("scroll", onScroll, true);
    window.addEventListener("resize", onScroll);
    return () => {
      window.cancelAnimationFrame(frame);
      root.removeEventListener("scroll", onScroll, true);
      window.removeEventListener("resize", onScroll);
      clearGap(root, shifted);
    };
  }, [host, shown, tabOn, ribbonOn, locale, tableInfo]);

  function engine() {
    return editor.current?.getEditor() ?? null;
  }

  function emit(command: Command | ExtraCommand) {
    const current = engine();
    if (!current) return false;
    const result = current.exec(command as Command);
    setNote(result.ok ? "" : text.refused);
    return result.ok;
  }

  function possible(command: Command | ExtraCommand) {
    const current = engine();
    if (!current) return false;
    try {
      return current.can(command as Command).ok;
    } catch {
      return false;
    }
  }

  function openFrom(fragment: HTMLElement | null) {
    const current = engine();
    const root = host.current;
    const target = live(fragment, root);
    if (!current || !target) return;
    const id = target.querySelector<HTMLElement>("[data-paragraph-id]")?.dataset.paragraphId;
    opening.current = true;
    current.focus();
    if (id) {
      const caret = { paragraphId: id, offset: 0 };
      const moved = current.exec({ type: "setSelection", range: { anchor: caret, head: caret } });
      if (!moved.ok) current.exec({ type: "setSelection", anchor: { paraId: id } });
    }
    const selected = current.exec({ type: "selectTableRegion", region: "table" });
    const inside = selected.ok || current.snapshot().table != null;
    if (!inside) {
      opening.current = false;
      setNote(text.refused);
      return;
    }
    setActiveTable(target);
    setTabOn(true);
    setRibbonOn(true);
    setNote("");
    window.setTimeout(() => {
      opening.current = false;
    }, 0);
  }

  function borders(scope: BorderScope) {
    emit({
      type: "setTableBorders",
      scope,
      spec: {
        style: borderStyle,
        size: borderSize,
        color: { kind: "hex", value: borderColor },
      },
    });
  }

  function paint(color: string | null) {
    setFill(color ?? "");
    emit({ type: "setCellFill", color: color ? { kind: "hex", value: color } : null });
  }

  function removeTable() {
    if (!window.confirm(text.deleteAsk)) return;
    if (emit({ type: "deleteTable" })) {
      setTabOn(false);
      setRibbonOn(false);
    }
  }

  const where = tableInfo
    ? text.where(tableInfo.rowIndex + 1, tableInfo.rows, tableInfo.columnIndex + 1, tableInfo.columns)
    : "";
  const distribute = possible({ type: "distributeColumns" });
  const autofit = possible({ type: "autoFitContents" });
  const properties = possible({ type: "openTableProperties" });

  return (
    <div className="table-layout-chrome">
      {handleBox ? (
        <button
          type="button"
          className="table-select-handle"
          style={{ top: handleBox.top, left: handleBox.left }}
          aria-label={text.selectTable}
          title={text.selectTable}
          onMouseDown={(event) => {
            event.preventDefault();
            event.stopPropagation();
            openFrom(shown);
          }}
        >
          <MoveIcon />
        </button>
      ) : null}

      {tabOn && tabBox ? (
        <button
          ref={tabRef}
          type="button"
          className={ribbonOn ? "table-layout-tab is-open" : "table-layout-tab"}
          style={{ top: tabBox.top, left: tabBox.left, height: tabBox.height }}
          aria-pressed={ribbonOn}
          onMouseDown={(event) => event.preventDefault()}
          onClick={() => setRibbonOn((open) => !open)}
        >
          {text.tab}
        </button>
      ) : null}

      {slot && ribbonOn ? createPortal(
        <div
          className="table-layout-ribbon"
          dir={locale === "ar" ? "rtl" : "ltr"}
          onMouseDown={(event) => {
            if ((event.target as HTMLElement).closest("input, select")) return;
            event.preventDefault();
          }}
        >
          <div className="table-layout-groups">
            <Group title={text.select}>
              <Btn label={text.table} disabled={!tableInfo} onClick={() => emit({ type: "selectTableRegion", region: "table" })}><GridIcon /></Btn>
              <Btn label={text.row} disabled={!tableInfo} onClick={() => emit({ type: "selectTableRegion", region: "row" })}><RowsIcon /></Btn>
              <Btn label={text.column} disabled={!tableInfo} onClick={() => emit({ type: "selectTableRegion", region: "column" })}><ColsIcon /></Btn>
            </Group>

            <Group title={text.rowsCols}>
              <Btn label={text.above} disabled={!possible({ type: "insertRow", where: "above" })} onClick={() => emit({ type: "insertRow", where: "above" })}><span aria-hidden="true">↑</span></Btn>
              <Btn label={text.below} disabled={!possible({ type: "insertRow", where: "below" })} onClick={() => emit({ type: "insertRow", where: "below" })}><span aria-hidden="true">↓</span></Btn>
              <Btn label={text.toRight} disabled={!possible({ type: "insertColumn", where: "right" })} onClick={() => emit({ type: "insertColumn", where: "right" })}><span aria-hidden="true">→</span></Btn>
              <Btn label={text.toLeft} disabled={!possible({ type: "insertColumn", where: "left" })} onClick={() => emit({ type: "insertColumn", where: "left" })}><span aria-hidden="true">←</span></Btn>
              <Btn label={text.deleteRow} disabled={!possible({ type: "deleteRow" })} onClick={() => emit({ type: "deleteRow" })}><span aria-hidden="true">−</span></Btn>
              <Btn label={text.deleteCol} disabled={!possible({ type: "deleteColumn" })} onClick={() => emit({ type: "deleteColumn" })}><span aria-hidden="true">|</span></Btn>
              <Btn label={text.deleteTable} danger disabled={!possible({ type: "deleteTable" })} onClick={removeTable}><span aria-hidden="true">×</span></Btn>
            </Group>

            <Group title={text.merge}>
              <Btn label={text.mergeCells} disabled={!possible({ type: "mergeCells" })} onClick={() => emit({ type: "mergeCells" })}><span aria-hidden="true">⊞</span></Btn>
              <Btn label={text.splitRows} disabled={!possible({ type: "splitCell", rows: 2, cols: 1 })} onClick={() => emit({ type: "splitCell", rows: 2, cols: 1 })}><span aria-hidden="true">═</span></Btn>
              <Btn label={text.splitCols} disabled={!possible({ type: "splitCell", rows: 1, cols: 2 })} onClick={() => emit({ type: "splitCell", rows: 1, cols: 2 })}><span aria-hidden="true">║</span></Btn>
            </Group>

            <Group title={text.borders}>
              <Btn label={text.all} onClick={() => borders("all")}><span aria-hidden="true">▦</span></Btn>
              <Btn label={text.outside} onClick={() => borders("outside")}><span aria-hidden="true">□</span></Btn>
              <Btn label={text.inside} onClick={() => borders("inside")}><span aria-hidden="true">┼</span></Btn>
              <Btn label={text.none} onClick={() => emit({ type: "setTableBorders", scope: "none", target: "all" })}><span aria-hidden="true">⌀</span></Btn>
              <div className="table-layout-pen">
                <select aria-label={text.style} value={borderStyle} onChange={(event) => setBorderStyle(event.target.value as BorderStyle)}>
                  <option value="single">{text.styles.single}</option>
                  <option value="dashed">{text.styles.dashed}</option>
                  <option value="dotted">{text.styles.dotted}</option>
                  <option value="double">{text.styles.double}</option>
                  <option value="triple">{text.styles.triple}</option>
                  <option value="thick">{text.styles.thick}</option>
                </select>
                <select aria-label={text.weight} value={borderSize} onChange={(event) => setBorderSize(Number(event.target.value))}>
                  {WIDTHS.map((item) => <option key={item.eighths} value={item.eighths}>{item.label}</option>)}
                </select>
                <label className="table-layout-color">
                  {text.color}
                  <input
                    type="color"
                    aria-label={text.color}
                    value={`#${borderColor}`}
                    onChange={(event) => setBorderColor(event.target.value.slice(1).toUpperCase())}
                  />
                </label>
                <span className="table-layout-edges">
                  <Edge label={text.edgeTop} onClick={() => borders("top")} />
                  <Edge label={text.edgeBottom} onClick={() => borders("bottom")} />
                  <Edge label={text.edgeRight} onClick={() => borders("right")} />
                  <Edge label={text.edgeLeft} onClick={() => borders("left")} />
                </span>
              </div>
            </Group>

            <Group title={text.shading}>
              {FILLS.map((color) => (
                <button
                  key={color}
                  type="button"
                  className={fill === color ? "table-layout-swatch is-on" : "table-layout-swatch"}
                  style={{ background: `#${color}` }}
                  aria-label={text.fill}
                  title={`#${color}`}
                  onClick={() => paint(color)}
                />
              ))}
              <label className="table-layout-color">
                {text.custom}
                <input
                  type="color"
                  aria-label={text.custom}
                  value={`#${fill || "FFFFFF"}`}
                  onChange={(event) => paint(event.target.value.slice(1).toUpperCase())}
                />
              </label>
              <Btn label={text.clearFill} onClick={() => paint(null)}><span aria-hidden="true">⌫</span></Btn>
            </Group>

            <Group title={text.alignCell}>
              <Btn label={text.top} disabled={!tableInfo} onClick={() => emit({ type: "setTableCellVerticalAlignment", alignment: "top" })}><span aria-hidden="true">⤒</span></Btn>
              <Btn label={text.middle} disabled={!tableInfo} onClick={() => emit({ type: "setTableCellVerticalAlignment", alignment: "center" })}><span aria-hidden="true">↕</span></Btn>
              <Btn label={text.bottom} disabled={!tableInfo} onClick={() => emit({ type: "setTableCellVerticalAlignment", alignment: "bottom" })}><span aria-hidden="true">⤓</span></Btn>
            </Group>

            <Group title={text.alignTable}>
              <Btn label={text.right} disabled={!tableInfo} onClick={() => emit({ type: "setTableProperties", justification: "right" })}><span aria-hidden="true">⇥</span></Btn>
              <Btn label={text.center} disabled={!tableInfo} onClick={() => emit({ type: "setTableProperties", justification: "center" })}><span aria-hidden="true">↔</span></Btn>
              <Btn label={text.left} disabled={!tableInfo} onClick={() => emit({ type: "setTableProperties", justification: "left" })}><span aria-hidden="true">⇤</span></Btn>
            </Group>

            <Group title={text.size}>
              <Btn label={text.auto} onClick={() => emit({ type: "setTableProperties", width: null, widthType: "auto" })}><span aria-hidden="true">A</span></Btn>
              <Btn label="50%" onClick={() => emit({ type: "setTableProperties", width: 2500, widthType: "pct" })}><span aria-hidden="true">½</span></Btn>
              <Btn label="75%" onClick={() => emit({ type: "setTableProperties", width: 3750, widthType: "pct" })}><span aria-hidden="true">¾</span></Btn>
              <Btn label={text.full} onClick={() => emit({ type: "setTableProperties", width: 5000, widthType: "pct" })}><span aria-hidden="true">↔</span></Btn>
              <Btn label={text.header} disabled={!tableInfo} onClick={() => emit({ type: "toggleHeaderRow" })}><span aria-hidden="true">H</span></Btn>
              {distribute ? <Btn label={text.distribute} onClick={() => emit({ type: "distributeColumns" })}><span aria-hidden="true">||</span></Btn> : null}
              {autofit ? <Btn label={text.autofit} onClick={() => emit({ type: "autoFitContents" })}><span aria-hidden="true">{"<>"}</span></Btn> : null}
              {properties ? <Btn label={text.properties} onClick={() => emit({ type: "openTableProperties" })}><span aria-hidden="true">⚙</span></Btn> : null}
            </Group>
          </div>
          <p className={note ? "table-layout-note is-warn" : "table-layout-note"}>{note || where}</p>
        </div>,
        slot,
      ) : null}
    </div>
  );
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="table-layout-group">
      <div className="table-layout-row">{children}</div>
      <p className="table-layout-group-name">{title}</p>
    </section>
  );
}

function Btn({
  label,
  onClick,
  disabled,
  danger,
  children,
}: {
  label: string;
  onClick: () => void;
  disabled?: boolean;
  danger?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      className={danger ? "table-layout-btn is-danger" : "table-layout-btn"}
      disabled={disabled}
      title={label}
      onClick={onClick}
    >
      <span className="table-layout-btn-icon">{children}</span>
      <span>{label}</span>
    </button>
  );
}

function Edge({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" className="table-layout-edge" title={label} aria-label={label} onClick={onClick}>
      {label}
    </button>
  );
}

function live(node: HTMLElement | null, root: HTMLElement | null) {
  if (!node) return null;
  if (node.isConnected) return node;
  const id = node.dataset.tableId;
  const index = node.dataset.fragmentIndex ?? "0";
  if (!root || !id) return null;
  return root.querySelector<HTMLElement>(
    `.docx-table-fragment[data-table-id="${CSS.escape(id)}"][data-fragment-index="${CSS.escape(index)}"]`,
  );
}

function scrollParent(node: HTMLElement) {
  let parent = node.parentElement;
  while (parent) {
    const style = getComputedStyle(parent);
    if (/(auto|scroll)/.test(style.overflowY)) return parent;
    parent = parent.parentElement;
  }
  return document.documentElement;
}

function holdToolbar(
  root: HTMLElement,
  ribbonOn: boolean,
  setSlot: (slot: HTMLElement | null) => void,
) {
  const toolbar = root.querySelector<HTMLElement>(".docx-toolbar");
  if (!ribbonOn || !toolbar?.parentElement) {
    restoreToolbar(root);
    setSlot(null);
    return;
  }
  toolbar.style.marginTop = "";
  let slot = root.querySelector<HTMLElement>(".table-layout-slot");
  if (!slot?.isConnected) {
    slot = document.createElement("div");
    slot.className = "table-layout-slot";
    toolbar.parentElement.insertBefore(slot, toolbar);
  }
  toolbar.style.display = "none";
  setSlot(slot);
}

function restoreToolbar(root: HTMLElement) {
  const toolbar = root.querySelector<HTMLElement>(".docx-toolbar");
  if (toolbar) {
    toolbar.hidden = false;
    toolbar.style.display = "";
    toolbar.style.marginTop = "";
  }
  root.querySelector(".table-layout-slot")?.remove();
}

function clearGap(root: HTMLElement, shifted: { current: HTMLElement | null }) {
  if (shifted.current) shifted.current.style.marginLeft = "";
  shifted.current = null;
  const bar = root.querySelector<HTMLElement>('[data-testid="docx-menubar"]');
  if (bar) bar.style.marginBottom = "";
  const toolbar = root.querySelector<HTMLElement>(".docx-toolbar");
  if (toolbar) toolbar.style.marginTop = "";
}

function MoveIcon() {
  return (
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
      <path d="M8 1.2v13.6M1.2 8h13.6" stroke="currentColor" strokeWidth="1.4" />
      <path d="M8 1.2 5.7 3.5M8 1.2l2.3 2.3M8 14.8 5.7 12.5M8 14.8l2.3-2.3M1.2 8l2.3-2.3M1.2 8l2.3 2.3M14.8 8l-2.3-2.3M14.8 8l-2.3 2.3" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
    </svg>
  );
}

function GridIcon() {
  return (
    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
      <rect x="2" y="2" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="1.3" />
      <path d="M8 2v12M2 8h12" stroke="currentColor" strokeWidth="1.3" />
    </svg>
  );
}

function RowsIcon() {
  return (
    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
      <rect x="2" y="3" width="12" height="10" fill="none" stroke="currentColor" strokeWidth="1.3" />
      <path d="M2 8h12" stroke="currentColor" strokeWidth="1.3" />
    </svg>
  );
}

function ColsIcon() {
  return (
    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
      <rect x="2" y="2" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="1.3" />
      <path d="M8 2v12" stroke="currentColor" strokeWidth="1.3" />
    </svg>
  );
}

function copy(locale: Locale) {
  if (locale === "en") {
    return {
      tab: "Table Layout",
      selectTable: "Select table",
      select: "Select",
      table: "Table",
      row: "Row",
      column: "Column",
      rowsCols: "Rows & columns",
      above: "Insert above",
      below: "Insert below",
      toRight: "Insert right",
      toLeft: "Insert left",
      deleteRow: "Delete row",
      deleteCol: "Delete column",
      deleteTable: "Delete table",
      deleteAsk: "Delete this table and everything in it?",
      merge: "Merge",
      mergeCells: "Merge cells",
      splitRows: "Split rows",
      splitCols: "Split columns",
      borders: "Borders",
      all: "All",
      outside: "Outside",
      inside: "Inside",
      none: "None",
      style: "Line style",
      weight: "Line weight",
      color: "Color",
      edgeTop: "Top",
      edgeBottom: "Bottom",
      edgeRight: "Right",
      edgeLeft: "Left",
      styles: { single: "Single", dashed: "Dashed", dotted: "Dotted", double: "Double", triple: "Triple", thick: "Thick" },
      shading: "Shading",
      fill: "Cell fill",
      custom: "Custom",
      clearFill: "No fill",
      alignCell: "Cell align",
      top: "Top",
      middle: "Middle",
      bottom: "Bottom",
      alignTable: "Table align",
      right: "Right",
      center: "Center",
      left: "Left",
      size: "Size",
      auto: "Auto",
      full: "Page width",
      header: "Header row",
      distribute: "Distribute",
      autofit: "Autofit",
      properties: "Properties",
      refused: "That action does not apply to the current selection.",
      where: (row: number, rows: number, column: number, columns: number) =>
        `Row ${row} of ${rows}  ·  Column ${column} of ${columns}`,
    };
  }
  return {
    tab: "تخطيط الجدول",
    selectTable: "تحديد الجدول",
    select: "تحديد",
    table: "الجدول",
    row: "الصف",
    column: "العمود",
    rowsCols: "صفوف وأعمدة",
    above: "إدراج لأعلى",
    below: "إدراج لأسفل",
    toRight: "إدراج لليمين",
    toLeft: "إدراج لليسار",
    deleteRow: "حذف الصف",
    deleteCol: "حذف العمود",
    deleteTable: "حذف الجدول",
    deleteAsk: "حذف هذا الجدول وكل ما فيه؟",
    merge: "دمج",
    mergeCells: "دمج الخلايا",
    splitRows: "تقسيم أفقي",
    splitCols: "تقسيم عمودي",
    borders: "حدود",
    all: "الكل",
    outside: "الإطار",
    inside: "الداخل",
    none: "بلا",
    style: "نمط الخط",
    weight: "سماكة الخط",
    color: "اللون",
    edgeTop: "أعلى",
    edgeBottom: "أسفل",
    edgeRight: "يمين",
    edgeLeft: "يسار",
    styles: { single: "متصل", dashed: "متقطع", dotted: "منقط", double: "مزدوج", triple: "ثلاثي", thick: "سميك" },
    shading: "تظليل",
    fill: "تعبئة الخلية",
    custom: "لون",
    clearFill: "بلا تعبئة",
    alignCell: "محاذاة الخلية",
    top: "أعلى",
    middle: "وسط",
    bottom: "أسفل",
    alignTable: "محاذاة الجدول",
    right: "يمين",
    center: "توسيط",
    left: "يسار",
    size: "الحجم",
    auto: "تلقائي",
    full: "عرض الصفحة",
    header: "صف الرأس",
    distribute: "توزيع الأعمدة",
    autofit: "احتواء",
    properties: "خصائص",
    refused: "هذا الإجراء لا ينطبق على التحديد الحالي.",
    where: (row: number, rows: number, column: number, columns: number) =>
      `الصف ${row} من ${rows}  ·  العمود ${column} من ${columns}`,
  };
}
