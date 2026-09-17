import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { Link, Outlet } from "react-router-dom";
import { api, type SocialAccount } from "../../api";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { groupSocialPages, orderedPageAccounts, pageChannelSummary, type SocialPageGroup } from "./helpers";

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
  pages: SocialPageGroup[];
  selectedPage: SocialPageGroup | null;
  selectedAccount: SocialAccount | null;
  selectAccount: (id: number) => void;
  selectPage: (key: string) => void;
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
  const pages = useMemo(() => groupSocialPages(activeAccounts), [activeAccounts]);
  const selectedAccount = activeAccounts.find((account) => account.id === selectedId) ?? null;
  const selectedPage = pages.find((page) => page.accounts.some((account) => account.id === selectedId)) ?? null;

  useEffect(() => {
    if (loading) return;
    if (pages.length === 0) {
      setSelectedId(null);
      writeStoredId(null);
      setPickerOpen(false);
      return;
    }
    if (selectedPage) return;
    if (pages.length === 1) {
      const primary = pages[0].facebook ?? pages[0].accounts[0];
      if (primary) {
        setSelectedId(primary.id);
        writeStoredId(primary.id);
      }
      setPickerOpen(false);
      return;
    }
    setPickerOpen(true);
  }, [loading, pages, selectedPage]);

  const selectAccount = useCallback((id: number) => {
    setSelectedId(id);
    writeStoredId(id);
    setPickerOpen(false);
  }, []);

  const selectPage = useCallback((key: string) => {
    const page = pages.find((item) => item.key === key);
    const primary = page?.facebook ?? page?.accounts[0];
    if (!primary) return;
    setSelectedId(primary.id);
    writeStoredId(primary.id);
    setPickerOpen(false);
  }, [pages]);

  const value = useMemo<SocialWorkspaceValue>(
    () => ({
      accounts,
      activeAccounts,
      pages,
      selectedPage,
      selectedAccount,
      selectAccount,
      selectPage,
      pickerOpen,
      openPicker: () => setPickerOpen(true),
      closePicker: () => {
        if (selectedPage) setPickerOpen(false);
      },
      loading,
      refreshAccounts,
      meta,
    }),
    [accounts, activeAccounts, pages, selectedPage, selectedAccount, selectAccount, selectPage, pickerOpen, loading, refreshAccounts, meta],
  );

  return (
    <SocialWorkspaceContext.Provider value={value}>
      <Outlet />
      {pickerOpen ? (
        <AccountPicker
          t={t}
          locale={locale}
          pages={pages}
          selectedKey={selectedPage?.key ?? null}
          required={!selectedPage}
          onSelect={selectPage}
          onClose={() => {
            if (selectedPage) setPickerOpen(false);
          }}
        />
      ) : null}
    </SocialWorkspaceContext.Provider>
  );
}

function AccountPicker({
  t,
  locale,
  pages,
  selectedKey,
  required,
  onSelect,
  onClose,
}: {
  t: (c: { ar: string; en: string }) => string;
  locale: Locale;
  pages: SocialPageGroup[];
  selectedKey: string | null;
  required: boolean;
  onSelect: (key: string) => void;
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
        {pages.length === 0 ? (
          <p className="muted">
            {t(copy.socialNoAccountsYet)}{" "}
            <Link to="/social/accounts">{t(copy.socialAccounts)}</Link>
          </p>
        ) : (
          <ul className="studio-account-list">
            {pages.map((page) => (
              <li key={page.key}>
                <button
                  type="button"
                  className={page.key === selectedKey ? "studio-account-option is-on" : "studio-account-option"}
                  onClick={() => onSelect(page.key)}
                >
                  <span>
                    <strong>{page.name}</strong>
                    <small>{pageChannelSummary(page, t)}</small>
                  </span>
                  <span className="studio-page-networks" aria-hidden>
                    {orderedPageAccounts(page).map((account) => (
                      <span key={account.id} className={`studio-chip-icon is-${account.platform}`}>
                        <SocialBrandIcon platform={account.platform} />
                      </span>
                    ))}
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
