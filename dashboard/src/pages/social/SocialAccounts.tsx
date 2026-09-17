import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { FormDialog } from "../../components/FormDialog";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { api, canSocial, type SocialAbility, type SocialAccount, type SocialStaff } from "../../api";
import { useAuth } from "../../auth";
import { copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { facebookErrorMessage, groupSocialPages, platformLabel, socialAccountStatusLabel, socialPlatforms, socialStatusLabel } from "./helpers";

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
  const [searchParams, setSearchParams] = useSearchParams();
  const [items, setItems] = useState<SocialAccount[]>([]);
  const [staff, setStaff] = useState<SocialStaff[]>([]);
  const [loading, setLoading] = useState(true);
  const [connecting, setConnecting] = useState(false);
  const [error, setError] = useState("");
  const [facebookError, setFacebookError] = useState("");
  const [threadsError, setThreadsError] = useState("");
  const [oauthNotice, setOauthNotice] = useState("");
  const [oauthError, setOauthError] = useState("");
  const [facebookPagesFound, setFacebookPagesFound] = useState<number | null>(null);
  const [threadsOauthConfigured, setThreadsOauthConfigured] = useState(false);
  const [threadsRedirectUri, setThreadsRedirectUri] = useState("");
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
        setThreadsError(accounts.threads_error ?? "");
        setFacebookPagesFound(typeof accounts.facebook_pages_found === "number" ? accounts.facebook_pages_found : null);
        setThreadsOauthConfigured(Boolean(accounts.threads_oauth_configured));
        setThreadsRedirectUri(accounts.threads_redirect_uri ?? "");
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  useEffect(() => {
    const connected = searchParams.get("threads");
    const oauthErrorCode = searchParams.get("threads_error");
    if (connected !== "connected" && !oauthErrorCode) {
      return;
    }

    if (connected === "connected") {
      setOauthNotice(t(copy.socialThreadsConnected));
      setOauthError("");
    } else if (oauthErrorCode) {
      setOauthNotice("");
      setOauthError(threadsOauthMessage(oauthErrorCode, t));
    }

    const next = new URLSearchParams(searchParams);
    next.delete("threads");
    next.delete("threads_error");
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams, t]);

  async function connectThreads() {
    setConnecting(true);
    setError("");
    try {
      const result = await api.threadsConnect();
      window.location.assign(result.data.authorize_url);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.socialThreadsOauthMissing));
      setConnecting(false);
    }
  }

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

  const pages = useMemo(() => groupSocialPages(items), [items]);

  function accountHandle(item: SocialAccount) {
    return item.handle ? `@${item.handle}` : item.page_id || "—";
  }

  function accountStatus(item: SocialAccount) {
    if (item.connection_status === "error") return socialStatusLabel("failed", t);
    return item.is_active ? t(copy.active) : t(copy.inactive);
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
      {canSocial(user, "accounts") ? (
        <div className="toolbar">
          {threadsOauthConfigured ? (
            <button type="button" className="btn btn-primary" disabled={connecting} onClick={() => void connectThreads()}>
              {t(copy.socialConnectThreads)}
            </button>
          ) : (
            <p className="notice notice-info">{t(copy.socialThreadsOauthMissing)}</p>
          )}
        </div>
      ) : null}
      {threadsRedirectUri ? (
        <p className="notice notice-info">
          {t(copy.socialThreadsRedirectHint)}{" "}
          <code dir="ltr">{threadsRedirectUri}</code>
        </p>
      ) : null}
      {oauthNotice ? <p className="notice notice-info">{oauthNotice}</p> : null}
      {oauthError ? <p className="error">{oauthError}</p> : null}
      {error && !showForm ? <p className="error">{error}</p> : null}
      {facebookError ? <p className="error">{facebookErrorMessage(facebookError, t)}</p> : null}
      {threadsError ? <p className="error">{facebookErrorMessage(threadsError, t)}</p> : null}
      {facebookPagesFound !== null && facebookPagesFound > 0 ? (
        <p className="notice notice-info">{t(copy.socialFacebookPagesFound).replace("{count}", String(facebookPagesFound))}</p>
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
            {!loading && pages.length === 0 ? (
              <tr>
                <td colSpan={4}>{t(copy.socialNoAccounts)}</td>
              </tr>
            ) : null}
            {pages.map((page) => (
              <tr key={page.key}>
                <td>
                  <strong className="client-name">{page.name}</strong>
                  <div className="social-page-channels">
                    {page.facebook ? (
                      <span className="social-account-name">
                        <SocialBrandIcon platform="facebook" />
                        {t(copy.facebook)}
                      </span>
                    ) : null}
                    {page.instagram ? (
                      <span className="social-account-name">
                        <SocialBrandIcon platform="instagram" />
                        {t(copy.socialLinkedInstagram)}
                      </span>
                    ) : (
                      <small className="muted">{t(copy.socialNoInstagram)}</small>
                    )}
                    {page.threads ? (
                      <span className="social-account-name">
                        <SocialBrandIcon platform="threads" />
                        {t(copy.socialLinkedThreads)}
                      </span>
                    ) : (
                      <small className="muted">{t(copy.socialNoThreads)}</small>
                    )}
                  </div>
                </td>
                <td>
                  {page.accounts.map((item) => (
                    <div key={item.id} dir="ltr">
                      {platformLabel(item.platform, t)} · {accountHandle(item)}
                    </div>
                  ))}
                </td>
                <td>
                  {page.accounts.map((item) => (
                    <div key={item.id} className="social-page-status">
                      <span className={`status ${item.connection_status === "error" ? "status-failed" : item.is_active ? "status-published" : "status-failed"}`}>
                        {accountStatus(item)}
                      </span>
                      <small>{item.has_token ? t(copy.socialHasToken) : t(copy.socialNoToken)}</small>
                      {socialAccountStatusLabel(item, t) ? <small className="error-inline">{socialAccountStatusLabel(item, t)}</small> : null}
                    </div>
                  ))}
                </td>
                <td>
                  {canSocial(user, "accounts")
                    ? page.accounts.map((item) => (
                        <div key={item.id} className="row-actions">
                          <small>{platformLabel(item.platform, t)}</small>
                          <button type="button" className="btn btn-ghost" onClick={() => startEdit(item)}>
                            {t(copy.edit)}
                          </button>
                          <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void toggle(item.id)}>
                            {item.is_active ? t(copy.socialToggleOff) : t(copy.socialToggleOn)}
                          </button>
                          <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void remove(item.id)}>
                            {t(copy.delete)}
                          </button>
                        </div>
                      ))
                    : null}
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
          <label className="check-row">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))}
            />
            <span>{form.is_active ? t(copy.socialToggleOn) : t(copy.socialToggleOff)}</span>
          </label>
        </FormDialog>
      ) : null}
    </SocialChrome>
  );
}

function threadsOauthMessage(code: string, t: (c: { ar: string; en: string }) => string) {
  if (code === "denied") return t(copy.socialThreadsOauthDenied);
  if (code === "invalid_state") return t(copy.socialThreadsOauthInvalid);
  if (code === "token_exchange") return t(copy.socialThreadsOauthToken);
  if (code === "profile") return t(copy.socialThreadsOauthProfile);

  return t(copy.socialThreadsOauthInvalid);
}
