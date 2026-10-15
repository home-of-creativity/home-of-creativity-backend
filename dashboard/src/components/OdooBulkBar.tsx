import { ConfirmAction } from "./ConfirmAction";
import { copy } from "../i18n";

export type BulkStep = "cancel" | "draft" | "delete";

export type BulkAction = { step: BulkStep; label: string; ids: number[] };

function fill(template: string, values: Record<string, string | number>) {
  return template.replace(/\{(\w+)\}/g, (_, key: string) => String(values[key] ?? ""));
}

/** Actions on the checked Odoo rows. Each button counts only the rows its state allows. */
export function OdooBulkBar({
  selected,
  actions,
  busy,
  t,
  onRun,
  onClear,
}: {
  selected: number;
  actions: BulkAction[];
  busy: boolean;
  t: (c: { ar: string; en: string }) => string;
  onRun: (step: BulkStep, ids: number[]) => void;
  onClear: () => void;
}) {
  if (selected === 0) return null;

  return (
    <div className="bulk-bar" role="toolbar" aria-label={fill(t(copy.bulkSelected), { n: selected })}>
      <span className="bulk-bar-count">{fill(t(copy.bulkSelected), { n: selected })}</span>
      {actions.map((action) => (
        <ConfirmAction
          key={action.step}
          label={`${action.label} (${action.ids.length})`}
          confirmLabel={fill(t(copy.bulkConfirm), { n: action.ids.length })}
          yesLabel={action.label}
          noLabel={t(copy.cancel)}
          className={`btn btn-ghost btn-sm${action.step === "draft" ? "" : " btn-danger"}`}
          disabled={busy || action.ids.length === 0}
          onConfirm={() => onRun(action.step, action.ids)}
        />
      ))}
      <button type="button" className="btn btn-ghost btn-sm" onClick={onClear} disabled={busy}>
        {t(copy.bulkClear)}
      </button>
    </div>
  );
}
