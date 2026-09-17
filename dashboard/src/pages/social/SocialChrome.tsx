import type { ReactNode } from "react";
import { NavLink } from "react-router-dom";
import { canSocial, type SocialAbility, type User } from "../../api";
import { PageHeader } from "../../components/PageHeader";
import { copy, type Copy, type Locale } from "../../i18n";

type Props = {
  locale: Locale;
  t: (c: Copy) => string;
  user: User | null;
  title: Copy;
  lede: Copy;
  actions?: ReactNode;
  children: ReactNode;
};

const tabs: { to: string; end?: boolean; label: Copy; ability: SocialAbility }[] = [
  { to: "/social", end: true, label: copy.socialPosts, ability: "create" },
  { to: "/social/compose", label: copy.socialCompose, ability: "create" },
  { to: "/social/calendar", label: copy.socialCalendar, ability: "create" },
  { to: "/social/inbox", label: copy.socialInbox, ability: "engage" },
  { to: "/social/accounts", label: copy.socialAccounts, ability: "accounts" },
];

export function SocialChrome({ t, user, title, lede, actions, children }: Props) {
  const visible = tabs.filter((tab) => canSocial(user, tab.ability) || (tab.ability === "create" && canSocial(user, "approve")));

  return (
    <>
      <PageHeader eyebrow={t(copy.navSocial)} title={t(title)} lede={lede ? t(lede) : undefined} actions={actions} />
      <div className="tabs" role="tablist" aria-label={t(copy.navSocial)}>
        {visible.map((tab) => (
          <NavLink
            key={tab.to}
            to={tab.to}
            end={tab.end}
            role="tab"
            className={({ isActive }) => (isActive ? "tab is-active" : "tab")}
          >
            {t(tab.label)}
          </NavLink>
        ))}
      </div>
      {children}
    </>
  );
}
