import { useEffect, useRef, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { api, type Client, type OdooInvoice, type OdooQuotation, type PageMeta } from "../api";
import { FormDialog } from "../components/FormDialog";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { Pagination } from "../components/Pagination";
import { copy, type Locale } from "../i18n";
import { ClientLogosPanel } from "./portfolio/ClientLogosPanel";

const TELEGRAM_BOT = import.meta.env.VITE_TELEGRAM_BOT ?? "pro_design_perfect_bot";

type Tab = "clients" | "logos" | "quotations" | "invoices";

const tabs: Tab[] = ["clients", "logos", "quotations", "invoices"];

function readTab(value: string | null): Tab {
  return tabs.includes(value as Tab) ? (value as Tab) : "clients";
}

function tabLabel(tab: Tab, t: (c: { ar: string; en: string }) => string) {
  switch (tab) {
    case "logos":
      return t(copy.portfolioTabClients);
    case "quotations":
      return t(copy.odooTabQuotations);
    case "invoices":
      return t(copy.odooTabInvoices);
    default:
      return t(copy.odooTabClients);
  }
}

export function Clients({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = readTab(searchParams.get("tab"));

  function setTab(next: Tab) {
    if (next === "clients") {
      setSearchParams({});
      return;
    }
    setSearchParams({ tab: next });
  }
  const [items, setItems] = useState<Client[]>([]);
  const [quotations, setQuotations] = useState<OdooQuotation[]>([]);
  const [invoices, setInvoices] = useState<OdooInvoice[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [odooReady, setOdooReady] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [companyName, setCompanyName] = useState("");
  const [importingCrm, setImportingCrm] = useState(false);
  const excelInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api.odooStatus().then((res) => setOdooReady(res.data.configured)).catch(() => setOdooReady(false));
  }, []);

  useEffect(() => {
    if (tab === "logos") return;

    let cancelled = false;

    function load(silent = false) {
      if (!silent) setLoading(true);

      const request =
        tab === "quotations"
          ? api.odooQuotations().then((res) => {
              if (cancelled) return;
              setQuotations(res.data);
              setOdooReady(true);
              setError("");
            })
          : tab === "invoices"
            ? api.odooInvoices().then((res) => {
                if (cancelled) return;
                setInvoices(res.data);
                setOdooReady(true);
                setError("");
              })
            : api.clients(page).then((res) => {
                if (cancelled) return;
                setItems(res.data);
                setMeta(res.meta);
                setError("");
              });

      request
        .catch((err) => {
          if (cancelled) return;
          if (tab === "quotations") setQuotations([]);
          if (tab === "invoices") setInvoices([]);
          if (tab === "clients") {
            setItems([]);
            setMeta(null);
          }
          const message = err instanceof Error ? err.message : t(copy.saveFailed);
          if (message.toLowerCase().includes("not configured")) {
            setOdooReady(false);
            setError("");
            return;
          }
          if (!silent) setError(message);
        })
        .finally(() => {
          if (!cancelled && !silent) setLoading(false);
        });
    }

    load();
    const timer = window.setInterval(() => load(true), 15000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [tab, page, t]);

  async function importCrmExcel(file: File) {
    setError("");
    setNotice("");
    setImportingCrm(true);
    try {
      const res = await api.importOdooCrmClientsExcel(file);
      const message = t(copy.odooImportCrmExcelDone)
        .replace("{createdOdoo}", String(res.data.created_in_odoo))
        .replace("{created}", String(res.data.created))
        .replace("{skipped}", String(res.data.skipped));
      setNotice(message);
      setPage(1);
      const list = await api.clients(1);
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setImportingCrm(false);
      if (excelInputRef.current) {
        excelInputRef.current.value = "";
      }
    }
  }

  function resetForm() {
    setEditingId(null);
    setName("");
    setEmail("");
    setPhone("");
    setCompanyName("");
    setShowForm(false);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setName("");
    setEmail("");
    setPhone("");
    setCompanyName("");
    setShowForm(true);
    setError("");
  }

  function startEdit(item: Client) {
    setEditingId(item.id);
    setName(item.name);
    setEmail(item.email ?? "");
    setPhone(item.phone ?? "");
    setCompanyName(item.company_name ?? "");
    setShowForm(true);
    setError("");
  }

  async function saveClient(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    try {
      const payload = {
        name,
        email: email || undefined,
        phone: phone || undefined,
        company_name: companyName || undefined,
      };
      if (editingId) {
        await api.updateClient(editingId, payload);
      } else {
        await api.createClient(payload);
      }
      resetForm();
      setNotice(t(copy.saveClient));
      const list = await api.clients(page);
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function removeClient(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    setNotice("");
    try {
      await api.deleteClient(id);
      if (editingId === id) resetForm();
      setNotice(t(copy.deleted));
      const list = await api.clients(page);
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  return (
    <>
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.clients)}</h1>
          <p className="page-lede">
            {tab === "logos" ? t(copy.clientLogosLede) : t(copy.clientsLede)}
          </p>
        </div>
        {tab === "clients" ? (
          <div className="toolbar">
            {odooReady ? (
              <>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={importingCrm}
                  onClick={() => excelInputRef.current?.click()}
                >
                  {importingCrm ? t(copy.odooImportCrmLoading) : t(copy.odooImportCrmExcel)}
                </button>
                <input
                  ref={excelInputRef}
                  type="file"
                  accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0];
                    if (file) void importCrmExcel(file);
                  }}
                />
              </>
            ) : (
              <span className="muted">{t(copy.odooNotConfigured)}</span>
            )}
            <button type="button" className="btn btn-primary" onClick={startAdd}>
              {t(copy.addClient)}
            </button>
            <a className="btn btn-telegram" href={`https://t.me/${TELEGRAM_BOT}`} target="_blank" rel="noreferrer">
              {t(copy.signupCta)}
            </a>
          </div>
        ) : null}
      </header>

      <div className="tabs" role="tablist" aria-label={t(copy.clients)}>
        {tabs.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            className={tab === key ? "tab is-active" : "tab"}
            onClick={() => {
              setTab(key);
              setError("");
              setShowForm(false);
            }}
          >
            {tabLabel(key, t)}
          </button>
        ))}
      </div>

      {tab === "clients" && showForm ? (
        <FormDialog
          title={editingId ? t(copy.editClient) : t(copy.addClient)}
          onClose={resetForm}
          onSubmit={saveClient}
          submitLabel={t(copy.saveClient)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
        >
          <div className="portfolio-form-grid">
            <label className="field-label">
              {t(copy.client)}
              <input className="field" value={name} onChange={(e) => setName(e.target.value)} required />
            </label>
            <label className="field-label">
              {t(copy.email)}
              <input className="field" type="email" dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} />
            </label>
            <label className="field-label">
              {t(copy.phone)}
              <input className="field" dir="ltr" value={phone} onChange={(e) => setPhone(e.target.value)} />
            </label>
            <label className="field-label">
              {t(copy.company)}
              <input className="field" value={companyName} onChange={(e) => setCompanyName(e.target.value)} />
            </label>
          </div>
        </FormDialog>
      ) : null}

      {tab === "clients" && notice ? <p className="notice">{notice}</p> : null}
      {tab === "clients" && !showForm && error ? <p className="error">{error}</p> : null}

      {tab === "logos" ? <ClientLogosPanel locale={locale} t={t} /> : null}

      {tab === "clients" ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>{t(copy.client)}</th>
                  <th>{t(copy.company)}</th>
                  <th>{t(copy.odooStage)}</th>
                  <th>{t(copy.email)}</th>
                  <th>{t(copy.phone)}</th>
                  <th>{t(copy.telegram)}</th>
                  <th>{t(copy.odooPartner)}</th>
                  <th>{t(copy.requestsCount)}</th>
                  <th>{t(copy.actions)}</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <LoadingTableRow colSpan={9} label={t(copy.loading)} />
                ) : items.length === 0 ? (
                  <tr>
                    <td colSpan={9}>{t(copy.empty)}</td>
                  </tr>
                ) : (
                  items.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <strong className="client-name">{item.name}</strong>
                      </td>
                      <td>{item.company_name ?? "—"}</td>
                      <td>{item.odoo_live?.stage ?? item.odoo_stage_name ?? "—"}</td>
                      <td dir="ltr">{item.email ?? "—"}</td>
                      <td dir="ltr">{item.phone ?? "—"}</td>
                      <td dir="ltr">
                        {item.telegram_user_id ? (
                          <span className="source source-telegram">{item.telegram_user_id}</span>
                        ) : (
                          "—"
                        )}
                      </td>
                      <td dir="ltr">
                        {item.odoo_url ? (
                          <a href={item.odoo_url} target="_blank" rel="noreferrer">
                            {item.odoo_partner_id}
                          </a>
                        ) : (
                          item.odoo_partner_id ?? "—"
                        )}
                      </td>
                      <td>{item.requests_count ?? 0}</td>
                      <td>
                        <div className="row-actions">
                          <button className="btn" type="button" onClick={() => startEdit(item)}>
                            {t(copy.editEmployee)}
                          </button>
                          <button className="btn" type="button" onClick={() => void removeClient(item.id)}>
                            {t(copy.deleteEmployee)}
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          <Pagination meta={meta} disabled={loading} onPage={setPage} t={t} />
        </>
      ) : null}

      {tab === "quotations" ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t(copy.number)}</th>
                <th>{t(copy.client)}</th>
                <th>{t(copy.odooAmount)}</th>
                <th>{t(copy.odooState)}</th>
                <th>{t(copy.odooReference)}</th>
                <th>{t(copy.odooDate)}</th>
                <th>{t(copy.actions)}</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <LoadingTableRow colSpan={7} label={t(copy.loading)} />
              ) : !odooReady ? (
                <tr>
                  <td colSpan={7}>{t(copy.odooNotConfigured)}</td>
                </tr>
              ) : quotations.length === 0 ? (
                <tr>
                  <td colSpan={7}>{t(copy.empty)}</td>
                </tr>
              ) : (
                quotations.map((item) => (
                  <tr key={item.id}>
                    <td dir="ltr">{item.name}</td>
                    <td>{item.partner_name ?? "—"}</td>
                    <td dir="ltr">{item.amount_total}</td>
                    <td>{item.state}</td>
                    <td dir="ltr">{item.client_order_ref ?? item.origin ?? "—"}</td>
                    <td dir="ltr">{item.date_order ?? "—"}</td>
                    <td>
                      <a href={item.odoo_url} target="_blank" rel="noreferrer">
                        {t(copy.openOdoo)}
                      </a>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}

      {tab === "invoices" ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t(copy.number)}</th>
                <th>{t(copy.client)}</th>
                <th>{t(copy.odooAmount)}</th>
                <th>{t(copy.odooState)}</th>
                <th>{t(copy.odooReference)}</th>
                <th>{t(copy.odooDate)}</th>
                <th>{t(copy.actions)}</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <LoadingTableRow colSpan={7} label={t(copy.loading)} />
              ) : !odooReady ? (
                <tr>
                  <td colSpan={7}>{t(copy.odooNotConfigured)}</td>
                </tr>
              ) : invoices.length === 0 ? (
                <tr>
                  <td colSpan={7}>{t(copy.empty)}</td>
                </tr>
              ) : (
                invoices.map((item) => (
                  <tr key={item.id}>
                    <td dir="ltr">{item.name}</td>
                    <td>{item.partner_name ?? "—"}</td>
                    <td dir="ltr">{item.amount_total}</td>
                    <td>
                      {item.state}
                      {item.payment_state ? ` / ${item.payment_state}` : ""}
                    </td>
                    <td dir="ltr">{item.ref ?? item.invoice_origin ?? "—"}</td>
                    <td dir="ltr">{item.invoice_date ?? "—"}</td>
                    <td>
                      <a href={item.odoo_url} target="_blank" rel="noreferrer">
                        {t(copy.openOdoo)}
                      </a>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  );
}
