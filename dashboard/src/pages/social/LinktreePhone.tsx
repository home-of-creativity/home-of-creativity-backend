import { Link } from "react-router-dom";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import type { SocialAccount, SocialPost } from "../../api";
import { copy, type Copy } from "../../i18n";
import { platformLabel, socialPlacementLabel, socialProfileUrl } from "./helpers";

export type LinktreeTheme = "cream" | "purple" | "dark";

export function LinktreePhone({
  t,
  dir,
  theme,
  name,
  handle,
  bio,
  accounts,
  stories,
  links,
  logoSrc,
}: {
  t: (c: Copy) => string;
  dir: "rtl" | "ltr";
  theme: LinktreeTheme;
  name: string;
  handle?: string | null;
  bio: string;
  accounts: SocialAccount[];
  stories: SocialPost[];
  links: SocialPost[];
  logoSrc: string;
}) {
  return (
    <div className="lt-phone-frame">
      <div className="lt-phone-notch" aria-hidden />
      <article className={`linktree-page is-${theme}`} dir={dir}>
      <header className="linktree-head">
        <span className="linktree-avatar">
          <img src={logoSrc} alt="" width={72} height={50} />
        </span>
        <h2 className="linktree-name">{name}</h2>
        {handle ? (
          <p className="linktree-handle">
            <bdi>@{handle.replace(/^@/, "")}</bdi>
          </p>
        ) : null}
        {bio ? <p className="linktree-bio">{bio}</p> : null}
        {accounts.length > 0 ? (
          <ul className="linktree-platforms">
            {accounts.map((account) => {
              const href = socialProfileUrl(account);
              const icon = <SocialBrandIcon platform={account.platform} className="linktree-platform-icon" />;
              return (
                <li key={account.id}>
                  {href ? (
                    <a href={href} target="_blank" rel="noreferrer" aria-label={platformLabel(account.platform, t)}>
                      {icon}
                    </a>
                  ) : (
                    <span title={platformLabel(account.platform, t)}>{icon}</span>
                  )}
                </li>
              );
            })}
          </ul>
        ) : null}
      </header>
      {stories.length > 0 ? (
        <section className="linktree-highlights" aria-label={t(copy.socialHighlights)}>
          {stories.slice(0, 8).map((item) => {
            const cover = item.media?.[0];
            return (
              <Link key={item.id} className="linktree-highlight" to={`/social/compose/${item.id}`}>
                <span className="linktree-highlight-ring">
                  {cover?.kind === "video" ? <video src={cover.url ?? undefined} muted playsInline /> : cover?.url ? <img src={cover.url} alt="" /> : <span>{socialPlacementLabel("story", t).slice(0, 1)}</span>}
                </span>
                <span>{item.body.slice(0, 16) || t(copy.socialHighlights)}</span>
              </Link>
            );
          })}
        </section>
      ) : null}
      <div className="linktree-links">
        {links.slice(0, 12).map((item) => {
          const cover = item.media?.[0];
          const title = (item.body.split("\n")[0]?.trim() || socialPlacementLabel(item.placement ?? "feed", t)).slice(0, 72);
          return (
            <Link key={item.id} className="linktree-link-hit" to={`/social/compose/${item.id}`}>
              <span className="linktree-thumb">
                {cover?.kind === "video" ? <video src={cover.url ?? undefined} muted playsInline /> : cover?.url ? <img src={cover.url} alt="" /> : <SocialBrandIcon platform={item.accounts?.[0]?.platform ?? "instagram"} />}
              </span>
              <span className="linktree-title">{title}</span>
            </Link>
          );
        })}
      </div>
      <footer className="linktree-brand">
        <img src={logoSrc} alt="" width={28} height={20} />
        <span>Home of Creativity</span>
      </footer>
    </article>
    </div>
  );
}
