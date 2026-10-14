import { ArrowDown, ArrowUp, Plus, Sparkles, Trash2 } from "lucide-react";
import { useState } from "react";
import { api, type WorkPlanDepartment, type WorkPlanTask } from "../../api";

type Change = "new" | "edited" | "same";
type Pick = { task: WorkPlanTask; change: Change; chosen: boolean };
type Dropped = { task: WorkPlanTask; keep: boolean };

const CHANGE_LABELS: Record<Change, string> = { new: "جديدة", edited: "معدّلة", same: "كما هي" };

function normal(text: string) {
  return text.replace(/\s+/g, " ").trim().toLowerCase();
}

/** The current task a suggestion rewrites: the same title, or one title holding the other. */
function matchFor(suggested: WorkPlanTask, current: WorkPlanTask[], taken: Set<WorkPlanTask>) {
  const title = normal(suggested.title);
  const free = current.filter((task) => !taken.has(task));
  return free.find((task) => normal(task.title) === title)
    ?? free.find((task) => {
      const other = normal(task.title);
      return other.length > 3 && title.length > 3 && (other.includes(title) || title.includes(other));
    })
    ?? null;
}

/**
 * A department's tasks; each is a ClickUp subtask of the department's task once the plan is
 * saved. Gemini can write them from the package features and the request: its list is shown
 * against the current one, and only the ticked tasks are applied.
 */
export function WorkPlanTasks({
  requestId,
  department,
  brief,
  tasks,
  onChange,
}: {
  requestId: number;
  department: WorkPlanDepartment;
  brief: string;
  tasks: WorkPlanTask[];
  onChange: (tasks: WorkPlanTask[]) => void;
}) {
  const [picks, setPicks] = useState<Pick[] | null>(null);
  const [dropped, setDropped] = useState<Dropped[]>([]);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState("");

  function patch(index: number, change: Partial<WorkPlanTask>) {
    onChange(tasks.map((task, position) => (position === index ? { ...task, ...change } : task)));
  }

  function move(index: number, step: number) {
    const target = index + step;
    if (target < 0 || target >= tasks.length) return;
    const next = [...tasks];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  }

  async function suggest() {
    setBusy(true);
    setNote("");
    try {
      const res = await api.suggestWorkPlanTasks(requestId, department, brief, tasks.filter((task) => task.title.trim()));
      const taken = new Set<WorkPlanTask>();
      const next = res.data.tasks.map((task): Pick => {
        const match = matchFor(task, tasks, taken);
        if (match) taken.add(match);
        const change: Change = !match ? "new" : normal(match.title) === normal(task.title) && normal(match.description) === normal(task.description) ? "same" : "edited";
        return { task: { ...task, clickup_task_id: match?.clickup_task_id ?? null }, change, chosen: true };
      });
      setPicks(next);
      setDropped(tasks.filter((task) => !taken.has(task) && task.title.trim()).map((task) => ({ task, keep: false })));
      setNote(res.message ?? "");
    } catch (err) {
      setNote(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  function apply() {
    if (!picks) return;
    onChange([...picks.filter((pick) => pick.chosen).map((pick) => pick.task), ...dropped.filter((row) => row.keep).map((row) => row.task)]);
    setPicks(null);
    setDropped([]);
    setNote("");
  }

  return (
    <div className="work-plan-tasks">
      <div className="work-plan-tasks-head">
        <span className="field-label">المهام <span className="muted">(مهام فرعية في ClickUp)</span></span>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => void suggest()} disabled={busy}>
          <Sparkles size={14} aria-hidden /> {busy ? "Gemini يكتب…" : "اقتراح بـGemini"}
        </button>
      </div>

      {tasks.length === 0 ? <p className="muted">لا مهام بعد. أضف مهمة أو اطلب اقتراح Gemini من مميزات الباقة.</p> : null}
      <ol className="work-plan-task-list">
        {tasks.map((task, index) => (
          <li key={task.clickup_task_id ?? `row-${index}`} className="work-plan-task-item">
            <div className="work-plan-task-fields">
              <input className="field" value={task.title} maxLength={200} onChange={(event) => patch(index, { title: event.target.value })} placeholder="عنوان المهمة" aria-label={`عنوان المهمة ${index + 1}`} />
              <textarea className="field" rows={2} maxLength={4000} value={task.description} onChange={(event) => patch(index, { description: event.target.value })} placeholder="الوصف: ما يُسلَّم، المقاس أو الصيغة، وما يلزم من العميل" aria-label={`وصف المهمة ${index + 1}`} />
            </div>
            <div className="work-plan-task-tools">
              <button type="button" className="btn btn-ghost btn-icon" onClick={() => move(index, -1)} disabled={index === 0} aria-label="تقديم"><ArrowUp size={14} aria-hidden /></button>
              <button type="button" className="btn btn-ghost btn-icon" onClick={() => move(index, 1)} disabled={index === tasks.length - 1} aria-label="تأخير"><ArrowDown size={14} aria-hidden /></button>
              <button type="button" className="btn btn-ghost btn-icon" onClick={() => onChange(tasks.filter((_, position) => position !== index))} aria-label="حذف المهمة"><Trash2 size={14} aria-hidden /></button>
            </div>
          </li>
        ))}
      </ol>
      <button type="button" className="btn btn-ghost btn-sm work-plan-task-add" onClick={() => onChange([...tasks, { title: "", description: "", clickup_task_id: null }])} disabled={tasks.length >= 40}>
        <Plus size={14} aria-hidden /> إضافة مهمة
      </button>

      {note && !picks ? <p className="muted" role="status">{note}</p> : null}
      {picks ? (
        <div className="work-plan-suggestion" role="region" aria-label="اقتراح Gemini">
          <p className="work-plan-suggestion-title"><Sparkles size={14} aria-hidden /> اقتراح Gemini</p>
          {note ? <p className="muted">{note}</p> : null}
          <ul className="work-plan-suggestion-list">
            {picks.map((pick, index) => (
              <li key={index} className={`is-${pick.change}`}>
                <label className="checkbox-row">
                  <input type="checkbox" checked={pick.chosen} onChange={(event) => setPicks((current) => current?.map((row, position) => (position === index ? { ...row, chosen: event.target.checked } : row)) ?? null)} />
                  <span>
                    <span className={`work-plan-change is-${pick.change}`}>{CHANGE_LABELS[pick.change]}</span> <strong>{pick.task.title}</strong>
                    {pick.task.description ? <span className="work-plan-suggestion-desc">{pick.task.description}</span> : null}
                  </span>
                </label>
              </li>
            ))}
            {dropped.map((row, index) => (
              <li key={`drop-${index}`} className="is-dropped">
                <label className="checkbox-row">
                  <input type="checkbox" checked={row.keep} onChange={(event) => setDropped((current) => current.map((item, position) => (position === index ? { ...item, keep: event.target.checked } : item)))} />
                  <span>
                    <span className="work-plan-change is-dropped">{row.keep ? "تبقى" : "ستُحذف"}</span> <strong>{row.task.title}</strong>
                  </span>
                </label>
              </li>
            ))}
          </ul>
          <div className="work-plan-suggestion-actions">
            <button type="button" className="btn btn-primary btn-sm" onClick={apply} disabled={!picks.some((pick) => pick.chosen) && !dropped.some((row) => row.keep)}>تطبيق على المهام</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => void suggest()} disabled={busy}>اقتراح آخر</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setPicks(null); setDropped([]); setNote(""); }}>تجاهل</button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
