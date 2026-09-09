import { useEffect, useState, type FormEvent } from "react";
import { api, type Client, type OdooInvoice, type OdooQuotation, type PageMeta } from "../api";
import { Pagination } from "../components/Pagination";
import { copy, type Locale } from "../i18n";

const TELEGRAM_BOT = import.meta.env.VITE_TELEGRAM_BOT ?? "pro_design_perfect_bot";

type Tab = "clients" | "quotations" | "invoices";

export function Clients({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [tab, setTab] = useState<Tab>("clients");
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
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");

  useEffect(() => {
    api.odooStatus().then((res) => setOdooReady(res.data.configured)).catch(() => setOdooReady(false));
  }, []);

  useEffect(() => {
    if (tab !== "clients") return;
    setLoading(true);
    api
      .clients(page)
      .then((res) => {
        setItems(res.data);
        setMeta(res.meta);
      })
      .catch(() => {
        setItems([]);
        setMeta(null);
      })
      .finally(() => setLoading(false));
  }, [page, tab]);

  useEffect(() => {
    if (tab !== "quotations" || !odooReady) return;
    setLoading(true);
    api
      .odooQuotations()
      .then((res) => setQuotations(res.data))
      .catch((err) => {
        setQuotations([]);
        setError(err instanceof Error ? err.message : t(copy.saveFailed));
      })
      .finally(() => setLoading(false));
  }, [tab, odooReady, t]);

  useEffect(() => {
    if (tab !== "invoices" || !odooReady) return;
    setLoading(true);
    api
      .odooInvoices()
      .then((res) => setInvoices(res.data))
      .catch((err) => {
        setInvoices([]);
        setError(err instanceof Error ? err.message : t(copy.saveFailed));
      })
      .finally(() => setLoading(false));
  }, [tab, odooReady, t]);

  async function syncOdoo() {
    setError("");
    setNotice("");
    try {
      const res = await api.syncOdooPartners();
      setNotice(`${t(copy.odooSyncDone)}: ${res.data.created} / ${res.data.updated}`);
      setPage(1);
      const list = await api.clients(1);
      setItems(list.data);
      setMeta(list.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  async function createClient(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    try {
      await api.createClient({
        name,
        email: email || undefined,
        phone: phone || undefined,
      });
      setName("");
      setEmail("");
      setPhone("");
      setShowForm(false);
      setNotice(t(copy.saveClient));
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
          <p className="page-lede">{t(copy.clientsLede)}</p>
        </div>
        <div className="toolbar">
          {odooReady ? (
            <button type="button" className="btn btn-teal" onClick={() => void syncOdoo()}>
              {t(copy.odooSync)}
            </button>
          ) : (
            <span className="muted">{t(copy.odooNotConfigured)}</span>
          )}
          <button type="button" className="btn btn-primary" onClick={() => setShowForm((v) => !v)}>
            {t(copy.addClient)}
          </button>
          <a className="btn btn-telegram" href={`https://t.me/${TELEGRAM_BOT}`} target="_blank" rel="noreferrer">
            {t(copy.signupCta)}
          </a>
        </div>
      </header>

      <div className="toolbar" role="tablist" aria-label={t(copy.clients)}>
        {(["clients", "quotations", "invoices"] as Tab[]).map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            className={tab === key ? "btn btn-primary" : "btn btn-ghost"}
            onClick={() => {
              setTab(key);
              setError("");
            }}
          >
            {t(copy[key === "clients" ? "odooTabClients" : key === "quotations" ? "odooTabQuotations" : "odooTabInvoices"])}
          </button>
        ))}
      </div>

      {showForm ? (
        <form className="card detail-card toolbar" onSubmit={createClient}>
          <input className="field" value={name} onChange={(e) => setName(e.target.value)} placeholder={t(copy.client)} required />
          <input className="field" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder={t(copy.email)} />
          <input className="field" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder={t(copy.phone)} />
          <button className="btn btn-primary" type="submit">
            {t(copy.saveClient)}
          </button>
          <button className="btn btn-ghost" type="button" onClick={() => setShowForm(false)}>
            {t(copy.cancel)}
          </button>
        </form>
      ) : null}

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      {tab === "clients" ? (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>{t(copy.client)}</th>
                  <th>{t(copy.email)}</th>
                  <th>{t(copy.phone)}</th>
                  <th>{t(copy.telegram)}</th>
                  <th>{t(copy.odooPartner)}</th>
                  <th>{t(copy.requestsCount)}</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={6}>{t(copy.loading)}</td>
                  </tr>
                ) : items.length === 0 ? (
                  <tr>
                    <td colSpan={6}>{t(copy.empty)}</td>
                  </tr>
                ) : (
                  items.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <strong className="client-name">{item.name}</strong>
                      </td>
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
                <tr>
                  <td colSpan={7}>{t(copy.loading)}</td>
                </tr>
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
                <tr>
                  <td colSpan={7}>{t(copy.loading)}</td>
                </tr>
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
