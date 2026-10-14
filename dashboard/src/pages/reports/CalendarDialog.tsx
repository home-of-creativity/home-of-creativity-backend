import { CalendarDays, ShieldCheck, Trash, X } from "lucide-react";
import { useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, type ReportCalendar } from "../../api";
import type { Locale } from "../../i18n";
import { GOOGLE_COLORS } from "../photography/model";

/**
 * Create or edit a reports calendar. The roles ticked here hold its view permission,
 * the same switch the permissions page shows under «تقاويم التقارير».
 */
export function CalendarDialog({
  locale,
  initial,
  roles,
  onClose,
  onSaved,
  onDeleted,
}: {
  locale: Locale;
  initial: ReportCalendar | null;
  roles: Array<{ id: number; name: string }>;
  onClose: () => void;
  onSaved: (calendar: ReportCalendar) => void;
  onDeleted: (id: number) => void;
}) {
  const ar = locale === "ar";
  const [name, setName] = useState(initial?.name ?? "");
  const [color, setColor] = useState<string | null>(initial?.color ?? GOOGLE_COLORS[0].hex);
  const [roleIds, setRoleIds] = useState<number[]>(initial?.role_ids ?? []);
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  function toggleRole(id: number) {
    setRoleIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    try {
      const payload = { name: name.trim(), color, role_ids: roleIds };
      const res = initial ? await api.updateReportCalendar(initial.id, payload) : await api.createReportCalendar(payload);
      toast.success(initial ? (ar ? "حُفظ التقويم." : "Calendar saved.") : ar ? "أُنشئ التقويم وأُضيف إلى الصلاحيات." : "Calendar created and added to permissions.");
      onSaved(res.data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!initial) return;
    setSaving(true);
    try {
      await api.deleteReportCalendar(initial.id);
      toast.success(ar ? "حُذف التقويم." : "Calendar deleted.");
      onDeleted(initial.id);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
      setSaving(false);
    }
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-calendar-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel gcal-dialog">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="rc-calendar-title">
            {initial ? (ar ? "إعدادات التقويم" : "Calendar settings") : ar ? "تقويم جديد" : "New calendar"}
          </h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={ar ? "إغلاق" : "Close"}>
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={submit}>
          <div className="form-dialog-body gcal-form">
            <div className="gcal-line">
              <CalendarDays size={17} aria-hidden />
              <div className="gcal-line-body">
                <input
                  className="field gcal-title-input"
                  placeholder={ar ? "اسم التقويم، مثال: تقارير السوشال ميديا" : "Calendar name, e.g. Social media reports"}
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  autoFocus
                  required
                  maxLength={80}
                />
              </div>
            </div>

            <div className="gcal-line">
              <span className="gcal-dot" style={{ background: color ?? "#f0b56a" }} aria-hidden />
              <div className="gcal-line-body">
                <div className="gcal-swatches" role="radiogroup" aria-label={ar ? "اللون" : "Color"}>
                  {GOOGLE_COLORS.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      role="radio"
                      aria-checked={color === item.hex}
                      className={color === item.hex ? "is-on" : ""}
                      style={{ background: item.hex }}
                      onClick={() => setColor(item.hex)}
                      title={item.name[locale]}
                    />
                  ))}
                </div>
              </div>
            </div>

            <div className="gcal-line">
              <ShieldCheck size={17} aria-hidden />
              <div className="gcal-line-body">
                <strong>{ar ? "من يرى هذا التقويم" : "Who sees this calendar"}</strong>
                {roles.length === 0 ? (
                  <p className="muted gcal-hint">{ar ? "لا أدوار بعد. أنشئ دوراً من صفحة الصلاحيات." : "No roles yet. Create one on the permissions page."}</p>
                ) : (
                  <ul className="rc-role-list">
                    {roles.map((role) => (
                      <li key={role.id}>
                        <label className="checkbox-row">
                          <input type="checkbox" checked={roleIds.includes(role.id)} onChange={() => toggleRole(role.id)} />
                          {role.name}
                        </label>
                      </li>
                    ))}
                  </ul>
                )}
                <p className="muted gcal-hint">
                  {ar
                    ? "الإدارة الكاملة بلا دور ترى كل التقاويم، ودورك يُضاف تلقائياً. نفس الخيار موجود في "
                    : "A full admin with no role sees every calendar, and your own role is added for you. The same switch is on "}
                  <Link className="table-link" to="/permissions">{ar ? "صفحة الصلاحيات" : "the permissions page"}</Link>.
                </p>
              </div>
            </div>

            {initial ? (
              <div className="gcal-line">
                <Trash size={17} aria-hidden />
                <div className="gcal-line-body">
                  {confirmDelete ? (
                    <div className="gcal-pop-confirm">
                      <p>
                        {ar
                          ? `يُحذف التقويم ومواعيده (${initial.deadlines_count}) وتُزال صلاحيته من كل الأدوار.`
                          : `The calendar and its ${initial.deadlines_count} deadlines are deleted, and its permission leaves every role.`}
                      </p>
                      <div className="gcal-row">
                        <button type="button" className="btn btn-danger" disabled={saving} onClick={() => void remove()}>{ar ? "حذف نهائي" : "Delete for good"}</button>
                        <button type="button" className="btn btn-ghost" onClick={() => setConfirmDelete(false)}>{ar ? "إلغاء" : "Cancel"}</button>
                      </div>
                    </div>
                  ) : (
                    <button type="button" className="btn btn-ghost" onClick={() => setConfirmDelete(true)}>{ar ? "حذف التقويم" : "Delete calendar"}</button>
                  )}
                </div>
              </div>
            ) : null}
          </div>
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={saving || !name.trim()}>{ar ? "حفظ" : "Save"}</button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>{ar ? "إلغاء" : "Cancel"}</button>
          </div>
        </form>
      </div>
    </div>
  );
}
