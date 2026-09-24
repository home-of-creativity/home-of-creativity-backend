import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  api,
  type CrudAction,
  type PageGrant,
  type SocialPageOption,
  type StaffAbility,
  type StaffAccessRow,
  type StaffModule,
  type StaffRole,
} from "../api";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const abilityLabels: Record<StaffModule, { ar: string; en: string }> = {
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

const crudLabels: Record<CrudAction, { ar: string; en: string }> = {
  view: copy.crudView,
  create: copy.crudCreate,
  update: copy.crudUpdate,
  delete: copy.crudDelete,
};

const pageAbilities = ["social.content", "social.approve", "social.engage", "social.messages"] as const;

type AbilityModule = { key: StaffModule; group: "ops" | "site" | "social"; actions: CrudAction[] };

type Draft = {
  id: number | null;
  name: string;
  abilities: string[];
};

const emptyDraft: Draft = { id: null, name: "", abilities: [] };

function holds(abilities: string[], module: string, action?: string) {
  if (!action) return abilities.includes(module);
  return abilities.includes(module) || abilities.includes(`${module}.${action}`);
}

export function Permissions({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [roles, setRoles] = useState<StaffRole[]>([]);
  const [staff, setStaff] = useState<StaffAccessRow[]>([]);
  const [pages, setPages] = useState<SocialPageOption[]>([]);
  const [groups, setGroups] = useState<AbilityModule[]>([]);
  const [draft, setDraft] = useState<Draft>(emptyDraft);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [passwords, setPasswords] = useState<Record<number, string>>({});

  function load() {
    return Promise.all([api.roles(), api.staffAccess()]).then(([roleRes, access]) => {
      setRoles(roleRes.data);
      setStaff(access.data.map((row) => ({ ...row, page_grants: row.page_grants ?? [] })));
      setPages(access.pages);
      setGroups(access.abilities);
    });
  }

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

  function toggleAbility(module: string, action?: CrudAction) {
    setDraft((current) => {
      if (!action) {
        const abilities = current.abilities.includes(module)
          ? current.abilities.filter((item) => item !== module)
          : [...current.abilities, module];
        return { ...current, abilities };
      }

      if (current.abilities.includes(module)) {
        const abilities = (["view", "create", "update", "delete"] as const)
          .filter((verb) => verb !== action)
          .map((verb) => `${module}.${verb}`);
        return {
          ...current,
          abilities: [...current.abilities.filter((item) => item !== module && !item.startsWith(`${module}.`)), ...abilities],
        };
      }

      const key = `${module}.${action}`;
      const abilities = current.abilities.includes(key)
        ? current.abilities.filter((item) => item !== key)
        : [...current.abilities, key];
      return { ...current, abilities };
    });
  }

  async function saveRole() {
    setBusy(true);
    setError("");
    try {
      const body = { name: draft.name.trim(), abilities: draft.abilities as StaffAbility[] };
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

  async function saveAccess(row: StaffAccessRow) {
    setBusy(true);
    setError("");
    try {
      const password = passwords[row.id]?.trim();
      const role = roles.find((item) => item.id === row.role_id);
      const allowed = new Set(pageAbilities.filter((ability) => role?.abilities.includes(ability)));
      const page_grants = row.page_grants.filter((grant) => allowed.has(grant.ability as (typeof pageAbilities)[number]));
      const res = await api.updateStaffAccess(row.id, {
        role_id: row.role_id,
        page_grants,
        password: password || undefined,
      });
      setStaff((current) => current.map((item) => (item.id === row.id ? { ...res.data, page_grants: res.data.page_grants ?? [] } : item)));
      setPasswords((current) => ({ ...current, [row.id]: "" }));
      toast.success(t(copy.accessSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  function toggleGrant(row: StaffAccessRow, ability: string, pageKey: string, checked: boolean) {
    const page_grants: PageGrant[] = checked
      ? [...row.page_grants, { ability, page_key: pageKey }]
      : row.page_grants.filter((grant) => !(grant.ability === ability && grant.page_key === pageKey));
    setStaff((current) => current.map((item) => (item.id === row.id ? { ...item, page_grants } : item)));
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
          <label>
            {t(copy.permissionName)}
            <input value={draft.name} onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))} required />
          </label>
          {(["ops", "site", "social"] as const).map((group) => (
            <fieldset key={group}>
              <legend>{t(group === "ops" ? copy.abilityOps : group === "site" ? copy.abilitySite : copy.abilitySocial)}</legend>
              {groups.filter((item) => item.group === group).map((item) => (
                <div key={item.key}>
                  {item.actions.length === 0 ? (
                    <label className="check-row">
                      <input type="checkbox" checked={holds(draft.abilities, item.key)} onChange={() => toggleAbility(item.key)} />
                      {t(abilityLabels[item.key])}
                    </label>
                  ) : (
                    <div>
                      <strong>{t(abilityLabels[item.key])}</strong>
                      {item.actions.map((action) => (
                        <label key={action} className="check-row">
                          <input
                            type="checkbox"
                            checked={holds(draft.abilities, item.key, action)}
                            onChange={() => toggleAbility(item.key, action)}
                          />
                          {t(crudLabels[action])}
                        </label>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </fieldset>
          ))}
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
                const role = roles.find((item) => item.id === row.role_id);
                const grantedPages = pageAbilities.filter((ability) => role?.abilities.includes(ability));
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
                        {roles.map((item) => (
                          <option key={item.id} value={item.id}>
                            {item.name}
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
                      {grantedPages.length === 0 ? (
                        "—"
                      ) : pages.length === 0 ? (
                        <small>{t(copy.noSocialPages)}</small>
                      ) : (
                        grantedPages.map((ability) => (
                          <div key={ability}>
                            <strong>{t(abilityLabels[ability])}</strong>
                            {pages.map((page) => (
                              <label key={page.key} className="check-row">
                                <input
                                  type="checkbox"
                                  checked={row.page_grants.some((grant) => grant.ability === ability && grant.page_key === page.key)}
                                  onChange={(event) => toggleGrant(row, ability, page.key, event.target.checked)}
                                />
                                {page.name}
                              </label>
                            ))}
                          </div>
                        ))
                      )}
                    </td>
                    <td>
                      <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void saveAccess(row)}>
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
