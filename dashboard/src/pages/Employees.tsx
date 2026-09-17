import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { api, type ClickUpMember, type Employee } from "../api";
import { copy, professions, type Locale } from "../i18n";

export function Employees({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [items, setItems] = useState<Employee[]>([]);
  const [members, setMembers] = useState<ClickUpMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [odooReady, setOdooReady] = useState(false);
  const [query, setQuery] = useState("");
  const staffBot = import.meta.env.VITE_TELEGRAM_STAFF_BOT as string | undefined;

  function load() {
    setLoading(true);
    Promise.all([api.employees(), api.clickupMembers().catch(() => ({ data: [] as ClickUpMember[] }))])
      .then(([employees, clickup]) => {
        setItems(employees.data);
        setMembers(clickup.data);
      })
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    api.odooStatus().then((res) => setOdooReady(res.data.configured)).catch(() => setOdooReady(false));
    const timer = window.setInterval(() => load(), 15000);
    return () => window.clearInterval(timer);
  }, []);

  function clickupLabel(id: string | null) {
    if (!id) return "—";
    const member = members.find((item) => item.id === id);
    return member ? member.name : id;
  }

  function telegramLabel(item: Employee) {
    if (item.telegram_username) return `@${item.telegram_username}`;
    if (item.telegram_user_id) return t(copy.telegramLinked);
    return "—";
  }

  const filteredItems = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return items;
    return items.filter((item) => {
      const haystack = [item.code, item.name, item.telegram_username ?? ""].join(" ").toLowerCase();
      return haystack.includes(needle);
    });
  }, [items, query]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deleteEmployee(id);
      load();
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
            {!odooReady ? <span className="muted">{t(copy.odooNotConfigured)}</span> : null}
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

      <div className="search-bar">
        <Search size={16} aria-hidden="true" />
        <input
          type="search"
          className="field"
          placeholder={t(copy.searchEmployees)}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label={t(copy.search)}
        />
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
            ) : filteredItems.length === 0 ? (
              <tr>
                <td colSpan={8}>{query ? t(copy.noSearchResults) : t(copy.empty)}</td>
              </tr>
            ) : (
              filteredItems.map((item) => (
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
    </>
  );
}
