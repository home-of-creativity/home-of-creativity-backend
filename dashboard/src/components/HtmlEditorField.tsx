import { useEffect, useRef, useState } from "react";
import {
  HTML_EDITOR_ACTIONS,
  insertAroundSelection,
  stripHtmlTags,
  unwrapAnchor,
} from "../lib/htmlEditor";

const HISTORY_LIMIT = 40;

export function HtmlEditorField({
  label,
  value,
  onChange,
  dir,
  rows = 10,
  placeholder,
  hint,
  error,
  toolbarLabel,
  previewLabel,
  showInlinePreview = false,
  span = false,
  className = "field field-mono",
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  dir: "rtl" | "ltr";
  rows?: number;
  placeholder?: string;
  hint?: string;
  error?: string;
  toolbarLabel: string;
  previewLabel?: string;
  showInlinePreview?: boolean;
  span?: boolean;
  className?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const internalChange = useRef(false);
  const [showHtml, setShowHtml] = useState(true);
  const [history, setHistory] = useState<string[]>([value]);
  const [historyIndex, setHistoryIndex] = useState(0);

  useEffect(() => {
    if (internalChange.current) {
      internalChange.current = false;
      return;
    }
    setHistory([value]);
    setHistoryIndex(0);
  }, [value]);

  function pushHistory(next: string) {
    internalChange.current = true;
    setHistory((prev) => {
      const trimmed = prev.slice(0, historyIndex + 1);
      const merged = [...trimmed, next].slice(-HISTORY_LIMIT);
      setHistoryIndex(merged.length - 1);
      return merged;
    });
    onChange(next);
  }

  function insert(open: string, close: string) {
    const el = ref.current;
    if (!el) {
      pushHistory(`${open}${close ? "…" : ""}${close}`);
      return;
    }
    const { next, caret } = insertAroundSelection(el, open, close);
    pushHistory(next);
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(caret, caret);
    });
  }

  function transformSelection(mapper: (selected: string) => string) {
    const el = ref.current;
    if (!el) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = el.value.slice(start, end);
    if (!selected) return;
    const mapped = mapper(selected);
    const next = `${el.value.slice(0, start)}${mapped}${el.value.slice(end)}`;
    const caret = start + mapped.length;
    pushHistory(next);
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(caret, caret);
    });
  }

  function undo() {
    if (historyIndex <= 0) return;
    const nextIndex = historyIndex - 1;
    internalChange.current = true;
    setHistoryIndex(nextIndex);
    onChange(history[nextIndex] ?? "");
  }

  function redo() {
    if (historyIndex >= history.length - 1) return;
    const nextIndex = historyIndex + 1;
    internalChange.current = true;
    setHistoryIndex(nextIndex);
    onChange(history[nextIndex] ?? "");
  }

  return (
    <label className={span ? "field-label html-editor-field field-span" : "field-label html-editor-field"}>
      <span>{label}</span>
      <div className="html-editor-toolbar" role="toolbar" aria-label={toolbarLabel}>
        {HTML_EDITOR_ACTIONS.map((action) => (
          <button
            key={action.id}
            type="button"
            className="html-editor-btn"
            title={action.title ?? action.label}
            onClick={() => insert(action.open, action.close)}
          >
            {action.label}
          </button>
        ))}
        <span className="html-editor-toolbar-sep" aria-hidden="true" />
        <button type="button" className="html-editor-btn" title="Undo" disabled={historyIndex <= 0} onClick={undo}>
          ↶
        </button>
        <button
          type="button"
          className="html-editor-btn"
          title="Redo"
          disabled={historyIndex >= history.length - 1}
          onClick={redo}
        >
          ↷
        </button>
        <button
          type="button"
          className="html-editor-btn"
          title="Clear formatting"
          onClick={() => transformSelection(stripHtmlTags)}
        >
          ⌫
        </button>
        <button type="button" className="html-editor-btn" title="Unlink" onClick={() => transformSelection(unwrapAnchor)}>
          ⛓
        </button>
        <button
          type="button"
          className={`html-editor-btn html-editor-btn-wide${showHtml ? " is-active" : ""}`}
          onClick={() => setShowHtml((current) => !current)}
        >
          {showHtml ? "HTML" : previewLabel ?? "Preview"}
        </button>
      </div>

      {showHtml ? (
        <textarea
          ref={ref}
          className={error ? `${className} has-error` : className}
          dir={dir}
          rows={rows}
          spellCheck={false}
          placeholder={placeholder}
          value={value}
          onChange={(event) => pushHistory(event.target.value)}
        />
      ) : (
        <div className="html-editor-preview" dir={dir} dangerouslySetInnerHTML={{ __html: value || "" }} />
      )}

      {error ? <p className="field-error">{error}</p> : null}
      {hint ? <small className="muted">{hint}</small> : null}
      {showInlinePreview && showHtml ? (
        <>
          <span className="html-editor-preview-label">{previewLabel}</span>
          <div className="html-editor-preview" dir={dir} dangerouslySetInnerHTML={{ __html: value || "" }} />
        </>
      ) : null}
    </label>
  );
}
