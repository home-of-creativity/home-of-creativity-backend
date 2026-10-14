import { Printer, X } from "lucide-react";
import { useEffect, useMemo, useRef } from "react";
import { copy } from "../i18n";

type Translate = (c: { ar: string; en: string }) => string;

export type DraftPaperLine = {
  kind: "product" | "section" | "note";
  title: string;
  notes?: string;
  units: number;
  price: number;
  discount: number;
  taxes: string[];
  amount: number;
};

/** The unsaved form, laid out like Odoo's quotation or invoice. */
export type DraftPaper = {
  kind: "quotation" | "invoice";
  name?: string;
  client: { name: string; company?: string | null; email?: string | null; phone?: string | null } | null;
  /** Label and value pairs printed under the title (dates, terms, reference). */
  info: { label: string; value: string }[];
  lines: DraftPaperLine[];
  untaxed: number;
  tax: number;
  total: number;
  /** Quotation terms (HTML from the editor). */
  terms?: string;
};

function escape(value: string) {
  return value.replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char] ?? char));
}

function money(value: number) {
  return `$ ${value.toFixed(2)}`;
}

function number(value: number) {
  return Number.isInteger(value) ? String(value) : value.toFixed(2);
}

export function draftPaperHtml(paper: DraftPaper, t: Translate, dir: "rtl" | "ltr") {
  const title = `${t(paper.kind === "quotation" ? copy.paperDocQuotation : copy.paperDocInvoice)}${paper.name ? ` ${paper.name}` : ""}`;
  const client = paper.client;
  const clientBlock = client
    ? [client.company && client.company !== client.name ? client.company : "", client.name, client.email ?? "", client.phone ?? ""]
      .filter(Boolean)
      .map((row, index) => (index === 0 ? `<strong>${escape(row)}</strong>` : `<span dir="auto">${escape(row)}</span>`))
      .join("<br>")
    : `<span class="muted">${escape(t(copy.paperChooseClient))}</span>`;
  const info = paper.info
    .filter((item) => item.value)
    .map((item) => `<div><span>${escape(item.label)}</span><strong dir="auto">${escape(item.value)}</strong></div>`)
    .join("");
  const rows = paper.lines
    .filter((line) => line.title.trim() !== "")
    .map((line) => {
      if (line.kind === "section") return `<tr class="section"><td colspan="6">${escape(line.title)}</td></tr>`;
      if (line.kind === "note") return `<tr class="note"><td colspan="6">${escape(line.title)}</td></tr>`;
      const notes = line.notes?.trim() ? `<div class="desc">${escape(line.notes.trim()).replace(/\n/g, "<br>")}</div>` : "";
      return `<tr>
        <td>${escape(line.title)}${notes}</td>
        <td class="num">${number(line.units)}</td>
        <td class="num">${money(line.price)}</td>
        <td>${escape(line.taxes.join("، "))}</td>
        <td class="num">${line.discount > 0 ? `${number(line.discount)}%` : ""}</td>
        <td class="num">${money(line.amount)}</td>
      </tr>`;
    })
    .join("");
  const totals = [
    `<div><span>${escape(t(copy.paperUntaxed))}</span><strong>${money(paper.untaxed)}</strong></div>`,
    paper.tax > 0.004 ? `<div><span>${escape(t(copy.paperTax))}</span><strong>${money(paper.tax)}</strong></div>` : "",
    `<div class="grand"><span>${escape(t(copy.paperTotal))}</span><strong>${money(paper.total)}</strong></div>`,
  ].join("");
  const terms = paper.terms?.trim() ? `<section class="terms">${paper.terms}</section>` : "";

  return `<!doctype html>
<html lang="${dir === "rtl" ? "ar" : "en"}" dir="${dir}">
<head>
<meta charset="utf-8">
<title>${escape(title)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 24px; font: 13px/1.5 "Segoe UI", Tahoma, Arial, sans-serif; color: #222; background: #fff; }
  .watermark { position: fixed; inset: 40% 0 auto; text-align: center; font-size: 64px; font-weight: 700; color: rgba(0,0,0,.05); transform: rotate(-18deg); pointer-events: none; }
  header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #714b67; padding-bottom: 12px; margin-bottom: 18px; }
  .brand { font-size: 18px; font-weight: 700; color: #714b67; }
  .client { text-align: end; }
  h1 { font-size: 24px; margin: 0 0 12px; color: #714b67; }
  .info { display: flex; flex-wrap: wrap; gap: 12px 32px; margin-bottom: 18px; }
  .info div { display: flex; flex-direction: column; }
  .info span, .muted { color: #6c757d; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: start; border-bottom: 2px solid #222; padding: 6px 8px; font-size: 12px; }
  td { border-bottom: 1px solid #dee2e6; padding: 6px 8px; vertical-align: top; }
  .num { text-align: end; white-space: nowrap; direction: ltr; }
  th.num { text-align: end; }
  tr.section td { font-weight: 700; background: #f2f2f2; }
  tr.note td { font-style: italic; color: #555; }
  .desc { color: #6c757d; font-size: 12px; margin-top: 2px; }
  .totals { margin-inline-start: auto; width: 280px; margin-top: 12px; }
  .totals div { display: flex; justify-content: space-between; padding: 4px 8px; border-bottom: 1px solid #dee2e6; }
  .totals strong { direction: ltr; }
  .totals .grand { border-top: 2px solid #222; border-bottom: none; font-size: 15px; }
  .terms { margin-top: 28px; padding-top: 12px; border-top: 1px solid #dee2e6; }
  @media print { body { padding: 0; } }
</style>
</head>
<body>
<div class="watermark">${escape(t(copy.paperDraftWatermark))}</div>
<header>
  <div class="brand">Home of Creativity</div>
  <div class="client">${clientBlock}</div>
</header>
<h1>${escape(title)}</h1>
<div class="info">${info}</div>
<table>
  <thead><tr>
    <th>${escape(t(copy.paperProduct))}</th>
    <th class="num">${escape(t(copy.paperQty))}</th>
    <th class="num">${escape(t(copy.paperUnitPrice))}</th>
    <th>${escape(t(copy.paperTax))}</th>
    <th class="num">${escape(t(copy.paperDiscount))}</th>
    <th class="num">${escape(t(copy.paperLineAmount))}</th>
  </tr></thead>
  <tbody>${rows}</tbody>
</table>
<div class="totals">${totals}</div>
${terms}
</body>
</html>`;
}

