import { useEffect, useState, type FormEvent } from "react";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { api, canSocial, type SocialAbility, type SocialAccount, type SocialStaff } from "../../api";
import { useAuth } from "../../auth";
import { copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { platformLabel, socialPlatforms } from "./helpers";

const emptyForm = {
  platform: "facebook",
  name: "",
  handle: "",
  page_id: "",
  access_token: "",
  is_active: true,
};

const abilities: { key: SocialAbility; label: typeof copy.socialPermAccounts }[] = [
  { key: "accounts", label: copy.socialPermAccounts },
  { key: "create", label: copy.socialPermCreate },
  { key: "approve", label: copy.socialPermApprove },
  { key: "engage", label: copy.socialPermEngage },
];

export function SocialAccounts({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [items, setItems] = useState<SocialAccount[]>([]);
  const [staff, setStaff] = useState<SocialStaff[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [facebookError, setFacebookError] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [busyId, setBusyId] = useState<number | null>(null);

  function load() {
    setLoading(true);
    Promise.all([
      api.socialAccounts(),
      canSocial(user, "accounts") ? api.socialStaff().catch(() => ({ data: [] as SocialStaff[] })) : Promise.resolve({ data: [] as SocialStaff[] }),
    ])
      .then(([accounts, team]) => {
        setItems(accounts.data);
        setStaff(team.data);
        setError("");
        setFacebookError(accounts.facebook_error ?? "");
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  function startEdit(item: SocialAccount) {
    setEditingId(item.id);
    setForm({
      platform: item.platform,
      name: item.name,
      handle: item.handle ?? "",
      page_id: item.page_id ?? "",
      access_token: "",
      is_active: item.is_active,
    });
    setShowForm(true);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    try {
      const payload = {
        platform: form.platform,
        name: form.name,
        handle: form.handle || null,
        page_id: form.page_id || null,
        is_active: form.is_active,
        ...(form.access_token ? { access_token: form.access_token } : {}),
      };
      if (editingId) await api.updateSocialAccount(editingId, payload);
      else await api.createSocialAccount(payload);
      setShowForm(false);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    }
  }

  async function toggle(id: number) {
    setBusyId(id);
    try {
      await api.toggleSocialAccount(id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setBusyId(null);
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.delete))) return;
    setBusyId(id);
    try {
      await api.deleteSocialAccount(id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setBusyId(null);
    }
  }

  async function updateStaff(member: SocialStaff, ability: SocialAbility, enabled: boolean) {
    const current = member.social_permissions ?? member.social_abilities ?? [];
    const next = enabled ? Array.from(new Set([...current, ability])) : current.filter((item) => item !== ability);
    try {
      await api.updateSocialStaff(member.id, next);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialAccounts} lede={copy.socialAccountsLede}>
      {error && !showForm ? <p className="error">{error}</p> : null}
      {facebookError ? (
        <p className="error">
          {facebookError === "no_pages" ? t(copy.socialFacebookNoPages) : t(copy.socialFacebookError)}
        </p>
      ) : null}
      <div className="table-wrap card">
        <table className="table-flush">
          <thead>
            <tr>
              <th>{t(copy.employeeName)}</th>
              <th>{t(copy.socialHandle)}</th>
              <th>{t(copy.status)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? <LoadingTableRow colSpan={4} label={t(copy.loading)} /> : null}
            {!loading && items.length === 0 ? (
              <tr>
                <td colSpan={4}>{t(copy.socialNoAccounts)}</td>
              </tr>
            ) : null}
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <span className="social-account-name">
                    <SocialBrandIcon platform={item.platform} />
                    <strong>{item.name}</strong>
                  </span>
                  <br />
                  <small>{platformLabel(item.platform, t)}</small>
                </td>
                <td dir="ltr">{item.handle ? `@${item.handle}` : item.page_id || "—"}</td>
                <td>
                  <span className={`status ${item.is_active ? "status-published" : "status-failed"}`}>
                    {item.is_active ? t(copy.active) : t(copy.inactive)}
                  </span>
                  <br />
                  <small>{item.has_token ? t(copy.socialHasToken) : t(copy.socialNoToken)}</small>
                </td>
                <td className="row-actions">
                  {canSocial(user, "accounts") ? (
                    <>
                      <button type="button" className="btn btn-ghost" onClick={() => startEdit(item)}>
                        {t(copy.edit)}
                      </button>
                      <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void toggle(item.id)}>
                        {item.is_active ? t(copy.socialToggleOff) : t(copy.socialToggleOn)}
                      </button>
                      <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void remove(item.id)}>
                        {t(copy.delete)}
                      </button>
                    </>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {canSocial(user, "accounts") && staff.length > 0 ? (
        <section className="card social-staff-card">
          <h2 className="section-title">{t(copy.socialPermissions)}</h2>
          <div className="table-wrap">
            <table className="table-flush">
              <thead>
                <tr>
                  <th>{t(copy.employeeName)}</th>
                  {abilities.map((ability) => (
                    <th key={ability.key}>{t(ability.label)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {staff.map((member) => {
                  const granted = member.social_permissions ?? member.social_abilities ?? [];
                  const all = member.social_permissions == null;
                  return (
                    <tr key={member.id}>
                      <td>
                        {member.name}
                        {all ? <small> · {t(copy.socialAllAbilities)}</small> : null}
                      </td>
                      {abilities.map((ability) => (
                        <td key={ability.key}>
                          <input
                            type="checkbox"
                            checked={all || granted.includes(ability.key)}
                            onChange={(event) => void updateStaff(member, ability.key, event.target.checked)}
                          />
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {showForm ? (
        <FormDialog
          title={editingId ? t(copy.edit) : t(copy.socialAddAccount)}
          onClose={() => setShowForm(false)}
          onSubmit={(event) => void save(event)}
          submitLabel={t(copy.socialSaveAccount)}
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
        >
          <label className="field-label">
            <span>{t(copy.socialPlatform)}</span>
            <select className="field" value={form.platform} onChange={(event) => setForm((current) => ({ ...current, platform: event.target.value }))}>
              {socialPlatforms.map((platform) => (
                <option key={platform} value={platform}>
                  {platformLabel(platform, t)}
                </option>
              ))}
            </select>
          </label>
          <label className="field-label">
            <span>{t(copy.employeeName)}</span>
            <input className="field" value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
          </label>
          <label className="field-label">
            <span>{t(copy.socialHandle)}</span>
            <input className="field" value={form.handle} onChange={(event) => setForm((current) => ({ ...current, handle: event.target.value }))} />
          </label>
          <label className="field-label">
            <span>{t(copy.socialPageId)}</span>
            <input className="field" value={form.page_id} onChange={(event) => setForm((current) => ({ ...current, page_id: event.target.value }))} />
          </label>
          <label className="field-label">
            <span>{t(copy.socialToken)}</span>
            <input className="field" type="password" autoComplete="off" value={form.access_token} onChange={(event) => setForm((current) => ({ ...current, access_token: event.target.value }))} />
            <small className="muted">{t(copy.socialTokenHint)}</small>
          </label>
        </FormDialog>
      ) : null}
    </SocialChrome>
  );
}
