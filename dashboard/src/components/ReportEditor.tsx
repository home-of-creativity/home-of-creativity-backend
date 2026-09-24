import { useEffect, useLayoutEffect, useRef, useState } from "react";

const PAGE_BREAK = '<div class="page-break"></div>';
const HISTORY_LIMIT = 80;

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
};

type Props = {
  title: string;
  header: string;
  footer: string;
  value: string;
  coverUrl?: string | null;
  zoom: number;
  onTitle: (value: string) => void;
  onHeader: (value: string) => void;
  onFooter: (value: string) => void;
  onChange: (html: string) => void;
  labels: Labels;
};

function splitBody(html: string): string[] {
  const parts = html
    .split(/<div class="page-break"><\/div>/gi)
    .map((part) => part.trim())
    .filter(Boolean);
  return parts.length > 0 ? parts : ["<p><br></p>"];
}

function joinPages(pages: string[]) {
  return pages.join(PAGE_BREAK);
}

export function ReportEditor({
  title,
  header,
  footer,
  value,
  coverUrl,
  zoom,
  onTitle,
  onHeader,
  onFooter,
  onChange,
  labels,
}: Props) {
  const initial = splitBody(value);
  const pageRefs = useRef<Array<HTMLDivElement | null>>([]);
  const active = useRef(0);
  const history = useRef<string[]>([joinPages(initial)]);
  const historyIndex = useRef(0);
  const applying = useRef(false);
  const pending = useRef<Node[]>([]);
  const [pageCount, setPageCount] = useState(initial.length);
  const [canUndo, setCanUndo] = useState(false);
  const [canRedo, setCanRedo] = useState(false);

  useEffect(() => {
    initial.forEach((html, index) => {
      const page = pageRefs.current[index];
      if (page) page.innerHTML = html;
    });
    // Seed once. Later edits live in the page DOM.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function readPages() {
    return Array.from({ length: pageCount }, (_, index) => pageRefs.current[index]?.innerHTML || "<p><br></p>");
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
    const html = joinPages(readPages());
    onChange(html);
    return html;
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
    setPageCount((count) => Math.max(count, index + 2));
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
    // emit/pushHistory close over the latest page count after the move.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageCount]);

  function restore(html: string) {
    const pages = splitBody(html);
    applying.current = true;
    setPageCount(pages.length);
    requestAnimationFrame(() => {
      pages.forEach((pageHtml, index) => {
        const page = pageRefs.current[index];
        if (page) page.innerHTML = pageHtml;
      });
      onChange(joinPages(pages));
      applying.current = false;
      markHistory();
    });
  }

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
    const page = pageRefs.current[active.current];
    page?.focus();
    pushHistory(joinPages(readPages()));
    document.execCommand(command, false, argument);
    const html = emit();
    pushHistory(html);
    overflow(active.current);
  }

  function addImage(file: File | undefined) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => run("insertHTML", `<img src="${String(reader.result)}" alt="">`);
    reader.readAsDataURL(file);
  }

  function addPage() {
    pushHistory(joinPages(readPages()));
    setPageCount((count) => count + 1);
    active.current = pageCount;
  }

  return (
    <div className="report-editor">
      <div className="html-editor-toolbar report-toolbar" role="toolbar" onMouseDown={(event) => event.preventDefault()}>
        <button type="button" className="html-editor-btn" title={labels.undo} disabled={!canUndo} onClick={undo}>{labels.undo}</button>
        <button type="button" className="html-editor-btn" title={labels.redo} disabled={!canRedo} onClick={redo}>{labels.redo}</button>
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
        <label className="html-editor-btn">
          {labels.image}
          <input type="file" accept="image/*" hidden onChange={(event) => addImage(event.target.files?.[0])} />
        </label>
        <button type="button" className="html-editor-btn" onClick={() => run("insertHTML", "<table><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>")}>{labels.table}</button>
        <button type="button" className="html-editor-btn" onClick={addPage}>{labels.pageBreak}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("removeFormat")}>{labels.clear}</button>
        <button type="button" className="html-editor-btn" onClick={() => run("delete")}>{labels.delete}</button>
        <span className="report-page-count">{labels.page} {pageCount}</span>
      </div>
      <div className="report-desk">
        <div className="report-scale" style={{ zoom: zoom / 100 }}>
          {coverUrl ? (
            <article className="a4-page a4-cover">
              <img src={coverUrl} alt="" />
            </article>
          ) : null}
          {Array.from({ length: pageCount }, (_, index) => (
            <article className="a4-page" key={index}>
              <label className="a4-header">
                <input value={header} placeholder={labels.header} onChange={(event) => onHeader(event.target.value)} />
              </label>
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
              <label className="a4-footer">
                <input value={footer} placeholder={labels.footer} onChange={(event) => onFooter(event.target.value)} />
                <span>{index + 1}</span>
              </label>
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
