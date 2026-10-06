import { useEffect, useMemo, useState } from "react";
import { Search } from "lucide-react";
import { Link, useParams } from "react-router-dom";
import { ExternalLink, FileText, FolderOpen, Plus } from "lucide-react";
import { toast } from "sonner";
import { api, type Client, type ClientReport } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { DriveFolderPicker } from "../components/DriveFolderPicker";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

function when(value: string | null | undefined, locale: Locale) {
  if (!value) return "—";
  return new Date(value).toLocaleString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" });
}

export function ClientReports({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const params = useParams();
  const clientId = Number(params.id);
  const [client, setClient] = useState<Client | null>(null);
  const [reports, setReports] = useState<ClientReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [folderOpen, setFolderOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("");

  useEffect(() => {
    setLoading(true);
    api.clientReports(clientId)
      .then((res) => {
        setClient(res.client);
        setReports(res.data);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [clientId, t]);

  async function remove(id: number) {
    try {
      await api.deleteClientReport(id);
      setReports((current) => current.filter((report) => report.id !== id));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  const name = client?.company_name || client?.name || t(copy.clientReports);
  const newReport = `/reports/clients/${clientId}/new`;
  const filteredReports = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return reports.filter((report) => {
      const edited = Boolean(report.published_at && report.updated_at && new Date(report.updated_at).getTime() - new Date(report.published_at).getTime() > 5000);
      if (status === "draft" && report.published_at) return false;
      if (status === "published" && (!report.published_at || edited)) return false;
      if (status === "edited" && !edited) return false;
      if (needle && !report.title.toLowerCase().includes(needle)) return false;
      return true;
    });
  }, [reports, query, status]);

  return (
    <section>
      <PageHeader
        title={name}
        lede={t(copy.clientReports)}
        breadcrumbs={[{ label: t(copy.reportsTitle), to: "/reports" }, { label: name }]}
        actions={(
          <>
            <button type="button" className="btn btn-ghost" onClick={() => setFolderOpen((open) => !open)}>
              <FolderOpen size={16} aria-hidden="true" />{t(copy.driveFolder)}
            </button>
            <Link className="btn btn-primary" to={newReport}><Plus size={16} aria-hidden="true" />{t(copy.addReport)}</Link>
          </>
        )}
      />
      {error ? <p className="error" role="alert">{error}</p> : null}
      {!loading && client && !client.google_drive_folder_id ? <p className="notice">{t(copy.reportNoFolderPublish)}</p> : null}
      {folderOpen && client ? (
        <DriveFolderPicker
          client={client}
          t={t}
          onClose={() => setFolderOpen(false)}
          onSaved={(saved) => {
            setClient(saved);
            setFolderOpen(false);
          }}
        />
      ) : null}

      {!loading && reports.length === 0 && !error ? (
        <div className="empty-state card">
          <FileText size={30} aria-hidden="true" />
          <h2>{t(copy.reportEmptyTitle)}</h2>
          <p>{t(copy.reportEmptyLede)}</p>
          <Link className="btn btn-primary" to={newReport}><Plus size={16} aria-hidden="true" />{t(copy.addReport)}</Link>
        </div>
      ) : (
        <>
        <div className="toolbar filter-bar filter-grid">
          <label className="field-label">
            {t(copy.search)}
            <span className="search-bar">
              <Search size={16} aria-hidden="true" />
              <input type="search" className="field" placeholder={t(copy.searchArticles)} value={query} onChange={(e) => setQuery(e.target.value)} aria-label={t(copy.search)} />
            </span>
          </label>
          <label className="field-label">
            {t(copy.status)}
            <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">{t(copy.all)}</option>
              <option value="draft">{t(copy.reportDraftStatus)}</option>
              <option value="published">{t(copy.reportPublishedStatus)}</option>
              <option value="edited">{t(copy.reportChangedAfterPublish)}</option>
            </select>
          </label>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t(copy.reportTitle)}</th>
                <th>{t(copy.reportLastEdited)}</th>
                <th>{t(copy.status)}</th>
                <th>Drive</th>
                <th><span className="sr-only">{t(copy.actions)}</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? <LoadingTableRow colSpan={5} label={t(copy.loading)} rows={3} /> : null}
              {!loading && filteredReports.length === 0 ? (
                <tr>
                  <td colSpan={5}>{t(copy.noSearchResults)}</td>
                </tr>
              ) : null}
              {filteredReports.map((report) => {
                const edited = report.published_at && report.updated_at && new Date(report.updated_at).getTime() - new Date(report.published_at).getTime() > 5000;
                return (
                  <tr key={report.id}>
                    <td>
                      <Link className="table-link" to={`/reports/${report.id}/edit`}>
                        <FileText size={15} aria-hidden="true" />
                        {report.title}
                      </Link>
                    </td>
                    <td className="muted">{when(report.updated_at ?? report.created_at, locale)}</td>
                    <td>
                      <span className={`status ${report.published_at ? (edited ? "status-revision_requested" : "status-sent") : "status-draft"}`}>
                        {report.published_at ? (edited ? t(copy.reportChangedAfterPublish) : t(copy.reportPublishedStatus)) : t(copy.reportDraftStatus)}
                      </span>
                    </td>
                    <td>
                      <div className="link-row">
                        {report.drive_document_url ? <a href={report.drive_document_url} target="_blank" rel="noreferrer">Word <ExternalLink size={12} aria-hidden="true" /></a> : null}
                        {report.drive_url ? <a href={report.drive_url} target="_blank" rel="noreferrer">PDF <ExternalLink size={12} aria-hidden="true" /></a> : null}
                        {!report.drive_url && !report.drive_document_url ? <span className="muted">—</span> : null}
                      </div>
                    </td>
                    <td className="row-actions-cell">
                      <Link className="btn btn-ghost btn-sm" to={`/reports/${report.id}/edit`}>{t(copy.reportOpen)}</Link>
                      <ConfirmAction
                        label={t(copy.delete)}
                        confirmLabel={t(copy.confirmDelete)}
                        yesLabel={t(copy.delete)}
                        noLabel={t(copy.cancel)}
                        className="btn btn-ghost btn-sm btn-danger"
                        onConfirm={() => void remove(report.id)}
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        </>
      )}
    </section>
  );
}
