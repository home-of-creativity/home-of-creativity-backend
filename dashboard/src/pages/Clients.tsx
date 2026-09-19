import { useEffect, useRef, useState } from "react";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { Link, useSearchParams } from "react-router-dom";
import { api, type Client, type OdooInvoice, type OdooQuotation, type PageMeta } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { Tabs } from "../components/Tabs";
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
  const [importingCrm, setImportingCrm] = useState(false);
  const excelInputRef = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedQuery(query.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [query]);

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
            : api.clients(page, debouncedQuery).then((res) => {
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
    const timer = window.setInterval(() => load(true), 30000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [tab, page, debouncedQuery, t]);

  useEffect(() => {
    setPage(1);
  }, [debouncedQuery]);

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

  async function removeClient(id: number) {
    setError("");
    setNotice("");
    try {
      await api.deleteClient(id);
      setNotice(t(copy.deleted));
      toast.success(t(copy.deleteSuccess));
      const list = await api.clients(page, debouncedQuery);
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
    }
  }

  return (
    <>
      <PageHeader
        eyebrow={t(copy.brandMark)}
        title={t(copy.clients)}
        lede={tab === "logos" ? t(copy.clientLogosLede) : t(copy.clientsLede)}
        actions={
          tab === "clients" ? (
            <>
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
              <Link className="btn btn-primary" to="/clients/new">
                {t(copy.addClient)}
              </Link>
              <a className="btn btn-telegram" href={`https://t.me/${TELEGRAM_BOT}`} target="_blank" rel="noreferrer">
                {t(copy.signupCta)}
              </a>
            </>
          ) : undefined
        }
      />

      <Tabs
        value={tab}
        onValueChange={(next) => {
          setTab(next as Tab);
          setError("");
        }}
        ariaLabel={t(copy.clients)}
        items={tabs.map((key) => ({ value: key, label: tabLabel(key, t) }))}
      />

      {tab === "clients" && notice ? <p className="notice">{notice}</p> : null}
      {tab === "clients" && error ? <p className="error">{error}</p> : null}

      {tab === "logos" ? <ClientLogosPanel locale={locale} t={t} /> : null}

      {tab === "clients" ? (
        <>
          <div className="search-bar">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              className="field"
              placeholder={t(copy.searchClients)}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              aria-label={t(copy.search)}
            />
          </div>

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
                    <td colSpan={9}>{debouncedQuery ? t(copy.noSearchResults) : t(copy.empty)}</td>
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
                        {item.telegram_url ? (
                          <a className="source source-telegram" href={item.telegram_url} rel="noreferrer">
                            {t(copy.contactTelegram)}
                          </a>
                        ) : item.telegram_user_id ? (
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
                      <td className="actions-cell">
                        <Link className="btn btn-ghost" to={`/clients/${item.id}/edit`}>
                          {t(copy.editEmployee)}
                        </Link>
                        <ConfirmAction
                          label={t(copy.deleteEmployee)}
                          yesLabel={t(copy.delete)}
                          noLabel={t(copy.cancel)}
                          onConfirm={() => void removeClient(item.id)}
                        />
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
