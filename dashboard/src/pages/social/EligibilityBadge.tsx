import { CircleAlert, CircleCheck } from "lucide-react";

export function EligibilityBadge({ ok, label }: { ok: boolean; label: string }) {
  return (
    <span className={ok ? "eligibility-badge is-ok" : "eligibility-badge"}>
      {ok ? <CircleCheck size={12} aria-hidden="true" /> : <CircleAlert size={12} aria-hidden="true" />}
      {label}
    </span>
  );
}
