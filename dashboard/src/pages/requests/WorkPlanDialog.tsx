import { Camera, ExternalLink, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { api, type ServiceRequest, type WorkPlanDepartment, type WorkPlanEditor, type WorkPlanTask } from "../../api";
import { WorkPlanTasks } from "./WorkPlanTasks";

type Row = {
  department: WorkPlanDepartment;
  label: string;
  included: boolean;
  brief: string;
  tasks: WorkPlanTask[];
  hours: string;
  priority: string;
  employeeId: string;
  task: WorkPlanEditor["departments"][number]["task"];
  employees: WorkPlanEditor["departments"][number]["employees"];
};

const PRIORITIES: Array<[string, string]> = [
  ["1", "عاجلة"],
  ["2", "عالية"],
  ["3", "عادية"],
  ["4", "منخفضة"],
];

/**
 * «تغيير الخطة»: the approved plan by department. Saving updates ClickUp: a kept department's
 * task takes the new tasks, hours, priority, and assignee; an added one gets a task; a removed
 * one has its task closed.
 */
export function WorkPlanDialog({
  requestId,
  onClose,
  onSaved,
}: {
  requestId: number;
  onClose: () => void;
  onSaved: (request: ServiceRequest, message: string) => void;
}) {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [photography, setPhotography] = useState({ enabled: false, sessions: "0", used: 0 });
  const [confirmed, setConfirmed] = useState(false);
  const [features, setFeatures] = useState<string[]>([]);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .workPlan(requestId)
      .then((res) => {
        setConfirmed(res.data.confirmed);
        setFeatures(res.data.package_features ?? []);
        setRows(
          res.data.departments.map((row) => ({
            department: row.department,
            label: row.label,
            included: row.included,
            brief: row.brief,
            tasks: row.tasks ?? [],
            hours: row.hours > 0 ? String(row.hours) : "8",
            priority: String(row.priority || 3),
            employeeId: row.employee_id ? String(row.employee_id) : "",
            task: row.task,
            employees: row.employees,
          })),
        );
        setPhotography({
          enabled: res.data.photography.enabled,
          sessions: String(res.data.photography.sessions || (res.data.photography.enabled ? 1 : 0)),
          used: res.data.photography.used,
        });
      })
      .catch((err) => setError(err instanceof Error ? err.message : String(err)));
  }, [requestId]);

  function patch(department: WorkPlanDepartment, change: Partial<Row>) {
    setRows((current) => current?.map((row) => (row.department === department ? { ...row, ...change } : row)) ?? null);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!rows) return;
    const chosen = rows.filter((row) => row.included);
    const missing = chosen.find((row) => (!row.brief.trim() && !row.tasks.some((task) => task.title.trim())) || !(Number(row.hours) > 0));
    if (missing) {
      setError(`اكتب وصف قسم ${missing.label} أو مهامه، وعدد ساعاته.`);
      return;
    }
    const sessions = photography.enabled ? Math.max(1, Number(photography.sessions) || 0) : 0;
    if (sessions < photography.used) {
      setError(`تمت ${photography.used} جلسة تصوير، فلا يقل العدد عنها.`);
      return;
    }
    setSaving(true);
    setError("");
    try {
      const res = await api.changeWorkPlan(requestId, {
        operations: chosen.map((row) => ({
          department: row.department,
          brief: row.brief.trim(),
          tasks: row.tasks
            .filter((task) => task.title.trim())
            .map((task) => ({ title: task.title.trim(), description: task.description.trim(), clickup_task_id: task.clickup_task_id ?? null })),
          hours: Math.round(Number(row.hours)),
          priority: Number(row.priority),
          employee_id: row.employeeId ? Number(row.employeeId) : null,
        })),
        photography_sessions: sessions,
      });
      onSaved(res.data, res.message ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="work-plan-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel is-lg work-plan-dialog">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="work-plan-title">{confirmed ? "تغيير الخطة" : "خطة العمل"}</h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label="إغلاق">
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={submit}>
          <div className="form-dialog-body">
            <p className="muted">
              {confirmed
                ? "هذه الخطة المعتمدة. عند الحفظ تُحدَّث مهام ClickUp: القسم المُبقى يأخذ المهام والساعات والمسؤول الجديدة، والقسم المضاف تُنشأ له مهمة، والقسم المحذوف تُغلق مهمته."
                : "اختر الأقسام ومهامها. عند الحفظ تُعتمد الخطة وتُنشأ مهام ClickUp."}
            </p>
            {!rows && !error ? <p className="muted">جارٍ التحميل…</p> : null}
            {features.length > 0 ? (
              <details className="work-plan-features">
                <summary>مميزات الباقة ({features.length})</summary>
                <ul>
                  {features.map((feature, index) => <li key={index}>{feature}</li>)}
                </ul>
              </details>
            ) : null}
            <div className="work-plan-rows">
              {(rows ?? []).map((row) => (
                <fieldset key={row.department} className={row.included ? "work-plan-row is-on" : "work-plan-row"}>
                  <legend>
                    <label className="checkbox-row">
                      <input type="checkbox" checked={row.included} onChange={(event) => patch(row.department, { included: event.target.checked })} />
                      <strong>{row.label}</strong>
                    </label>
                    {row.task?.url ? (
                      <a className="work-plan-task" href={row.task.url} target="_blank" rel="noreferrer">
                        مهمة ClickUp{row.task.status ? ` · ${row.task.status}` : ""} <ExternalLink size={12} aria-hidden />
                      </a>
                    ) : null}
                  </legend>
                  {row.included ? (
                    <>
                      <label className="field-label">
                        وصف القسم
                        <textarea className="field" rows={3} value={row.brief} onChange={(event) => patch(row.department, { brief: event.target.value })} placeholder="وصف عام لعمل القسم، يظهر في مهمة ClickUp" />
                      </label>
                      <WorkPlanTasks
                        requestId={requestId}
                        department={row.department}
                        brief={row.brief}
                        tasks={row.tasks}
                        onChange={(tasks) => patch(row.department, { tasks })}
                      />
                      <div className="work-plan-grid">
                        <label className="field-label">
                          الساعات
                          <input className="field" type="number" min={1} max={400} value={row.hours} onChange={(event) => patch(row.department, { hours: event.target.value })} />
                        </label>
                        <label className="field-label">
                          الأولوية
                          <select className="field" value={row.priority} onChange={(event) => patch(row.department, { priority: event.target.value })}>
                            {PRIORITIES.map(([value, label]) => (
                              <option key={value} value={value}>{label}</option>
                            ))}
                          </select>
                        </label>
                        <label className="field-label">
                          المسؤول
                          <select className="field" value={row.employeeId} onChange={(event) => patch(row.department, { employeeId: event.target.value })}>
                            <option value="">بدون تحديد</option>
                            {row.employees.map((employee) => (
                              <option key={employee.id} value={employee.id}>
                                {employee.name}{employee.in_clickup ? "" : " (غير مربوط بـClickUp)"}
                              </option>
                            ))}
                          </select>
                        </label>
                      </div>
                    </>
                  ) : row.task ? (
                    <p className="muted">ستُغلق مهمة هذا القسم في ClickUp عند الحفظ.</p>
                  ) : null}
                </fieldset>
              ))}
              {rows ? (
                <fieldset className={photography.enabled ? "work-plan-row is-on" : "work-plan-row"}>
                  <legend>
                    <label className="checkbox-row">
                      <input type="checkbox" checked={photography.enabled} onChange={(event) => setPhotography((value) => ({ ...value, enabled: event.target.checked, sessions: event.target.checked && Number(value.sessions) < 1 ? "1" : value.sessions }))} />
                      <Camera size={15} aria-hidden /> <strong>تصوير</strong>
                    </label>
                  </legend>
                  {photography.enabled ? (
                    <label className="field-label work-plan-sessions">
                      عدد جلسات التصوير
                      <input className="field" type="number" min={Math.max(1, photography.used)} max={100} value={photography.sessions} onChange={(event) => setPhotography((value) => ({ ...value, sessions: event.target.value }))} />
                      {photography.used > 0 ? <span className="muted">تمت {photography.used}</span> : null}
                    </label>
                  ) : (
                    <p className="muted">لا يتضمن الطلب تصويراً.</p>
                  )}
                </fieldset>
              ) : null}
            </div>
            {error ? <p className="error" role="alert">{error}</p> : null}
          </div>
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={saving || !rows}>{saving ? "جارٍ الحفظ…" : "حفظ الخطة"}</button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * «تمديد المدة»: the whole request or some of its departments, working days, and the reason.
 * The client is told only when the delivery date moves.
 */
export function ExtendScheduleDialog({
  requestId,
  onClose,
  onSaved,
}: {
  requestId: number;
  onClose: () => void;
  onSaved: (request: ServiceRequest, message: string) => void;
}) {
  const [days, setDays] = useState("1");
  const [reason, setReason] = useState("");
  const [departments, setDepartments] = useState<Array<{ department: WorkPlanDepartment; label: string }>>([]);
  const [scope, setScope] = useState<"all" | "some">("all");
  const [chosen, setChosen] = useState<WorkPlanDepartment[]>([]);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .workPlan(requestId)
      .then((res) => setDepartments(res.data.departments.filter((row) => row.included).map(({ department, label }) => ({ department, label }))))
      .catch(() => setDepartments([]));
  }, [requestId]);

  function toggle(department: WorkPlanDepartment, on: boolean) {
    setChosen((current) => (on ? [...current.filter((item) => item !== department), department] : current.filter((item) => item !== department)));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!reason.trim()) {
      setError("اكتب سبب التمديد، فهو يصل الزبون.");
      return;
    }
    if (scope === "some" && chosen.length === 0) {
      setError("اختر قسماً واحداً على الأقل.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const res = await api.extendSchedule(requestId, Math.max(1, Math.round(Number(days) || 1)), reason.trim(), scope === "some" ? chosen : null);
      onSaved(res.data, res.message ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="extend-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div className="form-dialog-panel">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="extend-title">تمديد المدة</h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label="إغلاق">
            <X aria-hidden />
          </button>
        </div>
        <form className="form-dialog-form" onSubmit={submit}>
          <div className="form-dialog-body">
            <p className="muted">
              {scope === "all"
                ? "تتأخر مواعيد كل مهام الطلب في ClickUp بنفس المدة، ويصل الزبون موعد التسليم الجديد مع السبب."
                : "تتأخر مهام الأقسام المختارة فقط. يتغير موعد التسليم للزبون ويُبلَّغ فقط إذا صار موعد القسم بعده."}
            </p>
            {departments.length > 1 ? (
              <fieldset className="extend-scope">
                <legend className="field-label">الأقسام</legend>
                <label className="checkbox-row">
                  <input type="radio" name="extend-scope" checked={scope === "all"} onChange={() => setScope("all")} />
                  كل أقسام الطلب
                </label>
                <label className="checkbox-row">
                  <input type="radio" name="extend-scope" checked={scope === "some"} onChange={() => setScope("some")} />
                  أقسام محددة
                </label>
                {scope === "some" ? (
                  <div className="extend-scope-list">
                    {departments.map((row) => (
                      <label key={row.department} className="checkbox-row">
                        <input type="checkbox" checked={chosen.includes(row.department)} onChange={(event) => toggle(row.department, event.target.checked)} />
                        {row.label}
                      </label>
                    ))}
                  </div>
                ) : null}
              </fieldset>
            ) : null}
            <label className="field-label">
              عدد أيام العمل
              <input className="field" type="number" min={1} max={60} value={days} onChange={(event) => setDays(event.target.value)} required />
            </label>
            <label className="field-label">
              السبب
              <textarea className="field" rows={3} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="مثال: ضغط موسم الأعياد" required />
            </label>
            {error ? <p className="error" role="alert">{error}</p> : null}
          </div>
          <div className="form-dialog-actions">
            <button type="submit" className="btn btn-primary" disabled={saving}>{saving ? "جارٍ التمديد…" : "تمديد"}</button>
            <button type="button" className="btn btn-ghost" onClick={onClose}>إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  );
}
