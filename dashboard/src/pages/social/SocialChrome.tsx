import type { ReactNode } from "react";
import { NavLink } from "react-router-dom";
import { canSocial, type SocialAbility, type User } from "../../api";
import { PageHeader } from "../../components/PageHeader";
import { copy, type Copy, type Locale } from "../../i18n";
import { pageChannelSummary, platformLabel } from "./helpers";
import { useSocialWorkspace } from "./SocialWorkspace";

type Props = {
  locale?: Locale;
  t: (c: Copy) => string;
  user: User | null;
  title: Copy;
  lede?: Copy;
  actions?: ReactNode;
  immersive?: boolean;
  children: ReactNode;
};

const tabs: { to: string; end?: boolean; label: Copy; ability: SocialAbility }[] = [
  { to: "/social", end: true, label: copy.socialHome, ability: "create" },
  { to: "/social/links", label: copy.socialBioLinks, ability: "create" },
  { to: "/social/design", label: copy.socialDesign, ability: "create" },
  { to: "/social/calendar", label: copy.socialCalendar, ability: "create" },
  { to: "/social/inbox", label: copy.socialInbox, ability: "engage" },
  { to: "/social/accounts", label: copy.socialAccounts, ability: "accounts" },
];

export function SocialChrome({ t, user, title, lede, actions, immersive, children }: Props) {
  const { selectedAccount, selectedPage, pages, openPicker, loading } = useSocialWorkspace();
  const visible = tabs.filter((tab) => canSocial(user, tab.ability) || (tab.ability === "create" && canSocial(user, "approve")));
  const pageLabel = selectedPage?.name ?? selectedAccount?.name ?? null;
  const pageSummary = selectedPage ? pageChannelSummary(selectedPage, t) : selectedAccount ? platformLabel(selectedAccount.platform, t) : "";

  return (
    <div className={immersive ? "studio-shell is-fill is-immersive" : "studio-shell is-fill"}>
      <div className="studio-top">
        <nav className="studio-nav" aria-label={t(copy.navSocial)}>
          {visible.map((tab) => (
            <NavLink
              key={tab.to}
              to={tab.to}
              end={tab.end}
              className={({ isActive }) => (isActive ? "studio-nav-link is-active" : "studio-nav-link")}
            >
              {t(tab.label)}
            </NavLink>
          ))}
        </nav>
        <div className="studio-account-switch">
          {pageLabel ? (
            <span className="studio-account-current">
              <span>
                <strong>{pageLabel}</strong>
                {pageSummary ? <small>{pageSummary}</small> : null}
              </span>
            </span>
          ) : (
            <span className="muted">{loading ? t(copy.loading) : t(copy.socialPickAccount)}</span>
          )}
          {pages.length > 0 ? (
            <button type="button" className="btn btn-primary studio-change-account" onClick={openPicker}>
              {selectedPage ? t(copy.socialChangeAccount) : t(copy.socialPickAccount)}
            </button>
          ) : null}
        </div>
      </div>
      {immersive ? <h1 className="sr-only">{t(title)}</h1> : <PageHeader title={t(title)} lede={lede ? t(lede) : undefined} actions={actions} />}
      {children}
    </div>
  );
}
