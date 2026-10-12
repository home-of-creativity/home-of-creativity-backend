import { CloudUpload, Download, FilePlus, FileText, Paperclip, Pencil, RotateCcw, Search, Trash } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api, type ClientReport, type ReportVersion, type ReportVersionEvent } from "../../api";
import { ConfirmAction } from "../../components/ConfirmAction";
import type { Locale } from "../../i18n";
import { useCan } from "../../lib/useCan";
import { EVENT_COLORS, EVENT_COPY, clientLabel, clock, dayKey, dayTitle, fileSize, saveBlob, stamp } from "./model";

const ICONS: Record<ReportVersionEvent, typeof Pencil> = {
  created: FilePlus,
  edited: Pencil,
  published: CloudUpload,
  restored: RotateCcw,
  attachment_added: Paperclip,
  attachment_removed: Paperclip,
  deleted: Trash,
};

const EVENTS = Object.keys(EVENT_COPY) as ReportVersionEvent[];

/**
 * The version history of one report or of every report of a client (or all clients):
 * editing sessions, publishes, restores, attachment changes, and deletes. Copies that
 * still hold their Word file can be downloaded or restored.
 */
export function ReportHistory({
  locale,
  clientId,
  reportId,
  reloadKey = 0,
  onRestored,
}: {
  locale: Locale;
  clientId?: number;
  reportId?: number;
  reloadKey?: number;
  onRestored?: (report: ClientReport) => void;
}) {
  const ar = locale === "ar";
  const mayRestore = useCan()("ops.reports.update");
  const [rows, setRows] = useState<ReportVersion[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [event, setEvent] = useState("");
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [busy, setBusy] = useState(0);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearch(query.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [query]);

  const load = useCallback(
    (nextPage: number) => {
      setLoading(true);
      setError("");
      return api
        .reportHistory({ page: nextPage, client_id: clientId, report_id: reportId, event, search, per_page: 40 })
        .then((res) => {
          setRows((current) => (nextPage === 1 ? res.data : [...current, ...res.data]));
          setPage(res.meta.current_page);
          setLastPage(res.meta.last_page);
          setTotal(res.meta.total);
        })
        .catch((err) => setError(err instanceof Error ? err.message : String(err)))
        .finally(() => setLoading(false));
    },
    [clientId, reportId, event, search],
  );

  useEffect(() => {
    void load(1);
  }, [load, reloadKey, refresh]);

  const days = useMemo(() => {
    const groups: Array<{ key: string; rows: ReportVersion[] }> = [];
    for (const row of rows) {
      const key = dayKey(row.ended_at ?? row.started_at ?? new Date().toISOString());
      const last = groups[groups.length - 1];
      if (last?.key === key) last.rows.push(row);
      else groups.push({ key, rows: [row] });
    }
    return groups;
  }, [rows]);

  async function download(row: ReportVersion) {
    setBusy(row.id);
    try {
      const blob = await api.reportVersionFile(row.id);
      saveBlob(blob, `${row.title} (${stamp(row.ended_at, "en").replace(/[/:,]/g, "-")}).docx`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(0);
    }
  }

  async function restore(row: ReportVersion) {
    setBusy(row.id);
    try {
      const res = await api.restoreReportVersion(row.id);
      toast.success(ar ? "استُرجعت النسخة وأصبحت الملف الحالي." : "The version was restored and is now the current file.");
      onRestored?.(res.data);
      setRefresh((value) => value + 1);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(0);
    }
  }

  return (
    <div className="rh">
      <div className="toolbar filter-bar filter-grid rh-filters">
        {reportId == null ? (
          <label className="field-label">
            {ar ? "بحث" : "Search"}
            <span className="search-bar">
              <Search size={16} aria-hidden="true" />
              <input type="search" className="field" placeholder={ar ? "عنوان التقرير، الموظف، أو الزبون" : "Report title, staff member, or client"} value={query} onChange={(e) => setQuery(e.target.value)} />
            </span>
          </label>
        ) : null}
        <label className="field-label">
          {ar ? "نوع التغيير" : "Change"}
          <select className="field" value={event} onChange={(e) => setEvent(e.target.value)}>
            <option value="">{ar ? "الكل" : "All"}</option>
            {EVENTS.map((item) => (
              <option key={item} value={item}>{EVENT_COPY[item][locale]}</option>
            ))}
          </select>
        </label>
      </div>

      {error ? <p className="error" role="alert">{error}</p> : null}
      {!loading && rows.length === 0 && !error ? (
        <div className="empty-state card">
          <FileText size={28} aria-hidden="true" />
          <h2>{ar ? "لا يوجد سجل بعد" : "No history yet"}</h2>
          <p>{ar ? "يظهر هنا كل تعديل ونشر واسترجاع بعد أول حفظ." : "Every edit, publish, and restore shows here after the first save."}</p>
        </div>
      ) : null}

      {days.map((day) => (
        <section key={day.key} className="rh-day">
          <h3 className="rh-day-title">{dayTitle(day.key, locale)}</h3>
          <ol className="rh-list">
            {day.rows.map((row) => {
              const Icon = ICONS[row.event];
              const session = row.event === "created" || row.event === "edited";
              const from = clock(row.started_at, locale);
              const to = clock(row.ended_at, locale);
              const canRestore = mayRestore && row.has_document && row.report_id != null;
              return (
                <li key={row.id} className="rh-item">
                  <span className="rh-icon" style={{ background: EVENT_COLORS[row.event] }} aria-hidden>
                    <Icon size={15} />
                  </span>
                  <div className="rh-body">
                    <p className="rh-line">
                      <strong>{row.user?.name ?? (ar ? "النظام" : "System")}</strong>
                      {" "}
                      <span>{EVENT_COPY[row.event][locale]}</span>
                      {reportId == null ? (
                        <>
                          {" · "}
                          {row.report_id != null ? <Link className="table-link" to={`/reports/${row.report_id}/edit`}>{row.report_title ?? row.title}</Link> : <span className="muted">{row.title}</span>}
                        </>
                      ) : null}
                    </p>
                    <p className="rh-meta muted">
                      <span dir="ltr">{session && from !== to ? `${from} – ${to}` : to}</span>
                      {session && row.saves > 1 ? <span>{ar ? `${row.saves} حفظاً تلقائياً` : `${row.saves} autosaves`}</span> : null}
                      {clientId == null && row.client ? <span>{clientLabel(row.client)}</span> : null}
                      {row.title !== (row.report_title ?? row.title) ? <span>{ar ? `العنوان وقتها: ${row.title}` : `Title then: ${row.title}`}</span> : null}
                      {row.size ? <span dir="ltr">{fileSize(row.size)}</span> : null}
                    </p>
                    {row.detail?.files?.length ? <p className="rh-files">{row.detail.files.join("، ")}</p> : null}
                    {row.event === "restored" && row.detail?.from_at ? (
                      <p className="rh-files">{ar ? `من نسخة ${stamp(row.detail.from_at, locale)}` : `From the ${stamp(row.detail.from_at, locale)} version`}</p>
                    ) : null}
                    {row.event === "published" && row.detail?.drive_document_url ? (
                      <a className="rh-files" href={row.detail.drive_document_url} target="_blank" rel="noreferrer">{ar ? "ملف Word على Drive" : "Word file on Drive"}</a>
                    ) : null}
                  </div>
                  <div className="rh-actions">
                    {row.has_document ? (
                      <button type="button" className="btn btn-ghost btn-sm" disabled={busy === row.id} onClick={() => void download(row)}>
                        <Download size={14} aria-hidden /> {ar ? "تنزيل" : "Download"}
                      </button>
                    ) : null}
                    {canRestore ? (
                      <ConfirmAction
                        label={ar ? "استرجاع" : "Restore"}
                        confirmLabel={ar ? "يصبح هذا الملف الحالي" : "Make this the current file"}
                        yesLabel={ar ? "استرجاع" : "Restore"}
                        noLabel={ar ? "إلغاء" : "Cancel"}
                        className="btn btn-ghost btn-sm"
                        disabled={busy === row.id}
                        onConfirm={() => void restore(row)}
                      />
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ol>
        </section>
      ))}

      {loading ? <p className="muted rh-loading">{ar ? "جارٍ التحميل…" : "Loading…"}</p> : null}
      {!loading && page < lastPage ? (
        <button type="button" className="btn btn-ghost rh-more" onClick={() => void load(page + 1)}>
          {ar ? `عرض المزيد (${total - rows.length})` : `Show more (${total - rows.length})`}
        </button>
      ) : null}
    </div>
  );
}