/** Preview and print of a quotation or invoice before it is saved in Odoo. */
export function PaperDraftPreview({ paper, t, onClose }: { paper: DraftPaper; t: Translate; onClose: () => void }) {
  const frame = useRef<HTMLIFrameElement>(null);
  const dir = document.documentElement.dir === "ltr" ? "ltr" : "rtl";
  const html = useMemo(() => draftPaperHtml(paper, t, dir), [paper, t, dir]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  function print() {
    const view = frame.current?.contentWindow;
    if (!view) return;
    view.focus();
    view.print();
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="paper-draft-preview-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg odoo-preview-panel">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="paper-draft-preview-title">{t(copy.paperDraftPreviewTitle)}</h2>
          <div className="odoo-preview-actions">
            <button type="button" className="btn btn-ghost" onClick={print}>
              <Printer size={16} aria-hidden /> {t(copy.paperPrint)}
            </button>
            <button type="button" className="form-dialog-close" onClick={onClose} aria-label={t(copy.cancel)}>
              <X aria-hidden />
            </button>
          </div>
        </div>
        <p className="muted paper-draft-hint">{t(copy.paperDraftPreviewHint)}</p>
        <div className="odoo-preview-body">
          {/* No scripts: the terms are staff HTML. allow-modals lets the frame print. */}
          <iframe ref={frame} className="odoo-preview-frame" sandbox="allow-same-origin allow-modals" srcDoc={html} title={t(copy.paperDraftPreviewTitle)} />
        </div>
      </div>
    </div>
  );
}
