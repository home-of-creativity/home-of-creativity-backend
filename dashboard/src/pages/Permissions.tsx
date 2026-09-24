import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import {
  api,
  type SocialPageOption,
  type StaffAbility,
  type StaffAccessRow,
  type StaffRole,
} from "../api";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const abilityLabels: Record<StaffAbility, { ar: string; en: string }> = {
  "ops.overview": { ar: "نظرة عامة", en: "Overview" },
  "ops.requests": { ar: "الطلبات", en: "Requests" },
  "ops.clients": { ar: "العملاء", en: "Clients" },
  "ops.employees": { ar: "الموظفون", en: "Employees" },
  "ops.payments": { ar: "المدفوعات", en: "Payments" },
  "ops.channels": { ar: "قنوات البوت", en: "Bot channels" },
  "site.projects": { ar: "المشاريع", en: "Projects" },
  "site.categories": { ar: "التصنيفات", en: "Categories" },
  "site.reels": { ar: "الريلز", en: "Reels" },
  "site.articles": { ar: "المقالات", en: "Articles" },
  "site.pricing": { ar: "الأسعار", en: "Pricing" },
  "site.contact": { ar: "التواصل", en: "Contact" },
  "site.legal": { ar: "الخصوصية والشروط", en: "Privacy and terms" },
  "site.profile_pdf": { ar: "الملف التعريفي", en: "Profile PDF" },
  "social.content": { ar: "إدارة المحتوى", en: "Content" },
  "social.approve": { ar: "النشر والموافقة", en: "Publish and approve" },
  "social.engage": { ar: "التفاعل والتعليقات", en: "Engagement" },
  "social.messages": { ar: "الرسائل", en: "Messages" },
  "social.accounts": { ar: "ربط الحسابات", en: "Accounts" },
  "social.links": { ar: "الروابط والمظهر", en: "Links and design" },
};

type Draft = {
  id: number | null;
  name: string;
  abilities: StaffAbility[];
};

const emptyDraft: Draft = { id: null, name: "", abilities: [] };

