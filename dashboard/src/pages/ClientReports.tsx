import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { api, type Client, type ClientReport } from "../api";
import { DriveFolderPicker } from "../components/DriveFolderPicker";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

export function ClientReports({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const params = useParams();
  const clientId = Number(params.id);
  const [client, setClient] = useState<Client | null>(null);
  const [reports, setReports] = useState<ClientReport[]>([]);
  const [error, setError] = useState("");
  const [folderOpen, setFolderOpen] = useState(false);

  useEffect(() => {
    api.clientReports(clientId)
      .then((res) => {
        setClient(res.client);
        setReports(res.data);
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [clientId, t]);

  return (
    <section>
      <PageHeader
        title={client?.company_name || client?.name || t(copy.clientReports)}
        lede={t(copy.clientReports)}
      />
      {error ? <p className="error">{error}</p> : null}
      {!client?.google_drive_folder_id ? <p className="error">{t(copy.reportNoFolder)}</p> : null}
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
      <div className="row-actions">
        <button type="button" className="btn" onClick={() => setFolderOpen(true)}>{t(copy.driveFolder)}</button>
        <Link className="btn btn-primary" to={`/reports/clients/${clientId}/new`}>{t(copy.addReport)}</Link>
        <Link className="btn" to="/reports">{t(copy.reportsTitle)}</Link>
      </div>
      <ul className="plain-list card">
        {reports.length === 0 ? <li>{t(copy.noReports)}</li> : null}
        {reports.map((report) => (
          <li key={report.id}>
            <Link to={`/reports/${report.id}/edit`}>{report.title}</Link>
            {report.drive_url ? (
              <a href={report.drive_url} target="_blank" rel="noreferrer"> Drive</a>
            ) : null}
          </li>
        ))}
      </ul>
    </section>
  );
}
