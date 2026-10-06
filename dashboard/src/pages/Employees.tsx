import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { Pagination } from "../components/Pagination";
import { api, type ClickUpMember, type Employee, type PageMeta } from "../api";
import { copy, professions, type Locale } from "../i18n";

export function Employees({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [items, setItems] = useState<Employee[]>([]);
  const [members, setMembers] = useState<ClickUpMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [odooReady, setOdooReady] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [status, setStatus] = useState("");
  const [profession, setProfession] = useState("");
  const [odooFilter, setOdooFilter] = useState("");
  const [activeFilter, setActiveFilter] = useState("");
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const staffBot = import.meta.env.VITE_TELEGRAM_STAFF_BOT as string | undefined;

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedQuery(query.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    setPage(1);
  }, [debouncedQuery, status, profession, odooFilter, activeFilter]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .employees({
        search: debouncedQuery || undefined,
        status: status || undefined,
        profession: profession || undefined,
        odoo: odooFilter || undefined,
        active: activeFilter || undefined,
        page,
      })
      .then((employees) => {
        if (cancelled) return;
        setItems(employees.data);
        setMeta(employees.meta);
      })
      .catch(() => {
        if (!cancelled) {
          setItems([]);
          setMeta(null);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    const timer = window.setInterval(() => {
      api.employees({
        search: debouncedQuery || undefined,
        status: status || undefined,
        profession: profession || undefined,
        odoo: odooFilter || undefined,
        active: activeFilter || undefined,
        page,
      }).then((employees) => {
        if (cancelled) return;
        setItems(employees.data);
        setMeta(employees.meta);
      }).catch(() => undefined);
    }, 30000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [debouncedQuery, status, profession, odooFilter, activeFilter, page]);

  useEffect(() => {
    api.odooStatus().then((res) => setOdooReady(res.data.configured)).catch(() => setOdooReady(false));
    api.clickupMembers().then((clickup) => setMembers(clickup.data)).catch(() => setMembers([]));
  }, []);

  function clickupLabel(id: string | null) {
    if (!id) return "—";
    const member = members.find((item) => item.id === id);
    return member ? member.name : id;
  }

  function telegramLabel(item: Employee) {
    if (item.telegram_username) return `@${item.telegram_username}`;
    if (item.telegram_user_id) return t(copy.telegramLinked);
    return t(copy.linkTelegramCode).replace("{code}", item.code);
  }

  async function syncFromOdoo() {
    setSyncing(true);
    setError("");
    try {
      await api.syncOdooEmployees();
      setPage(1);
      const employees = await api.employees({ page: 1 });
      setItems(employees.data);
      setMeta(employees.meta);
      toast.success(t(copy.employeesSynced));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
    } finally {
      setSyncing(false);
    }
  }

  async function remove(id: number) {
    setError("");
    try {
      await api.deleteEmployee(id);
      const employees = await api.employees({
        search: debouncedQuery || undefined,
        status: status || undefined,
        profession: profession || undefined,
        odoo: odooFilter || undefined,
        active: activeFilter || undefined,
        page,
      });
      setItems(employees.data);
      setMeta(employees.meta);
      toast.success(t(copy.deleteSuccess));
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
        title={t(copy.employees)}
        lede={t(copy.employeesLede)}
        actions={
          <>
            {!odooReady ? <span className="muted">{t(copy.odooNotConfigured)}</span> : (
              <button className="btn btn-ghost" type="button" disabled={syncing} onClick={() => void syncFromOdoo()}>
                {t(copy.syncEmployees)}
              </button>
            )}
            <Link className="btn btn-primary" to="/employees/new">
              {t(copy.addEmployee)}
            </Link>
            {staffBot ? (
              <a className="btn btn-telegram" href={`https://t.me/${staffBot}`} target="_blank" rel="noreferrer">
                {t(copy.openStaffBot)}
              </a>
            ) : null}
          </>
        }
      />

      <p className="notice notice-info">{t(copy.joinHint)}</p>

      {error ? <p className="error">{error}</p> : null}

      <div className="toolbar filter-bar filter-grid">
        <label className="field-label">
          {t(copy.search)}
          <span className="search-bar">
            <Search size={16} aria-hidden="true" />
            <input type="search" className="field" placeholder={t(copy.searchEmployees)} value={query} onChange={(e) => setQuery(e.target.value)} aria-label={t(copy.search)} />
          </span>
        </label>
        <label className="field-label">
          {t(copy.employeeStatus)}
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t(copy.all)}</option>
            <option value="pending">{t(copy.pending)}</option>
            <option value="approved">{t(copy.approved)}</option>
            <option value="rejected">{t(copy.rejected)}</option>
          </select>
        </label>
        <label className="field-label">
          {t(copy.profession)}
          <select className="field" value={profession} onChange={(e) => setProfession(e.target.value)}>
            <option value="">{t(copy.all)}</option>
            {Object.entries(professions).map(([key, label]) => (
              <option key={key} value={key}>{t(label)}</option>
            ))}
          </select>
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
          {t(copy.filterActive)}
          <select className="field" value={activeFilter} onChange={(e) => setActiveFilter(e.target.value)}>
            <option value="">{t(copy.all)}</option>
            <option value="yes">{t(copy.active)}</option>
            <option value="no">{t(copy.filterInactive)}</option>
          </select>
        </label>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.employeeCode)}</th>
              <th>{t(copy.employeeName)}</th>
              <th>{t(copy.telegram)}</th>
              <th>{t(copy.clickupMember)}</th>
              <th>{t(copy.odooEmployee)}</th>
              <th>{t(copy.profession)}</th>
              <th>{t(copy.employeeStatus)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={8} label={t(copy.loading)} />
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={8}>{debouncedQuery || status || profession || odooFilter || activeFilter ? t(copy.noSearchResults) : t(copy.empty)}</td>
              </tr>
            ) : (
              items.map((item) => (
                <tr key={item.id}>
                  <td dir="ltr">{item.code}</td>
                  <td>
                    <strong className="client-name">{item.name}</strong>
                  </td>
                  <td dir="ltr">{telegramLabel(item)}</td>
                  <td>{clickupLabel(item.clickup_user_id)}</td>
                  <td dir="ltr">
                    {item.odoo_url ? (
                      <a href={item.odoo_url} target="_blank" rel="noreferrer">
                        {item.odoo_employee_id}
                      </a>
                    ) : (
                      item.odoo_employee_id ?? "—"
                    )}
                  </td>
                  <td>{t(professions[item.profession] ?? { ar: item.profession, en: item.profession })}</td>
                  <td>
                    <span className={`emp-status emp-status-${item.status}`}>
                      {item.status === "pending" ? t(copy.pending) : item.status === "rejected" ? t(copy.rejected) : t(copy.approved)}
                    </span>
                  </td>
                  <td className="actions-cell">
                    {item.status === "pending" ? (
                      <Link className="btn btn-teal" to={`/employees/${item.id}/approve`}>
                        {t(copy.approveEmployee)}
                      </Link>
                    ) : null}
                    <Link className="btn btn-ghost" to={`/employees/${item.id}/edit`}>
                      {t(copy.editEmployee)}
                    </Link>
                    <ConfirmAction
                      label={t(copy.deleteEmployee)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      onConfirm={() => void remove(item.id)}
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
  );
}
