import { useEffect, useMemo, useRef, useState } from "react";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { Link, useSearchParams } from "react-router-dom";
import { api, type Client, type OdooInvoice, type OdooQuotation, type PageMeta } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { DriveFolderPicker } from "../components/DriveFolderPicker";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { OdooPaperForm } from "../components/OdooPaperForm";
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

function formatMoney(amount: number, currency: string | null): string {
  const formatted = amount.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return currency ? `${formatted} ${currency}` : formatted;
}

function formatOdooWhen(value: string | null, locale: Locale): string {
  if (!value) return "—";
  const tag = locale === "ar" ? "ar-SY-u-nu-latn" : "en-GB";
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  const date = dateOnly
    ? new Date(Date.UTC(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]), 12))
    : new Date(`${value.replace(" ", "T")}Z`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString(tag, dateOnly
    ? { dateStyle: "medium", timeZone: "Asia/Damascus" }
    : { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Damascus" });
}

function partnerLines(name: string | null): { title: string; detail: string | null } {
  if (!name) return { title: "—", detail: null };
  const parts = name.split(/,\s*/, 2);
  if (parts.length === 2 && parts[0] && parts[1]) return { title: parts[0], detail: parts[1] };
  return { title: name, detail: null };
}

function odooStatusClass(state: string): string {
  if (state === "sale" || state === "posted" || state === "paid") return "status status-sale";
  if (state === "sent" || state === "in_payment") return "status status-sent";
  if (state === "cancel") return "status status-cancel";
  if (state === "partial") return "status status-partial";
  return "status status-draft";
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
  const partnerFilter = searchParams.get("partner") ?? "";
  const clientLabel = searchParams.get("client") ?? "";
  const papersUnlinked = searchParams.get("unlinked") === "1";

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
  const [odooFilter, setOdooFilter] = useState("");
  const [companyFilter, setCompanyFilter] = useState("");
  const [phoneFilter, setPhoneFilter] = useState("");
  const [channelFilter, setChannelFilter] = useState("");
  const [stageFilter, setStageFilter] = useState("");
  const [driveFilter, setDriveFilter] = useState("");
  const [stages, setStages] = useState<string[]>([]);
  const [quoteQuery, setQuoteQuery] = useState("");
  const [quoteState, setQuoteState] = useState("");
  const [invoiceQuery, setInvoiceQuery] = useState("");
  const [invoiceState, setInvoiceState] = useState("");
  const [invoicePayment, setInvoicePayment] = useState("");
  const [folderClient, setFolderClient] = useState<Client | null>(null);
  const [paper, setPaper] = useState<null | { kind: "quotation" | "invoice"; quotation?: OdooQuotation }>(null);
  const [refresh, setRefresh] = useState(0);
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
        papersUnlinked && (tab === "quotations" || tab === "invoices")
          ? Promise.resolve().then(() => {
              if (cancelled) return;
              if (tab === "quotations") setQuotations([]);
              if (tab === "invoices") setInvoices([]);
              setOdooReady(true);
              setError("");
            })
          : tab === "quotations"
          ? api.odooQuotations(partnerFilter || undefined).then((res) => {
              if (cancelled) return;
              setQuotations(res.data);
              setOdooReady(true);
              setError("");
            })
          : tab === "invoices"
            ? api.odooInvoices(partnerFilter || undefined).then((res) => {
                if (cancelled) return;
                setInvoices(res.data);
                setOdooReady(true);
                setError("");
              })
            : api.clients(page, {
                search: debouncedQuery,
                odoo: odooFilter,
                company: companyFilter,
                phone: phoneFilter,
                channel: channelFilter,
                stage: stageFilter,
                drive: driveFilter,
              }).then((res) => {
                if (cancelled) return;
                setItems(res.data);
                setMeta(res.meta);
                setStages(res.stages ?? []);
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
  }, [tab, page, debouncedQuery, odooFilter, companyFilter, phoneFilter, channelFilter, stageFilter, driveFilter, partnerFilter, papersUnlinked, refresh, t]);

  useEffect(() => {
    setPage(1);
  }, [debouncedQuery, odooFilter, companyFilter, phoneFilter, channelFilter, stageFilter, driveFilter]);

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
      const list = await api.clients(page, {
        search: debouncedQuery,
        odoo: odooFilter,
        company: companyFilter,
        phone: phoneFilter,
        channel: channelFilter,
        stage: stageFilter,
        drive: driveFilter,
      });
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
    }
  }

  const clientFiltersActive = Boolean(debouncedQuery || odooFilter || companyFilter || phoneFilter || channelFilter || stageFilter || driveFilter);

  const filteredQuotations = useMemo(() => {
    const needle = quoteQuery.trim().toLowerCase();
    return quotations.filter((item) => {
      if (quoteState && item.state !== quoteState) return false;
      if (!needle) return true;
      return [item.name, item.partner_name ?? "", item.client_order_ref ?? "", item.origin ?? ""].join(" ").toLowerCase().includes(needle);
    });
  }, [quotations, quoteQuery, quoteState]);

  const filteredInvoices = useMemo(() => {
    const needle = invoiceQuery.trim().toLowerCase();
    return invoices.filter((item) => {
      if (invoiceState && item.state !== invoiceState) return false;
      if (invoicePayment && item.payment_state !== invoicePayment) return false;
      if (!needle) return true;
      return [item.name, item.partner_name ?? "", item.invoice_origin ?? "", item.ref ?? ""].join(" ").toLowerCase().includes(needle);
    });
  }, [invoices, invoiceQuery, invoiceState, invoicePayment]);

  const quoteStateLabel: Record<string, { ar: string; en: string }> = {
    draft: copy.quoteStateDraft,
    sent: copy.quoteStateSent,
    sale: copy.quoteStateSale,
    posted: copy.quoteStatePosted,
    cancel: copy.quoteStateCancel,
  };
  const paymentLabel: Record<string, { ar: string; en: string }> = {
    not_paid: copy.payNotPaid,
    partial: copy.payPartial,
    paid: copy.payPaid,
    in_payment: copy.payInPayment,
  };

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
          ) : tab === "quotations" && odooReady ? (
            <button type="button" className="btn btn-primary" onClick={() => setPaper({ kind: "quotation" })}>
              {t(copy.createQuotation)}
            </button>
          ) : tab === "invoices" && odooReady ? (
            <button type="button" className="btn btn-primary" onClick={() => setPaper({ kind: "invoice" })}>
              {t(copy.createInvoice)}
            </button>
          ) : undefined
        }
      />

      <Tabs
        value={tab}
        onValueChange={(next) => {
          setTab(next as Tab);
          setPaper(null);
          setError("");
        }}
        ariaLabel={t(copy.clients)}
        items={tabs.map((key) => ({ value: key, label: tabLabel(key, t) }))}
      />

      {tab !== "logos" && notice ? <p className="notice">{notice}</p> : null}
      {tab !== "logos" && error ? <p className="error">{error}</p> : null}
      {folderClient ? (
        <DriveFolderPicker
          client={folderClient}
          t={t}
          onClose={() => setFolderClient(null)}
          onSaved={(saved) => {
            setItems((current) => current.map((item) => (item.id === saved.id ? saved : item)));
            setFolderClient(null);
          }}
        />
      ) : null}

      {tab === "logos" ? <ClientLogosPanel locale={locale} t={t} /> : null}

      {tab === "clients" ? (
        <>
          <div className="toolbar filter-bar filter-grid">
            <label className="field-label">
              {t(copy.search)}
              <span className="search-bar">
                <Search size={16} aria-hidden="true" />
                <input
                  type="search"
                  className="field"
                  placeholder={t(copy.searchClients)}
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  aria-label={t(copy.search)}
                />
              </span>
            </label>
            <label className="field-label">
              {t(copy.filterOdoo)}
              <select className="field" value={odooFilter} onChange={(e) => setOdooFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="linked">{t(copy.filterLinked)}</option>
                <option value="unlinked">{t(copy.filterUnlinked)}</option>
              </select>
            </label>
            <label className="field-label">
              {t(copy.filterStage)}
              <select className="field" value={stageFilter} onChange={(e) => setStageFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                {stages.map((stage) => (
                  <option key={stage} value={stage}>{stage}</option>
                ))}
              </select>
            </label>
            <label className="field-label">
              {t(copy.filterCompany)}
              <select className="field" value={companyFilter} onChange={(e) => setCompanyFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="yes">{t(copy.filterHas)}</option>
                <option value="no">{t(copy.filterMissing)}</option>
              </select>
            </label>
            <label className="field-label">
              {t(copy.filterPhone)}
              <select className="field" value={phoneFilter} onChange={(e) => setPhoneFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="yes">{t(copy.filterHas)}</option>
                <option value="no">{t(copy.filterMissing)}</option>
              </select>
            </label>
            <label className="field-label">
              {t(copy.filterChannel)}
              <select className="field" value={channelFilter} onChange={(e) => setChannelFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="telegram">{t(copy.contactTelegram)}</option>
                <option value="whatsapp">{t(copy.contactWhatsapp)}</option>
                <option value="none">{t(copy.filterNoChannel)}</option>
              </select>
            </label>
            <label className="field-label">
              {t(copy.filterDrive)}
              <select className="field" value={driveFilter} onChange={(e) => setDriveFilter(e.target.value)}>
                <option value="">{t(copy.all)}</option>
                <option value="yes">{t(copy.filterHas)}</option>
                <option value="no">{t(copy.filterMissing)}</option>
              </select>
            </label>
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
                    <td colSpan={9}>{clientFiltersActive ? t(copy.noSearchResults) : t(copy.empty)}</td>
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
                          <a
                            className={item.channel === "whatsapp" ? "source source-whatsapp" : "source source-telegram"}
                            href={item.telegram_url}
                            rel="noreferrer"
                          >
                            {t(item.channel === "whatsapp" ? copy.contactWhatsapp : copy.contactTelegram)}
                          </a>
                        ) : item.telegram_user_id ? (
                          <span className={item.channel === "whatsapp" ? "source source-whatsapp" : "source source-telegram"}>
                            {item.telegram_user_id}
                          </span>
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
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => {
                            const params: Record<string, string> = { tab: "quotations", client: item.company_name?.trim() || item.name };
                            if (item.odoo_partner_id) params.partner = item.odoo_partner_id;
                            else params.unlinked = "1";
                            setSearchParams(params);
                          }}
                        >
                          {t(copy.clientPaperQuotations)}
                        </button>
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => {
                            const params: Record<string, string> = { tab: "invoices", client: item.company_name?.trim() || item.name };
                            if (item.odoo_partner_id) params.partner = item.odoo_partner_id;
                            else params.unlinked = "1";
                            setSearchParams(params);
                          }}
                        >
                          {t(copy.clientPaperInvoices)}
                        </button>
                        <Link className="btn btn-ghost" to={`/reports/clients/${item.id}`}>{t(copy.reportsTitle)}</Link>
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => setFolderClient(item)}
                        >
                          {t(copy.driveFolder)}
                        </button>
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
        <>
        {paper?.kind === "quotation" ? (
          <OdooPaperForm
            kind="quotation"
            partnerId={partnerFilter || undefined}
            t={t}
            onCancel={() => setPaper(null)}
            onCreated={(message) => {
              setNotice(message);
              toast.success(message);
              setPaper(null);
              setRefresh((value) => value + 1);
            }}
          />
        ) : null}
        <div className="toolbar filter-bar filter-grid">
          <label className="field-label">
            {t(copy.search)}
            <span className="search-bar">
              <Search size={16} aria-hidden="true" />
              <input type="search" className="field" placeholder={t(copy.searchQuotations)} value={quoteQuery} onChange={(e) => setQuoteQuery(e.target.value)} aria-label={t(copy.search)} />
            </span>
          </label>
          <label className="field-label">
            {t(copy.odooState)}
            <select className="field" value={quoteState} onChange={(e) => setQuoteState(e.target.value)}>
              <option value="">{t(copy.all)}</option>
              {["draft", "sent", "sale", "cancel"].map((state) => (
                <option key={state} value={state}>{t(quoteStateLabel[state])}</option>
              ))}
            </select>
          </label>
        </div>
        {partnerFilter || papersUnlinked ? (
          <p className="papers-for">
            <span>{papersUnlinked ? t(copy.clientPapersUnlinked) : t(copy.papersFor).replace("{kind}", t(copy.odooTabQuotations)).replace("{client}", clientLabel || "—")}</span>
            <button type="button" className="btn btn-ghost" onClick={() => setTab("quotations")}>{t(copy.showAllPapers)}</button>
          </p>
        ) : null}
        <div className="table-wrap">
          <table className="odoo-table">
            <thead>
              <tr>
                <th>{t(copy.number)}</th>
                <th>{t(copy.client)}</th>
                <th className="money">{t(copy.odooAmount)}</th>
                <th>{t(copy.odooState)}</th>
                <th>{t(copy.odooReference)}</th>
                <th>{t(copy.odooDate)}</th>
                <th>{t(copy.createInvoice)}</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <LoadingTableRow colSpan={7} label={t(copy.loading)} />
              ) : !odooReady ? (
                <tr>
                  <td colSpan={7}>{t(copy.odooNotConfigured)}</td>
                </tr>
              ) : filteredQuotations.length === 0 ? (
                <tr>
                  <td colSpan={7}>{quoteQuery || quoteState ? t(copy.noSearchResults) : t(copy.empty)}</td>
                </tr>
              ) : (
                filteredQuotations.map((item) => {
                  const partner = partnerLines(item.partner_name);
                  return (
                  <tr key={item.id}>
                    <td dir="ltr"><a className="table-link" href={item.odoo_url} target="_blank" rel="noreferrer">{item.name}</a></td>
                    <td className="cell-client">
                      <span className="client-name">{partner.title}</span>
                      {partner.detail ? <span className="cell-detail">{partner.detail}</span> : null}
                    </td>
                    <td className="money" dir="ltr">{formatMoney(item.amount_total, item.currency)}</td>
                    <td><span className={odooStatusClass(item.state)}>{t(quoteStateLabel[item.state] ?? { ar: item.state, en: item.state })}</span></td>
                    <td dir="ltr">{item.client_order_ref ?? item.origin ?? "—"}</td>
                    <td className="nowrap">{formatOdooWhen(item.date_order, locale)}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => {
                          const params: Record<string, string> = { tab: "invoices" };
                          if (item.partner_id) params.partner = String(item.partner_id);
                          if (item.partner_name) params.client = item.partner_name;
                          setSearchParams(params);
                          setPaper({ kind: "invoice", quotation: item });
                        }}
                      >
                        {t(copy.invoiceFromQuote)}
                      </button>
                    </td>
                  </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        </>
      ) : null}

      {tab === "invoices" ? (
        <>
        {paper?.kind === "invoice" ? (
          <OdooPaperForm
            kind="invoice"
            partnerId={partnerFilter || undefined}
            quotation={paper.quotation}
            t={t}
            onCancel={() => setPaper(null)}
            onCreated={(message) => {
              setNotice(message);
              toast.success(message);
              setPaper(null);
              setRefresh((value) => value + 1);
            }}
          />
        ) : null}
        <div className="toolbar filter-bar filter-grid">
          <label className="field-label">
            {t(copy.search)}
            <span className="search-bar">
              <Search size={16} aria-hidden="true" />
              <input type="search" className="field" placeholder={t(copy.searchInvoices)} value={invoiceQuery} onChange={(e) => setInvoiceQuery(e.target.value)} aria-label={t(copy.search)} />
            </span>
          </label>
          <label className="field-label">
            {t(copy.odooState)}
            <select className="field" value={invoiceState} onChange={(e) => setInvoiceState(e.target.value)}>
              <option value="">{t(copy.all)}</option>
              {["draft", "posted", "cancel"].map((state) => (
                <option key={state} value={state}>{t(quoteStateLabel[state] ?? { ar: state, en: state })}</option>
              ))}
            </select>
          </label>
          <label className="field-label">
            {t(copy.filterPayment)}
            <select className="field" value={invoicePayment} onChange={(e) => setInvoicePayment(e.target.value)}>
              <option value="">{t(copy.all)}</option>
              {Object.entries(paymentLabel).map(([key, label]) => (
                <option key={key} value={key}>{t(label)}</option>
              ))}
            </select>
          </label>
        </div>
        {partnerFilter || papersUnlinked ? (
          <p className="papers-for">
            <span>{papersUnlinked ? t(copy.clientPapersUnlinked) : t(copy.papersFor).replace("{kind}", t(copy.odooTabInvoices)).replace("{client}", clientLabel || "—")}</span>
            <button type="button" className="btn btn-ghost" onClick={() => setTab("invoices")}>{t(copy.showAllPapers)}</button>
          </p>
        ) : null}
        <div className="table-wrap">
          <table className="odoo-table">
            <thead>
              <tr>
                <th>{t(copy.number)}</th>
                <th>{t(copy.client)}</th>
                <th className="money">{t(copy.odooAmount)}</th>
                <th>{t(copy.odooState)}</th>
                <th>{t(copy.filterPayment)}</th>
                <th>{t(copy.odooReference)}</th>
                <th>{t(copy.odooDate)}</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <LoadingTableRow colSpan={7} label={t(copy.loading)} />
              ) : !odooReady ? (
                <tr>
                  <td colSpan={7}>{t(copy.odooNotConfigured)}</td>
                </tr>
              ) : filteredInvoices.length === 0 ? (
                <tr>
                  <td colSpan={7}>{invoiceQuery || invoiceState || invoicePayment ? t(copy.noSearchResults) : t(copy.empty)}</td>
                </tr>
              ) : (
                filteredInvoices.map((item) => {
                  const partner = partnerLines(item.partner_name);
                  return (
                  <tr key={item.id}>
                    <td dir="ltr"><a className="table-link" href={item.odoo_url} target="_blank" rel="noreferrer">{item.name}</a></td>
                    <td className="cell-client">
                      <span className="client-name">{partner.title}</span>
                      {partner.detail ? <span className="cell-detail">{partner.detail}</span> : null}
                    </td>
                    <td className="money" dir="ltr">
                      <span>{formatMoney(item.amount_total, item.currency)}</span>
                      <span className="cell-detail">{t(copy.odooResidual)} {formatMoney(item.amount_residual, item.currency)}</span>
                    </td>
                    <td><span className={odooStatusClass(item.state)}>{t(quoteStateLabel[item.state] ?? { ar: item.state, en: item.state })}</span></td>
                    <td>{item.payment_state ? <span className={odooStatusClass(item.payment_state)}>{t(paymentLabel[item.payment_state] ?? { ar: item.payment_state, en: item.payment_state })}</span> : "—"}</td>
                    <td dir="ltr">{item.ref ?? item.invoice_origin ?? "—"}</td>
                    <td className="nowrap">{formatOdooWhen(item.invoice_date, locale)}</td>
                  </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        </>
      ) : null}
    </>
  );
}
