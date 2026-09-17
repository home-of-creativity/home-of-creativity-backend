import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { api, canSocial, type SocialAbility, type SocialAccount, type SocialStaff } from "../../api";
import { useAuth } from "../../auth";
import { copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { useSocialWorkspace } from "./SocialWorkspace";
import { facebookErrorMessage, groupSocialPages, linkedinOauthMessage, platformLabel, socialAccountStatusLabel, socialStatusLabel } from "./helpers";

const abilities: { key: SocialAbility; label: typeof copy.socialPermAccounts }[] = [
  { key: "accounts", label: copy.socialPermAccounts },
  { key: "create", label: copy.socialPermCreate },
  { key: "approve", label: copy.socialPermApprove },
  { key: "engage", label: copy.socialPermEngage },
];

export function SocialAccounts({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const { accounts, meta, refreshAccounts, loading: accountsLoading } = useSocialWorkspace();
  const [searchParams, setSearchParams] = useSearchParams();
  const items = accounts;
  const [staff, setStaff] = useState<SocialStaff[]>([]);
  const [staffLoading, setStaffLoading] = useState(true);
  const [connecting, setConnecting] = useState(false);
  const [error, setError] = useState("");
  const [oauthNotice, setOauthNotice] = useState("");
  const [oauthError, setOauthError] = useState("");
  const [connectingLinkedin, setConnectingLinkedin] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const loading = accountsLoading || staffLoading;
  const facebookError = meta.facebook_error ?? "";
  const threadsError = meta.threads_error ?? "";
  const facebookPagesFound = typeof meta.facebook_pages_found === "number" ? meta.facebook_pages_found : null;
  const threadsOauthConfigured = Boolean(meta.threads_oauth_configured);
  const threadsRedirectUri = meta.threads_redirect_uri ?? "";
  const linkedinOauthConfigured = Boolean(meta.linkedin_oauth_configured);
  const linkedinRedirectUri = meta.linkedin_redirect_uri ?? "";
  const linkedinError = meta.linkedin_error ?? "";

  function loadStaff() {
    if (!canSocial(user, "accounts")) {
      setStaff([]);
      setStaffLoading(false);
      return;
    }
    setStaffLoading(true);
    api
      .socialStaff()
      .then((team) => setStaff(team.data))
      .catch(() => setStaff([]))
      .finally(() => setStaffLoading(false));
  }

  useEffect(() => {
    loadStaff();
  }, []);

  useEffect(() => {
    const connected = searchParams.get("threads");
    const oauthErrorCode = searchParams.get("threads_error");
    const linkedinConnected = searchParams.get("linkedin");
    const linkedinErrorCode = searchParams.get("linkedin_error");
    if (connected !== "connected" && !oauthErrorCode && linkedinConnected !== "connected" && !linkedinErrorCode) {
      return;
    }

    if (connected === "connected") {
      setOauthNotice(t(copy.socialThreadsConnected));
      setOauthError("");
    } else if (oauthErrorCode) {
      setOauthNotice("");
      setOauthError(threadsOauthMessage(oauthErrorCode, t));
    } else if (linkedinConnected === "connected") {
      setOauthNotice(t(copy.socialLinkedinConnected));
      setOauthError("");
    } else if (linkedinErrorCode) {
      setOauthNotice("");
      setOauthError(linkedinOauthMessage(linkedinErrorCode, t));
    }

    const next = new URLSearchParams(searchParams);
    next.delete("threads");
    next.delete("threads_error");
    next.delete("linkedin");
    next.delete("linkedin_error");
    setSearchParams(next, { replace: true });
    void refreshAccounts();
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

  async function connectLinkedin() {
    setConnectingLinkedin(true);
    setError("");
    try {
      const result = await api.linkedinConnect();
      window.location.assign(result.data.authorize_url);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.socialLinkedinOauthMissing));
      setConnectingLinkedin(false);
    }
  }

  async function toggle(id: number) {
    setBusyId(id);
    try {
      await api.toggleSocialAccount(id);
      await refreshAccounts();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setBusyId(null);
    }
  }

  async function remove(id: number) {
    setBusyId(id);
    try {
      await api.deleteSocialAccount(id);
      await refreshAccounts();
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.loading);
      setError(message);
      toast.error(message);
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
      loadStaff();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialAccounts} lede={copy.socialAccountsLede}>
      {canSocial(user, "accounts") ? (
        <div className="toolbar">
          <Link className="btn btn-primary" to="/social/accounts/new">
            {t(copy.socialAddAccount)}
          </Link>
          {threadsOauthConfigured ? (
            <button type="button" className="btn" disabled={connecting} onClick={() => void connectThreads()}>
              {t(copy.socialConnectThreads)}
            </button>
          ) : (
            <p className="notice notice-info">{t(copy.socialThreadsOauthMissing)}</p>
          )}
          {linkedinOauthConfigured ? (
            <button type="button" className="btn" disabled={connectingLinkedin} onClick={() => void connectLinkedin()}>
              {t(copy.socialConnectLinkedin)}
            </button>
          ) : (
            <p className="notice notice-info">{t(copy.socialLinkedinOauthMissing)}</p>
          )}
        </div>
      ) : null}
      {threadsRedirectUri ? (
        <p className="notice notice-info">
          {t(copy.socialThreadsRedirectHint)}{" "}
          <code dir="ltr">{threadsRedirectUri}</code>
        </p>
      ) : null}
      {linkedinRedirectUri ? (
        <p className="notice notice-info">
          {t(copy.socialLinkedinRedirectHint)}{" "}
          <code dir="ltr">{linkedinRedirectUri}</code>
        </p>
      ) : null}
      {oauthNotice ? <p className="notice notice-info">{oauthNotice}</p> : null}
      {oauthError ? <p className="error">{oauthError}</p> : null}
      {error ? <p className="error">{error}</p> : null}
      {facebookError ? <p className="error">{facebookErrorMessage(facebookError, t)}</p> : null}
      {threadsError ? <p className="error">{facebookErrorMessage(threadsError, t)}</p> : null}
      {linkedinError ? <p className="error">{facebookErrorMessage(linkedinError, t)}</p> : null}
      {facebookPagesFound !== null && facebookPagesFound > 0 ? (
        <p className="notice notice-info">{t(copy.socialFacebookPagesFound).replace("{count}", String(facebookPagesFound))}</p>
      ) : null}
      {loading ? <p className="muted">{t(copy.loading)}</p> : null}
      {!loading && pages.length === 0 ? <p className="card social-inbox-empty">{t(copy.socialNoAccounts)}</p> : null}
      <div className="social-account-tile-grid">
        {pages.map((page) => (
          <article key={page.key} className="social-account-tile">
            <header className="social-account-tile-head">
              <strong className="social-account-tile-name">{page.name}</strong>
              <div className="social-page-channels">
                {page.facebook ? (
                  <>
                    <span className="social-account-name">
                      <SocialBrandIcon platform="facebook" />
                      {t(copy.facebook)}
                    </span>
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
                  </>
                ) : (
                  <span className="social-account-name">
                    <SocialBrandIcon platform={page.accounts[0]?.platform ?? ""} />
                    {platformLabel(page.accounts[0]?.platform ?? "", t)}
                  </span>
                )}
              </div>
            </header>
            {page.accounts.map((item) => (
              <div key={item.id} className="social-page-status">
                <div>
                  <span dir="ltr">
                    {platformLabel(item.platform, t)} · {accountHandle(item)}
                  </span>
                  <br />
                  <span className={`status ${item.connection_status === "error" ? "status-failed" : item.is_active ? "status-published" : "status-failed"}`}>
                    {accountStatus(item)}
                  </span>
                  <small> · {item.has_token ? t(copy.socialHasToken) : t(copy.socialNoToken)}</small>
                  {socialAccountStatusLabel(item, t) ? <small className="error-inline">{socialAccountStatusLabel(item, t)}</small> : null}
                </div>
                {canSocial(user, "accounts") ? (
                  <div className="row-actions">
                    <Link className="btn btn-ghost" to={`/social/accounts/${item.id}/edit`}>
                      {t(copy.edit)}
                    </Link>
                    <button type="button" className="btn btn-ghost" disabled={busyId === item.id} onClick={() => void toggle(item.id)}>
                      {item.is_active ? t(copy.socialToggleOff) : t(copy.socialToggleOn)}
                    </button>
                    <ConfirmAction
                      label={t(copy.delete)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      disabled={busyId === item.id}
                      onConfirm={() => void remove(item.id)}
                    />
                  </div>
                ) : null}
              </div>
            ))}
          </article>
        ))}
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
