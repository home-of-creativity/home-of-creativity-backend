import { X } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type CalendarPermissions, type CalendarRoleLevel, type CalendarSource } from "../../api";
import type { Locale } from "../../i18n";

const LEVELS: Array<{ id: CalendarRoleLevel; label: { ar: string; en: string } }> = [
  { id: "none", label: { ar: "لا يراه", en: "No access" } },
  { id: "view", label: { ar: "يرى فقط", en: "View" } },
  { id: "edit", label: { ar: "يرى ويعدّل", en: "View and edit" } },
];

/** Which dashboard roles see and change one Google calendar inside the photography tab. */
export function CalendarPermissionsDialog({
  locale,
  calendar,
  onClose,
  onSaved,
}: {
  locale: Locale;
  calendar: CalendarSource;
  onClose: () => void;
  onSaved: () => void;
}) {
  const ar = locale === "ar";
  const [data, setData] = useState<CalendarPermissions | null>(null);
  const [levels, setLevels] = useState<Record<number, CalendarRoleLevel>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api
      .photographyCalendarPermissions(calendar.id)
      .then((res) => {
        if (cancelled) return;
        setData(res.data);
        setLevels(Object.fromEntries(res.data.roles.map((role) => [role.id, role.level])));
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : String(err)));
    return () => {
      cancelled = true;
    };
  }, [calendar.id]);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  async function save(next: Record<number, CalendarRoleLevel>) {
    setBusy(true);
    try {
      const res = await api.savePhotographyCalendarPermissions(calendar.id, next);
      toast.success(res.message ?? (ar ? "حُفظت الصلاحيات." : "Saved."));
      onSaved();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  const restricted = Object.values(levels).some((level) => level !== "none");

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="gcal-perm-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel gcal-perm">
        <div className="form-dialog-head">
          <div>
            <h2 className="form-dialog-title" id="gcal-perm-title">{ar ? "صلاحيات التقويم" : "Calendar access"}</h2>
            <p className="muted gcal-perm-sub">{calendar.summary}</p>
          </div>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}>
            <X aria-hidden />
          </button>
        </div>
        <div className="form-dialog-body">
          <p className={restricted ? "gcal-perm-state is-limited" : "gcal-perm-state"} role="status">
            {restricted
              ? ar
                ? "التقويم محصور بالأدوار المختارة أدناه. باقي الأدوار لا تراه."
                : "Only the roles chosen below see this calendar."
              : ar
                ? "التقويم مفتوح: كل من يفتح تاب التصوير يراه، ويعدّله من عنده صلاحية «كل المواعيد». اختر أدواراً لحصره بها."
                : "Open: everyone on the photography tab sees it, and staff with “all bookings” edit it. Choose roles to limit it."}
          </p>
          {data === null ? (
            <p className="muted">{ar ? "جارٍ التحميل…" : "Loading…"}</p>
          ) : data.roles.length === 0 ? (
            <p className="muted">{ar ? "لا توجد أدوار بعد. أنشئها من صفحة الصلاحيات." : "No roles yet. Create them on the Permissions page."}</p>
          ) : (
            <table className="gcal-perm-table">
              <thead>
                <tr>
                  <th>{ar ? "الدور" : "Role"}</th>
                  {LEVELS.map((level) => (
                    <th key={level.id}>{level.label[locale]}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.roles.map((role) => (
                  <tr key={role.id}>
                    <td>
                      <strong>{role.name}</strong>
                      {role.full ? <small className="muted">{ar ? "يملك «كل المواعيد»: يرى ويعدّل دائماً" : "Has “all bookings”: always sees and edits"}</small> : null}
                    </td>
                    {LEVELS.map((level) => (
                      <td key={level.id}>
                        <input
                          type="radio"
                          name={`role-${role.id}`}
                          aria-label={`${role.name}: ${level.label[locale]}`}
                          checked={role.full ? level.id === "edit" : (levels[role.id] ?? "none") === level.id}
                          disabled={role.full || busy}
                          onChange={() => setLevels((current) => ({ ...current, [role.id]: level.id }))}
                        />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {!calendar.writable && calendar.access_role !== "owner" && calendar.access_role !== "writer" ? (
            <p className="muted gcal-hint">
              {ar ? "حساب الخدمة يقرأ هذا التقويم فقط في جوجل، فلن يستطيع أحد تعديله حتى تُعطى الصلاحية من جوجل." : "Google gives the service account read access only, so nobody can edit it until that changes in Google."}
            </p>
          ) : null}
        </div>
        <div className="form-dialog-actions">
          <button type="button" className="btn btn-primary" disabled={busy || data === null} onClick={() => void save(levels)}>
            {ar ? "حفظ" : "Save"}
          </button>
          {data?.restricted ? (
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void save({})}>
              {ar ? "افتح التقويم للجميع" : "Open to everyone"}
            </button>
          ) : null}
          <button type="button" className="btn btn-ghost" onClick={onClose}>{ar ? "إلغاء" : "Cancel"}</button>
        </div>
      </div>
    </div>
  );
}
