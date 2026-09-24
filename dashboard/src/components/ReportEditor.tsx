import { useEffect, useRef, useState } from "react";

type Props = {
  value: string;
  onChange: (html: string) => void;
  zoom: number;
  labels: {
    zoomIn: string;
    zoomOut: string;
    bold: string;
    italic: string;
    underline: string;
    heading: string;
    list: string;
    align: string;
    image: string;
    table: string;
  };
};

export function ReportEditor({ value, onChange, zoom, labels }: Props) {
  const sheet = useRef<HTMLDivElement>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (!sheet.current || ready) return;
    sheet.current.innerHTML = value || "<p><br></p>";
    setReady(true);
  }, [ready, value]);

  function run(command: string, argument?: string) {
    sheet.current?.focus();
    document.execCommand(command, false, argument);
    onChange(sheet.current?.innerHTML ?? "");
  }

  function addImage(file: File | undefined) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => run("insertHTML", `<img src="${String(reader.result)}" alt="">`);
    reader.readAsDataURL(file);
  }

  return (
    <div className="report-editor">
      <div className="permission-line report-toolbar">
        <button type="button" className="btn" onClick={() => run("bold")}>{labels.bold}</button>
        <button type="button" className="btn" onClick={() => run("italic")}>{labels.italic}</button>
        <button type="button" className="btn" onClick={() => run("underline")}>{labels.underline}</button>
        <button type="button" className="btn" onClick={() => run("formatBlock", "h2")}>{labels.heading}</button>
        <button type="button" className="btn" onClick={() => run("insertUnorderedList")}>{labels.list}</button>
        <button type="button" className="btn" onClick={() => run("justifyRight")}>{labels.align}</button>
        <label className="btn">
          {labels.image}
          <input type="file" accept="image/*" hidden onChange={(event) => addImage(event.target.files?.[0])} />
        </label>
        <button
          type="button"
          className="btn"
          onClick={() => run("insertHTML", "<table><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></table>")}
        >
          {labels.table}
        </button>
        <span>{zoom}%</span>
      </div>
      <div
        ref={sheet}
        className="report-sheet"
        contentEditable
        role="textbox"
        aria-multiline="true"
        style={{ fontSize: `${zoom}%` }}
        onInput={() => onChange(sheet.current?.innerHTML ?? "")}
      />
    </div>
  );
}
