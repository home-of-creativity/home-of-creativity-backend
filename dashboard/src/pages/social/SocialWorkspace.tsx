import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { Link, Outlet } from "react-router-dom";
import { api, type SocialAccount } from "../../api";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { platformLabel } from "./helpers";

const STORAGE = "hoc-social-account-id";

export type SocialAccountsMeta = {
  facebook_configured?: boolean;
  facebook_error?: string | null;
  facebook_pages_found?: number;
  threads_configured?: boolean;
  threads_error?: string | null;
  threads_oauth_configured?: boolean;
  threads_redirect_uri?: string | null;
  linkedin_oauth_configured?: boolean;
  linkedin_redirect_uri?: string | null;
  linkedin_error?: string | null;
};

type SocialWorkspaceValue = {
  accounts: SocialAccount[];
  activeAccounts: SocialAccount[];
  selectedAccount: SocialAccount | null;
  selectAccount: (id: number) => void;
  pickerOpen: boolean;
  openPicker: () => void;
  closePicker: () => void;
  loading: boolean;
  refreshAccounts: () => Promise<SocialAccount[]>;
  meta: SocialAccountsMeta;
};

const SocialWorkspaceContext = createContext<SocialWorkspaceValue | null>(null);

function readStoredId(): number | null {
  try {
    const raw = localStorage.getItem(STORAGE);
    const id = raw ? Number(raw) : NaN;
    return Number.isFinite(id) && id > 0 ? id : null;
  } catch {
    return null;
  }
}

function writeStoredId(id: number | null) {
  try {
    if (id) localStorage.setItem(STORAGE, String(id));
    else localStorage.removeItem(STORAGE);
  } catch {
    /* private mode */
  }
}

export function useSocialWorkspace() {
  const value = useContext(SocialWorkspaceContext);
  if (!value) {
    throw new Error("useSocialWorkspace must be used inside SocialWorkspace");
  }
  return value;
}

export function SocialWorkspace({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [meta, setMeta] = useState<SocialAccountsMeta>({});
  const [selectedId, setSelectedId] = useState<number | null>(readStoredId);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [loading, setLoading] = useState(true);

  const refreshAccounts = useCallback(async () => {
    const res = await api.socialAccounts();
    setAccounts(res.data);
    setMeta({
      facebook_configured: res.facebook_configured,
      facebook_error: res.facebook_error,
      facebook_pages_found: res.facebook_pages_found,
      threads_configured: res.threads_configured,
      threads_error: res.threads_error,
      threads_oauth_configured: res.threads_oauth_configured,
      threads_redirect_uri: res.threads_redirect_uri,
      linkedin_oauth_configured: res.linkedin_oauth_configured,
      linkedin_redirect_uri: res.linkedin_redirect_uri,
      linkedin_error: res.linkedin_error,
    });
    return res.data;
  }, []);

  useEffect(() => {
    refreshAccounts()
      .catch(() => setAccounts([]))
      .finally(() => setLoading(false));
  }, [refreshAccounts]);

  const activeAccounts = useMemo(() => accounts.filter((account) => account.is_active), [accounts]);
  const selectedAccount = activeAccounts.find((account) => account.id === selectedId) ?? null;

  useEffect(() => {
    if (loading) return;
    if (activeAccounts.length === 0) {
      setSelectedId(null);
      writeStoredId(null);
      setPickerOpen(false);
      return;
    }
    if (selectedAccount) return;
    if (activeAccounts.length === 1) {
      setSelectedId(activeAccounts[0].id);
      writeStoredId(activeAccounts[0].id);
      setPickerOpen(false);
      return;
    }
    setPickerOpen(true);
  }, [loading, activeAccounts, selectedAccount]);

  const selectAccount = useCallback((id: number) => {
    setSelectedId(id);
    writeStoredId(id);
    setPickerOpen(false);
  }, []);

  const value = useMemo<SocialWorkspaceValue>(
    () => ({
      accounts,
      activeAccounts,
      selectedAccount,
      selectAccount,
      pickerOpen,
      openPicker: () => setPickerOpen(true),
      closePicker: () => {
        if (selectedAccount) setPickerOpen(false);
      },
      loading,
      refreshAccounts,
      meta,
    }),
    [accounts, activeAccounts, selectedAccount, selectAccount, pickerOpen, loading, refreshAccounts, meta],
  );

  return (
    <SocialWorkspaceContext.Provider value={value}>
      <Outlet />
      {pickerOpen ? (
        <AccountPicker
          t={t}
          locale={locale}
          accounts={activeAccounts}
          selectedId={selectedAccount?.id ?? null}
          required={!selectedAccount}
          onSelect={selectAccount}
          onClose={() => {
            if (selectedAccount) setPickerOpen(false);
          }}
        />
      ) : null}
    </SocialWorkspaceContext.Provider>
  );
}

function AccountPicker({
  t,
  locale,
  accounts,
  selectedId,
  required,
  onSelect,
  onClose,
}: {
  t: (c: { ar: string; en: string }) => string;
  locale: Locale;
  accounts: SocialAccount[];
  selectedId: number | null;
  required: boolean;
  onSelect: (id: number) => void;
  onClose: () => void;
}) {
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape" && !required) onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose, required]);

  return (
    <div className="studio-modal" role="dialog" aria-modal="true" aria-labelledby="account-picker-title">
      {required ? null : (
        <button type="button" className="studio-modal-backdrop" aria-label={t(copy.cancel)} onClick={onClose} />
      )}
      <div className="studio-modal-card studio-account-picker" dir={locale === "ar" ? "rtl" : "ltr"}>
        <h2 id="account-picker-title">{t(copy.socialPickAccountTitle)}</h2>
        {accounts.length === 0 ? (
          <p className="muted">
            {t(copy.socialNoAccountsYet)}{" "}
            <Link to="/social/accounts">{t(copy.socialAccounts)}</Link>
          </p>
        ) : (
          <ul className="studio-account-list">
            {accounts.map((account) => (
              <li key={account.id}>
                <button
                  type="button"
                  className={account.id === selectedId ? "studio-account-option is-on" : "studio-account-option"}
                  onClick={() => onSelect(account.id)}
                >
                  <span className={`studio-chip-icon is-${account.platform}`}>
                    <SocialBrandIcon platform={account.platform} />
                  </span>
                  <span>
                    <strong>{account.name}</strong>
                    <small>
                      {platformLabel(account.platform, t)}
                      {account.handle ? ` · @${account.handle.replace(/^@/, "")}` : ""}
                    </small>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
