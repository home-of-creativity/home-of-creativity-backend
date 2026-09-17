import type { ReactNode } from "react";
import { NavLink } from "react-router-dom";
import { canSocial, type SocialAbility, type User } from "../../api";
import { PageHeader } from "../../components/PageHeader";
import { copy, type Copy, type Locale } from "../../i18n";

type Props = {
  locale?: Locale;
  t: (c: Copy) => string;
  user: User | null;
  title: Copy;
  lede?: Copy;
  actions?: ReactNode;
  children: ReactNode;
};

const tabs: { to: string; end?: boolean; label: Copy; ability: SocialAbility }[] = [
  { to: "/social", end: true, label: copy.socialHome, ability: "create" },
  { to: "/social/compose", label: copy.socialCreate, ability: "create" },
  { to: "/social/links", label: copy.socialBioLinks, ability: "create" },
  { to: "/social/design", label: copy.socialDesign, ability: "create" },
  { to: "/social/calendar", label: copy.socialCalendar, ability: "create" },
  { to: "/social/inbox", label: copy.socialInbox, ability: "engage" },
  { to: "/social/accounts", label: copy.socialAccounts, ability: "accounts" },
];

export function SocialChrome({ t, user, title, lede, actions, children }: Props) {
  const visible = tabs.filter((tab) => canSocial(user, tab.ability) || (tab.ability === "create" && canSocial(user, "approve")));

  return (
    <div className="studio-shell">
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
        {canSocial(user, "create") ? (
          <NavLink className="btn btn-primary studio-create" to="/social?create=1">
            {t(copy.socialFindInspo)}
          </NavLink>
        ) : null}
      </div>
      <PageHeader title={t(title)} lede={lede ? t(lede) : undefined} actions={actions} />
      {children}
    </div>
  );
}
