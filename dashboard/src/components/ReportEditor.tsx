import { useEffect, useLayoutEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from "react";

const HISTORY_LIMIT = 80;
const IMAGE_ACCEPT = "image/*,.jpg,.jpeg,.jpe,.jfif,.png,.gif,.webp,.bmp,.svg,.avif,.heic,.heif,.tif,.tiff,.ico";

type Mark = { opacity: number; width: number };

type Labels = {
  bold: string;
  italic: string;
  underline: string;
  strike: string;
  heading: string;
  paragraph: string;
  list: string;
  numbered: string;
  alignRight: string;
  alignCenter: string;
  alignLeft: string;
  image: string;
  table: string;
  undo: string;
  redo: string;
  delete: string;
  clear: string;
  pageBreak: string;
  title: string;
  header: string;
  footer: string;
  page: string;
  cover: string;
  uploadImage: string;
  pickImage: string;
  coverContent: string;
  hideChrome: string;
  showChrome: string;
  deletePage: string;
  imageSize: string;
  useAsCover: string;
  rows: string;
  columns: string;
  addRow: string;
  addColumn: string;
  deleteRow: string;
  deleteColumn: string;
  headerRow: string;
  tableWidth: string;
  borders: string;
  noBorders: string;
  textBox: string;
  shapeRect: string;
  shapeEllipse: string;
  shapeLine: string;
  direction: string;
  nudge: string;
  themeLight: string;
  themeDark: string;
  fontSize: string;
  watermark: string;
  watermarkOpacity: string;
  removeWatermark: string;
};

type Props = {
  title: string;
  header: string;
  footer: string;
  value: string;
  coverUrl?: string | null;
  watermarkUrl?: string | null;
  zoom: number;
  onTitle: (value: string) => void;
  onHeader: (value: string) => void;
  onFooter: (value: string) => void;
  onChange: (html: string) => void;
  onCoverFile: (file: File | null) => void;
  onWatermarkFile: (file: File | null) => void;
  syncKey?: number;
  syncHtml?: string;
  labels: Labels;
};

type PageState = { html: string; chrome: boolean };

function parseDocument(html: string): { cover: string | null; coverWidth: number; mark: Mark | null; pages: PageState[] } {
  if (!html.includes("hoc-page") && !html.includes("hoc-cover") && !html.includes("hoc-mark")) {
    const parts = html.split(/<div class="page-break"><\/div>/gi).map((part) => part.trim()).filter(Boolean);
    return {
      cover: null,
      coverWidth: 100,
      mark: null,
      pages: (parts.length > 0 ? parts : ["<p><br></p>"]).map((page) => ({ html: page, chrome: true })),
    };
  }
  const root = new DOMParser().parseFromString(`<div id="root">${html}</div>`, "text/html").getElementById("root");
  let cover: string | null = null;
  let coverWidth = 100;
  let mark: Mark | null = null;
  const pages: PageState[] = [];
  root?.querySelectorAll(":scope > section").forEach((section) => {
    if (section.classList.contains("hoc-cover")) {
      cover = section.innerHTML;
      const width = Number(section.getAttribute("data-width"));
      if (width >= 15 && width <= 100) coverWidth = width;
    } else if (section.classList.contains("hoc-mark")) {
      const opacity = Number(section.getAttribute("data-opacity"));
      const width = Number(section.getAttribute("data-width"));
      mark = {
        opacity: opacity >= 5 && opacity <= 80 ? opacity : 18,
        width: width >= 15 && width <= 80 ? width : 42,
      };
    } else if (section.classList.contains("hoc-page")) {
      pages.push({
        html: section.innerHTML || "<p><br></p>",
        chrome: section.getAttribute("data-chrome") !== "0",
      });
    }
  });
  if (pages.length === 0) pages.push({ html: "<p><br></p>", chrome: true });
  return { cover, coverWidth, mark, pages };
}

function serialize(coverHtml: string | null, coverWidth: number, pages: PageState[], mark: Mark | null) {
  const cover = coverHtml === null ? "" : `<section class="hoc-cover" data-width="${coverWidth}">${coverHtml}</section>`;
  const stamp = mark ? `<section class="hoc-mark" data-opacity="${mark.opacity}" data-width="${mark.width}"></section>` : "";
  const body = pages
    .map((page) => `<section class="hoc-page" data-chrome="${page.chrome ? "1" : "0"}">${page.html}</section>`)
    .join("");
  return cover + stamp + body;
}

export function ReportEditor({
  title,
  header,
  footer,
  value,
  coverUrl,
  watermarkUrl,
  zoom,
  onTitle,
  onHeader,
  onFooter,
  onChange,
  onCoverFile,
  onWatermarkFile,
  syncKey = 0,
  syncHtml = "",
  labels,
}: Props) {
  const initial = parseDocument(value);
  const pageRefs = useRef<Array<HTMLDivElement | null>>([]);
  const coverRef = useRef<HTMLDivElement | null>(null);
  const active = useRef(0);
  const history = useRef<string[]>([serialize(initial.cover, initial.coverWidth, initial.pages, initial.mark)]);
  const historyIndex = useRef(0);
  const applying = useRef(false);
  const pending = useRef<Node[]>([]);
  const pickedImage = useRef<HTMLImageElement | null>(null);
  const [pageCount, setPageCount] = useState(initial.pages.length);
  const [chrome, setChrome] = useState(initial.pages.map((page) => page.chrome));
  const [coverOn, setCoverOn] = useState(initial.cover !== null || Boolean(coverUrl));
  const [coverWidth, setCoverWidth] = useState(initial.coverWidth);
  const [markOn, setMarkOn] = useState(initial.mark !== null || Boolean(watermarkUrl));
  const [markOpacity, setMarkOpacity] = useState(initial.mark?.opacity ?? 18);
  const [markWidth, setMarkWidth] = useState(initial.mark?.width ?? 42);
  const [coverPicked, setCoverPicked] = useState(false);
  const [imageOn, setImageOn] = useState(false);
  const [tableOn, setTableOn] = useState(false);
  const [tableCols, setTableCols] = useState(3);
  const [tableRows, setTableRows] = useState(3);
  const [fontSize, setFontSize] = useState(14);
  const [themeName, setThemeName] = useState<"light" | "dark">("light");
  const [absOn, setAbsOn] = useState(false);
  const selectedAbs = useRef<HTMLElement | null>(null);
  const drag = useRef<{ mode: "move" | "resize" | "cover"; el: HTMLElement; page: HTMLElement; x: number; y: number; left: number; top: number; width: number; height: number } | null>(null);
  const [canUndo, setCanUndo] = useState(false);
  const [canRedo, setCanRedo] = useState(false);

  useEffect(() => {
    const read = () => setThemeName(document.documentElement.dataset.theme === "dark" ? "dark" : "light");
    read();
    const observer = new MutationObserver(read);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    initial.pages.forEach((page, index) => {
      const node = pageRefs.current[index];
      if (node) node.innerHTML = page.html;
    });
    if (coverRef.current && initial.cover) coverRef.current.innerHTML = initial.cover;
    // Seed once. Later edits live in the page DOM.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function readPages(): PageState[] {
    return Array.from({ length: pageCount }, (_, index) => ({
      html: pageRefs.current[index]?.innerHTML || "<p><br></p>",
      chrome: chrome[index] !== false,
    }));
  }

  function currentHtml() {
    const coverHtml = coverOn ? (coverRef.current?.innerHTML || "") : null;
    const mark = markOn ? { opacity: markOpacity, width: markWidth } : null;
    return serialize(coverHtml, coverWidth, readPages(), mark);
  }

  function markHistory() {
    setCanUndo(historyIndex.current > 0);
    setCanRedo(historyIndex.current < history.current.length - 1);
  }

  function pushHistory(html: string) {
    const past = history.current.slice(0, historyIndex.current + 1);
    if (past[past.length - 1] === html) return;
    history.current = [...past, html].slice(-HISTORY_LIMIT);
    historyIndex.current = history.current.length - 1;
    markHistory();
  }

  function emit() {
    const html = currentHtml();
    onChange(html);
    return html;
  }

  function growChrome(count: number) {
    setChrome((current) => {
      const next = current.slice(0, count);
      while (next.length < count) next.push(true);
      return next;
    });
  }

  function overflow(index: number) {
    const page = pageRefs.current[index];
    if (!page || page.childNodes.length < 2) return;
    const moved: Node[] = [];
    while (page.scrollHeight > page.clientHeight + 2 && page.childNodes.length > 1) {
      const node = page.lastChild;
      if (!node) break;
      moved.unshift(node);
      page.removeChild(node);
    }
    if (moved.length === 0) return;
    pending.current = moved;
    setPageCount((count) => {
      const next = Math.max(count, index + 2);
      growChrome(next);
      return next;
    });
  }

  useLayoutEffect(() => {
    if (pending.current.length === 0) return;
    const target = pageRefs.current[pageCount - 1];
    if (!target) return;
    target.innerHTML = "";
    pending.current.forEach((node) => target.appendChild(node));
    pending.current = [];
    const html = emit();
    if (!applying.current) pushHistory(html);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageCount]);

  function restore(html: string) {
    const parsed = parseDocument(html);
    applying.current = true;
    setCoverOn(parsed.cover !== null);
    setCoverWidth(parsed.coverWidth);
    setMarkOn(parsed.mark !== null);
    setMarkOpacity(parsed.mark?.opacity ?? 18);
    setMarkWidth(parsed.mark?.width ?? 42);
    setChrome(parsed.pages.map((page) => page.chrome));
    setPageCount(parsed.pages.length);
    requestAnimationFrame(() => {
      if (coverRef.current) coverRef.current.innerHTML = parsed.cover || "";
      parsed.pages.forEach((page, index) => {
        const node = pageRefs.current[index];
        if (node) node.innerHTML = page.html;
      });
      onChange(serialize(parsed.cover, parsed.coverWidth, parsed.pages, parsed.mark));
      applying.current = false;
      markHistory();
    });
  }

  useEffect(() => {
    if (syncKey === 0) return;
    const past = history.current.slice(0, historyIndex.current + 1);
    history.current = [...past, syncHtml].slice(-HISTORY_LIMIT);
    historyIndex.current = history.current.length - 1;
    restore(syncHtml);
    // Apply an external Gemini edit into the page DOM.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [syncKey]);

  function undo() {
    if (historyIndex.current <= 0) return;
    historyIndex.current -= 1;
    restore(history.current[historyIndex.current] ?? "");
  }

  function redo() {
    if (historyIndex.current >= history.current.length - 1) return;
    historyIndex.current += 1;
    restore(history.current[historyIndex.current] ?? "");
  }

  function run(command: string, argument?: string) {
    pageRefs.current[active.current]?.focus();
    pushHistory(currentHtml());
    document.execCommand(command, false, argument);
    const html = emit();
    pushHistory(html);
    overflow(active.current);
  }

  function clearPicked() {
    pickedImage.current?.classList.remove("is-picked");
    pickedImage.current = null;
    setImageOn(false);
    setCoverPicked(false);
    setTableOn(false);
  }

  function pickImage(image: HTMLImageElement) {
    pickedImage.current?.classList.remove("is-picked");
    pickedImage.current = image;
    image.classList.add("is-picked");
    setImageOn(true);
    setCoverPicked(false);
    setTableOn(false);
  }

  function selectAbs(element: HTMLElement | null) {
    selectedAbs.current?.classList.remove("is-selected");
    selectedAbs.current = element;
    element?.classList.add("is-selected");
    setAbsOn(Boolean(element));
  }

  function placeObject(kind: "text" | "rect" | "ellipse" | "line" | "image", src?: string, at?: { left: number; top: number }) {
    const page = pageRefs.current[active.current];
    if (!page) return;
    const box = document.createElement("div");
    box.className = "hoc-abs";
    box.contentEditable = "false";
    box.style.left = `${at?.left ?? 12}%`;
    box.style.top = `${at?.top ?? 16}%`;
    box.style.width = kind === "line" ? "40%" : "34%";
    box.style.height = kind === "line" ? "6%" : "18%";
    if (kind === "text") {
      box.classList.add("hoc-text");
      box.dir = "rtl";
      const text = document.createElement("div");
      text.className = "hoc-text-body";
      text.contentEditable = "true";
      text.dataset.placeholder = labels.textBox;
      text.style.fontSize = `${fontSize}pt`;
      box.appendChild(text);
    } else if (kind === "image" && src) {
      const image = document.createElement("img");
      image.src = src;
      image.alt = "";
      image.draggable = false;
      box.appendChild(image);
    } else {
      const shape = document.createElement("div");
      shape.className = `hoc-shape is-${kind}`;
      box.appendChild(shape);
    }
    const handle = document.createElement("span");
    handle.className = "hoc-resize";
    handle.dataset.resize = "1";
    box.appendChild(handle);
    pushHistory(currentHtml());
    page.appendChild(box);
    selectAbs(box);
    pushHistory(emit());
  }

  function addImage(file: File | undefined, at?: { left: number; top: number }) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => placeObject("image", String(reader.result), at);
    reader.readAsDataURL(file);
  }

  function applyFont(size: number) {
    const next = Math.min(72, Math.max(8, size));
    setFontSize(next);
    const text = selectedAbs.current?.querySelector(".hoc-text-body");
    if (text instanceof HTMLElement) {
      text.style.fontSize = `${next}pt`;
      emit();
    }
  }

  function flipDirection() {
    const box = selectedAbs.current;
    if (!box) return;
    box.dir = box.dir === "ltr" ? "rtl" : "ltr";
    emit();
  }

  function nudge(dx: number, dy: number) {
    const box = selectedAbs.current;
    if (!box) return;
    box.style.left = `${Math.min(92, Math.max(0, (Number.parseFloat(box.style.left) || 0) + dx))}%`;
    box.style.top = `${Math.min(92, Math.max(0, (Number.parseFloat(box.style.top) || 0) + dy))}%`;
    emit();
  }

  function gestureStart(event: ReactPointerEvent<HTMLElement>, index: number) {
    const target = event.target instanceof Element ? event.target : null;
    const page = pageRefs.current[index];
    if (!target || !page) return;
    const box = target.closest(".hoc-abs");
    if (!(box instanceof HTMLElement) || !page.contains(box)) return;
    if (target.closest(".hoc-text-body")) {
      selectAbs(box);
      return;
    }
    event.preventDefault();
    selectAbs(box);
    drag.current = {
      mode: target.closest("[data-resize]") ? "resize" : "move",
      el: box,
      page,
      x: event.clientX,
      y: event.clientY,
      left: Number.parseFloat(box.style.left) || 0,
      top: Number.parseFloat(box.style.top) || 0,
      width: Number.parseFloat(box.style.width) || 30,
      height: Number.parseFloat(box.style.height) || 16,
    };
  }

  function gestureMove(event: ReactPointerEvent<HTMLElement>) {
    const gesture = drag.current;
    if (!gesture || gesture.mode === "cover") return;
    const bounds = gesture.page.getBoundingClientRect();
    const dx = ((event.clientX - gesture.x) / bounds.width) * 100;
    const dy = ((event.clientY - gesture.y) / bounds.height) * 100;
    if (gesture.mode === "move") {
      gesture.el.style.left = `${Math.min(92, Math.max(0, gesture.left + dx))}%`;
      gesture.el.style.top = `${Math.min(92, Math.max(0, gesture.top + dy))}%`;
    } else {
      gesture.el.style.width = `${Math.min(100, Math.max(8, gesture.width + dx))}%`;
      gesture.el.style.height = `${Math.min(100, Math.max(4, gesture.height + dy))}%`;
    }
  }

  function gestureEnd() {
    if (!drag.current || drag.current.mode === "cover") return;
    drag.current = null;
    emit();
  }

  function coverResizeStart(event: ReactPointerEvent<HTMLElement>) {
    const frame = event.currentTarget.parentElement;
    if (!frame) return;
    event.preventDefault();
    event.stopPropagation();
    drag.current = {
      mode: "cover",
      el: frame,
      page: frame,
      x: event.clientX,
      y: event.clientY,
      left: 0,
      top: 0,
      width: coverWidth,
      height: 0,
    };
    const move = (pointer: PointerEvent) => {
      const current = drag.current;
      if (!current || current.mode !== "cover") return;
      const bounds = frame.parentElement?.getBoundingClientRect();
      if (!bounds) return;
      const next = current.width + ((pointer.clientX - current.x) / bounds.width) * 100;
      setCoverWidth(Math.min(100, Math.max(15, Math.round(next))));
    };
    const stop = () => {
      drag.current = null;
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", stop);
    };
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", stop);
  }

  function addPage() {
    pushHistory(currentHtml());
    setPageCount((count) => {
      const next = count + 1;
      growChrome(next);
      active.current = count;
      return next;
    });
  }

  function deletePage(index: number) {
    const pages = readPages();
    pushHistory(currentHtml());
    if (pages.length === 1) {
      const page = pageRefs.current[0];
      if (page) page.innerHTML = "<p><br></p>";
      pushHistory(emit());
      return;
    }
    pages.splice(index, 1);
    applying.current = true;
    setChrome(pages.map((page) => page.chrome));
    setPageCount(pages.length);
    requestAnimationFrame(() => {
      pages.forEach((page, item) => {
        const node = pageRefs.current[item];
        if (node) node.innerHTML = page.html;
      });
      const mark = markOn ? { opacity: markOpacity, width: markWidth } : null;
      const html = serialize(coverOn ? (coverRef.current?.innerHTML || "") : null, coverWidth, pages, mark);
      onChange(html);
      applying.current = false;
      pushHistory(html);
    });
  }

  function toggleChrome(index: number) {
    pushHistory(currentHtml());
    setChrome((current) => current.map((value, item) => (item === index ? !value : value)));
  }

  useEffect(() => {
    if (applying.current) return;
    onChange(currentHtml());
    // chrome and cover width are outside the contenteditable input path.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chrome, coverWidth, coverOn, markOn, markOpacity, markWidth]);

  function selectedCell() {
    const node = document.getSelection()?.anchorNode;
    const element = node instanceof Element ? node : node?.parentElement;
    return element?.closest("td, th") ?? null;
  }

  function selectedTable() {
    return selectedCell()?.closest("table") ?? null;
  }

  function insertTable() {
    const columns = Math.max(1, Math.min(8, tableCols));
    const rows = Math.max(1, Math.min(20, tableRows));
    const head = `<tr>${Array.from({ length: columns }, () => "<th>&nbsp;</th>").join("")}</tr>`;
    const body = Array.from({ length: Math.max(0, rows - 1) }, () => `<tr>${Array.from({ length: columns }, () => "<td>&nbsp;</td>").join("")}</tr>`).join("");
    run("insertHTML", `<table class="hoc-border" style="width: 100%;"><tbody>${head}${body}</tbody></table>`);
    setTableOn(true);
  }

  function mutateTable(change: (table: HTMLTableElement, cell: HTMLTableCellElement) => void) {
    const cell = selectedCell();
    const table = cell?.closest("table");
    if (!cell || !table) return;
    pushHistory(currentHtml());
    change(table, cell as HTMLTableCellElement);
    pushHistory(emit());
  }

  function addRow() {
    mutateTable((table, cell) => {
      const row = cell.parentElement;
      if (!(row instanceof HTMLTableRowElement)) return;
      const columns = row.children.length || 1;
      const next = table.insertRow(row.rowIndex + 1);
      const tag = cell.tagName.toLowerCase();
      for (let index = 0; index < columns; index += 1) {
        const item = document.createElement(tag);
        item.innerHTML = "&nbsp;";
        next.appendChild(item);
      }
    });
  }

  function addColumn() {
    mutateTable((table, cell) => {
      const index = cell.cellIndex + 1;
      Array.from(table.rows).forEach((row) => {
        const item = document.createElement(row.cells[cell.cellIndex]?.tagName.toLowerCase() || "td");
        item.innerHTML = "&nbsp;";
        row.insertBefore(item, row.cells[index] ?? null);
      });
    });
  }

  function deleteRow() {
    mutateTable((table, cell) => {
      const row = cell.parentElement;
      if (!(row instanceof HTMLTableRowElement) || table.rows.length <= 1) return;
      table.deleteRow(row.rowIndex);
    });
  }

  function deleteColumn() {
    mutateTable((table, cell) => {
      const index = cell.cellIndex;
      if ((table.rows[0]?.cells.length ?? 0) <= 1) return;
      Array.from(table.rows).forEach((row) => row.deleteCell(index));
    });
  }

  function toggleHeader() {
    mutateTable((table) => {
      const row = table.rows[0];
      if (!row) return;
      const asHeader = row.cells[0]?.tagName !== "TH";
      Array.from(row.cells).forEach((item) => {
        const next = document.createElement(asHeader ? "th" : "td");
        next.innerHTML = item.innerHTML;
        next.style.cssText = item.style.cssText;
        item.replaceWith(next);
      });
    });
  }

  function setTableWidth(width: number) {
    mutateTable((table) => {
      table.style.width = `${width}%`;
    });
  }

  function setBorders(on: boolean) {
    mutateTable((table) => {
      table.classList.toggle("hoc-plain", !on);
      table.classList.toggle("hoc-border", on);
    });
  }

  function alignCell(command: string) {
    const cell = selectedCell();
    if (!cell) return;
    const range = document.createRange();
    range.selectNodeContents(cell);
    const selection = document.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    run(command);
  }

  async function useImageAsCover() {
    const image = pickedImage.current;
    if (!image) return;
    const response = await fetch(image.src);
    const blob = await response.blob();
    onCoverFile(new File([blob], "cover", { type: blob.type || "image/jpeg" }));
    setCoverOn(true);
    setCoverPicked(true);
  }

  return (
    <div className="report-editor">
      <div className="html-editor-toolbar report-toolbar" role="toolbar" onMouseDown={(event) => event.preventDefault()}>
        <button type="button" className="html-editor-btn html-editor-btn-wide" title={labels.undo} disabled={!canUndo} onClick={undo}>{labels.undo}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" title={labels.redo} disabled={!canRedo} onClick={redo}>{labels.redo}</button>
        <span className="html-editor-toolbar-sep" />
        <button type="button" className="html-editor-btn" onClick={() => run("bold")}>{labels.bold}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("italic")}>{labels.italic}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("underline")}>{labels.underline}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("strikeThrough")}>{labels.strike}</button>
        <span className="html-editor-toolbar-sep" />
        <button type="button" className="html-editor-btn" onClick={() => run("formatBlock", "h2")}>{labels.heading}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("formatBlock", "p")}>{labels.paragraph}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("insertUnorderedList")}>{labels.list}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("insertOrderedList")}>{labels.numbered}</button>
        <span className="html-editor-toolbar-sep" />
        <button type="button" className="html-editor-btn" onClick={() => run("justifyRight")}>{labels.alignRight}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("justifyCenter")}>{labels.alignCenter}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("justifyLeft")}>{labels.alignLeft}</button>
        <span className="html-editor-toolbar-sep" />
        <label className="html-editor-btn html-editor-btn-wide">
          {labels.image}
          <input type="file" accept={IMAGE_ACCEPT} hidden onChange={(event) => addImage(event.target.files?.[0])} />
        </label>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => placeObject("text")}>{labels.textBox}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => placeObject("rect")}>{labels.shapeRect}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => placeObject("ellipse")}>{labels.shapeEllipse}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => placeObject("line")}>{labels.shapeLine}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" disabled={!absOn} onClick={flipDirection}>{labels.direction}</button>
        <button type="button" className="html-editor-btn" disabled={!absOn} onClick={() => nudge(-2, 0)} aria-label={labels.nudge}>←</button>
        <button type="button" className="html-editor-btn" disabled={!absOn} onClick={() => nudge(2, 0)} aria-label={labels.nudge}>→</button>
        <button type="button" className="html-editor-btn" disabled={!absOn} onClick={() => nudge(0, -2)} aria-label={labels.nudge}>↑</button>
        <button type="button" className="html-editor-btn" disabled={!absOn} onClick={() => nudge(0, 2)} aria-label={labels.nudge}>↓</button>
        <label className="report-mini">
          {labels.fontSize}
          <input className="field" type="number" min={8} max={72} value={fontSize} onChange={(event) => applyFont(Number(event.target.value))} />
        </label>
        {[12, 14, 18, 24, 32].map((size) => (
          <button key={size} type="button" className={`html-editor-btn${fontSize === size ? " is-active" : ""}`} onClick={() => applyFont(size)}>{size}</button>
        ))}
        <span className="report-theme">{themeName === "dark" ? labels.themeDark : labels.themeLight}</span>
        <label className="report-mini">
          {labels.rows}
          <input className="field" type="number" min={1} max={20} value={tableRows} onChange={(event) => setTableRows(Number(event.target.value))} />
        </label>
        <label className="report-mini">
          {labels.columns}
          <input className="field" type="number" min={1} max={8} value={tableCols} onChange={(event) => setTableCols(Number(event.target.value))} />
        </label>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={insertTable}>{labels.table}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={addPage}>{labels.pageBreak}</button>
        <button type="button" className={`html-editor-btn html-editor-btn-wide${coverOn ? " is-active" : ""}`} onClick={() => { setCoverOn(true); }}>{labels.cover}</button>
        <button type="button" className={`html-editor-btn html-editor-btn-wide${markOn ? " is-active" : ""}`} onClick={() => setMarkOn(true)}>{labels.watermark}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => run("removeFormat")}>{labels.clear}</button>
        <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => run("delete")}>{labels.delete}</button>
        <span className="report-page-count">{labels.page} {pageCount}</span>
      </div>
      {markOn ? (
        <div className="html-editor-toolbar" role="toolbar">
          <label className="html-editor-btn html-editor-btn-wide">
            {labels.uploadImage}
            <input type="file" accept={IMAGE_ACCEPT} hidden onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) onWatermarkFile(file);
            }} />
          </label>
          <label className="report-mini">
            {labels.watermarkOpacity}
            <input type="range" min={5} max={80} value={markOpacity} onChange={(event) => setMarkOpacity(Number(event.target.value))} />
          </label>
          <label className="report-mini">
            {labels.imageSize}
            <input type="range" min={15} max={80} value={markWidth} onChange={(event) => setMarkWidth(Number(event.target.value))} />
          </label>
          <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => { setMarkOn(false); onWatermarkFile(null); }}>{labels.removeWatermark}</button>
        </div>
      ) : null}
      {imageOn || coverPicked || tableOn ? (
        <div className="html-editor-toolbar report-inspector" role="toolbar">
          {imageOn ? (
            <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => void useImageAsCover()}>{labels.useAsCover}</button>
          ) : null}
          {tableOn ? (
            <>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={addRow}>{labels.addRow}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={deleteRow}>{labels.deleteRow}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={addColumn}>{labels.addColumn}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={deleteColumn}>{labels.deleteColumn}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={toggleHeader}>{labels.headerRow}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => setBorders(true)}>{labels.borders}</button>
              <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => setBorders(false)}>{labels.noBorders}</button>
              <button type="button" className="html-editor-btn" onClick={() => alignCell("justifyRight")}>{labels.alignRight}</button>
              <button type="button" className="html-editor-btn" onClick={() => alignCell("justifyCenter")}>{labels.alignCenter}</button>
              <button type="button" className="html-editor-btn" onClick={() => alignCell("justifyLeft")}>{labels.alignLeft}</button>
              <label className="report-mini">
                {labels.tableWidth}
                <input type="range" min={30} max={100} defaultValue={100} onChange={(event) => setTableWidth(Number(event.target.value))} />
              </label>
            </>
          ) : null}
        </div>
      ) : null}
      <div className="report-desk">
        <div className="report-scale" style={{ zoom: zoom / 100 }}>
          {coverOn ? (
            <article className="a4-page a4-cover-page">
              {markOn && watermarkUrl ? (
                <div className="a4-watermark" aria-hidden="true">
                  <img src={watermarkUrl} alt="" style={{ width: `${markWidth}%`, opacity: markOpacity / 100 }} />
                </div>
              ) : null}
              <div className="a4-page-tools">
                <label className="html-editor-btn html-editor-btn-wide">
                  {labels.uploadImage}
                  <input
                    type="file"
                    accept={IMAGE_ACCEPT}
                    hidden
                    onChange={(event) => {
                      const file = event.target.files?.[0] ?? null;
                      if (file) onCoverFile(file);
                      setCoverPicked(true);
                      clearPicked();
                      setCoverPicked(true);
                    }}
                  />
                </label>
                <button
                  type="button"
                  className="html-editor-btn html-editor-btn-wide"
                  onClick={() => {
                    setCoverOn(false);
                    onCoverFile(null);
                    if (coverRef.current) coverRef.current.innerHTML = "";
                  }}
                >
                  {labels.deletePage}
                </button>
              </div>
              <div className="a4-cover-stage">
                {coverUrl ? (
                  <div className={`a4-cover-frame${coverPicked ? " is-picked" : ""}`} style={{ width: `${coverWidth}%` }}>
                    <img src={coverUrl} alt="" draggable={false} onClick={() => { clearPicked(); setCoverPicked(true); }} />
                    <span className="hoc-resize" data-resize="1" onPointerDown={coverResizeStart} />
                  </div>
                ) : null}
                <div
                  ref={coverRef}
                  className="a4-cover-copy"
                  contentEditable
                  role="textbox"
                  aria-multiline="true"
                  aria-label={labels.coverContent}
                  dir="rtl"
                  data-placeholder={labels.coverContent}
                  onInput={() => {
                    if (!applying.current) pushHistory(emit());
                  }}
                />
              </div>
            </article>
          ) : null}
          {Array.from({ length: pageCount }, (_, index) => (
            <article className="a4-page" key={index}>
              {markOn && watermarkUrl ? (
                <div className="a4-watermark" aria-hidden="true">
                  <img src={watermarkUrl} alt="" style={{ width: `${markWidth}%`, opacity: markOpacity / 100 }} />
                </div>
              ) : null}
              <div className="a4-page-tools">
                <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => toggleChrome(index)}>
                  {chrome[index] === false ? labels.showChrome : labels.hideChrome}
                </button>
                <button type="button" className="html-editor-btn html-editor-btn-wide" onClick={() => deletePage(index)}>{labels.deletePage}</button>
              </div>
              {chrome[index] !== false ? (
                <label className="a4-header">
                  <input value={header} placeholder={labels.header} aria-label={labels.header} onChange={(event) => onHeader(event.target.value)} />
                </label>
              ) : null}
              {index === 0 ? <h1 className="a4-title">{title || labels.title}</h1> : null}
              <div
                ref={(node) => {
                  pageRefs.current[index] = node;
                  if (node && node.innerHTML === "") node.innerHTML = "<p><br></p>";
                }}
                className="a4-body"
                contentEditable
                role="textbox"
                aria-multiline="true"
                dir="rtl"
                onFocus={() => { active.current = index; }}
                onDragOver={(event) => event.preventDefault()}
                onDrop={(event) => {
                  event.preventDefault();
                  const file = event.dataTransfer.files?.[0];
                  const bounds = event.currentTarget.getBoundingClientRect();
                  const at = {
                    left: Math.min(80, Math.max(0, ((event.clientX - bounds.left) / bounds.width) * 100)),
                    top: Math.min(80, Math.max(0, ((event.clientY - bounds.top) / bounds.height) * 100)),
                  };
                  active.current = index;
                  if (file) addImage(file, at);
                }}
                onPointerDown={(event) => gestureStart(event, index)}
                onPointerMove={gestureMove}
                onPointerUp={gestureEnd}
                onMouseUp={(event) => {
                  const target = event.target instanceof Element ? event.target : null;
                  const image = target?.closest("img");
                  if (image instanceof HTMLImageElement) {
                    pickImage(image);
                    return;
                  }
                  if (selectedTable()) {
                    pickedImage.current?.classList.remove("is-picked");
                    pickedImage.current = null;
                    setImageOn(false);
                    setCoverPicked(false);
                    setTableOn(true);
                  }
                }}
                onKeyDown={(event) => {
                  if (!(event.ctrlKey || event.metaKey)) return;
                  const key = event.key.toLowerCase();
                  if (key === "z" && !event.shiftKey) {
                    event.preventDefault();
                    undo();
                  } else if (key === "y" || (key === "z" && event.shiftKey)) {
                    event.preventDefault();
                    redo();
                  } else if (key === "b") {
                    event.preventDefault();
                    run("bold");
                  } else if (key === "i") {
                    event.preventDefault();
                    run("italic");
                  } else if (key === "u") {
                    event.preventDefault();
                    run("underline");
                  }
                }}
                onInput={() => {
                  if (applying.current) return;
                  const html = emit();
                  pushHistory(html);
                  overflow(index);
                }}
              />
              {chrome[index] !== false ? (
                <label className="a4-footer">
                  <input value={footer} placeholder={labels.footer} aria-label={labels.footer} onChange={(event) => onFooter(event.target.value)} />
                  <span>{index + 1}</span>
                </label>
              ) : null}
            </article>
          ))}
        </div>
      </div>
      <label className="field-label">
        {labels.title}
        <input className="field" value={title} onChange={(event) => onTitle(event.target.value)} required />
      </label>
    </div>
  );
}