export function Permissions({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [roles, setRoles] = useState<StaffRole[]>([]);
  const [staff, setStaff] = useState<StaffAccessRow[]>([]);
  const [pages, setPages] = useState<SocialPageOption[]>([]);
  const [groups, setGroups] = useState<{ key: StaffAbility; group: "ops" | "site" | "social" }[]>([]);
  const [draft, setDraft] = useState<Draft>(emptyDraft);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [passwords, setPasswords] = useState<Record<number, string>>({});

  const selectedRole = roles.find((role) => role.id === draft.id) ?? null;
  const roleHasSocial = useMemo(
    () => (selectedRole?.abilities ?? []).some((ability) => ability.startsWith("social.")),
    [selectedRole],
  );

  function load() {
    return Promise.all([api.roles(), api.staffAccess()]).then(([roleRes, access]) => {
      setRoles(roleRes.data);
      setStaff(access.data);
      setPages(access.pages);
      setGroups(access.abilities);
    });
  }

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  function toggleAbility(ability: StaffAbility) {
    setDraft((current) => ({
      ...current,
      abilities: current.abilities.includes(ability)
        ? current.abilities.filter((item) => item !== ability)
        : [...current.abilities, ability],
    }));
  }

  async function saveRole() {
    setBusy(true);
    setError("");
    try {
      const body = { name: draft.name.trim(), abilities: draft.abilities };
      if (draft.id) await api.updateRole(draft.id, body);
      else await api.createRole(body);
      await load();
      setDraft(emptyDraft);
      toast.success(t(copy.saved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function removeRole(role: StaffRole) {
    setBusy(true);
    setError("");
    try {
      await api.deleteRole(role.id);
      if (draft.id === role.id) setDraft(emptyDraft);
      await load();
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.permissionInUse));
    } finally {
      setBusy(false);
    }
  }

  async function saveAccess(row: StaffAccessRow, roleId: number | null, pageKeys: string[]) {
    setBusy(true);
    setError("");
    try {
      const password = passwords[row.id]?.trim();
      const res = await api.updateStaffAccess(row.id, {
        role_id: roleId,
        page_keys: pageKeys,
        password: password || undefined,
      });
      setStaff((current) => current.map((item) => (item.id === row.id ? res.data : item)));
      setPasswords((current) => ({ ...current, [row.id]: "" }));
      toast.success(t(copy.accessSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section>
      <PageHeader title={t(copy.permissionsTitle)} lede={t(copy.permissionsLede)} />
      {error ? <p className="error">{error}</p> : null}
      <div className="split-panels">
        <form
          className="card form-grid"
          onSubmit={(event) => {
            event.preventDefault();
            void saveRole();
          }}
        >
          <h2 className="section-title">{draft.id ? t(copy.edit) : t(copy.newPermission)}</h2>
          <label>
            {t(copy.permissionName)}
            <input value={draft.name} onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))} required />
          </label>
          {(["ops", "site", "social"] as const).map((group) => (
            <fieldset key={group}>
              <legend>{t(group === "ops" ? copy.abilityOps : group === "site" ? copy.abilitySite : copy.abilitySocial)}</legend>
              {groups.filter((item) => item.group === group).map((item) => (
                <label key={item.key} className="check-row">
                  <input type="checkbox" checked={draft.abilities.includes(item.key)} onChange={() => toggleAbility(item.key)} />
                  {t(abilityLabels[item.key])}
                </label>
              ))}
            </fieldset>
          ))}
          <div className="row-actions">
            <button className="btn btn-primary" type="submit" disabled={busy || draft.abilities.length === 0}>
              {t(copy.savePermission)}
            </button>
            {draft.id ? (
              <button className="btn" type="button" onClick={() => setDraft(emptyDraft)}>
                {t(copy.cancel)}
              </button>
            ) : null}
          </div>
        </form>
        <div className="card">
          <h2 className="section-title">{t(copy.permissionsTitle)}</h2>
          <ul className="plain-list">
            {roles.map((role) => (
              <li key={role.id}>
                <button type="button" className="btn btn-ghost" onClick={() => setDraft({ id: role.id, name: role.name, abilities: role.abilities })}>
                  {role.name}
                </button>
                <small> · {role.users_count ?? 0}</small>
                <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void removeRole(role)}>
                  {t(copy.deletePermission)}
                </button>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <section className="card" style={{ marginTop: "1.5rem" }}>
        <h2 className="section-title">{t(copy.assignRole)}</h2>
        <div className="table-wrap">
          <table className="table-flush">
            <thead>
              <tr>
                <th>{t(copy.employeeName)}</th>
                <th>{t(copy.permissionsTitle)}</th>
                <th>{t(copy.dashboardPassword)}</th>
                <th>{t(copy.socialPages)}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {staff.map((row) => {
                const showPages = roles.find((role) => role.id === row.role_id)?.abilities.some((ability) => ability.startsWith("social.")) ?? roleHasSocial;
                return (
                  <tr key={row.id}>
                    <td>
                      {row.name}
                      <br />
                      <small>{row.email || "—"}</small>
                    </td>
                    <td>
                      <select
                        value={row.role_id ?? ""}
                        onChange={(event) => {
                          const roleId = event.target.value ? Number(event.target.value) : null;
                          setStaff((current) => current.map((item) => (item.id === row.id ? { ...item, role_id: roleId } : item)));
                        }}
                      >
                        <option value="">{t(copy.noRole)}</option>
                        {roles.map((role) => (
                          <option key={role.id} value={role.id}>
                            {role.name}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      <input
                        type="password"
                        autoComplete="new-password"
                        placeholder={row.has_account ? t(copy.passwordHint) : ""}
                        value={passwords[row.id] ?? ""}
                        onChange={(event) => setPasswords((current) => ({ ...current, [row.id]: event.target.value }))}
                      />
                    </td>
                    <td>
                      {showPages ? (
                        pages.length === 0 ? (
                          <small>{t(copy.noSocialPages)}</small>
                        ) : (
                          pages.map((page) => (
                            <label key={page.key} className="check-row">
                              <input
                                type="checkbox"
                                checked={row.page_keys.includes(page.key)}
                                onChange={(event) => {
                                  const pageKeys = event.target.checked
                                    ? [...row.page_keys, page.key]
                                    : row.page_keys.filter((key) => key !== page.key);
                                  setStaff((current) => current.map((item) => (item.id === row.id ? { ...item, page_keys: pageKeys } : item)));
                                }}
                              />
                              {page.name}
                            </label>
                          ))
                        )
                      ) : (
                        "—"
                      )}
                    </td>
                    <td>
                      <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void saveAccess(row, row.role_id, row.page_keys)}>
                        {t(copy.save)}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
    </section>
  );
}
