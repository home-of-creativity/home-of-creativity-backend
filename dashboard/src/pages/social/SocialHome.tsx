import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { api, canSocial, type SocialAccount, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { LIVE_SOCIAL_PLATFORMS, SOCIAL_PLATFORMS, STUDIO_HOME_CARDS, type SocialPlatformId } from "./catalog";
import { PlatformPicker } from "./PlatformPicker";
import { SocialChrome } from "./SocialChrome";
import { platformLabel, socialStatusLabel } from "./helpers";

export function SocialHome({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [posts, setPosts] = useState<SocialPost[]>([]);
  const [picker, setPicker] = useState(false);
  const [selected, setSelected] = useState<SocialPlatformId[]>([...LIVE_SOCIAL_PLATFORMS]);

  useEffect(() => {
    api.socialAccounts().then((res) => setAccounts(res.data.filter((row) => row.is_active))).catch(() => setAccounts([]));
    api.socialPosts({ per_page: 6 }).then((res) => setPosts(res.data)).catch(() => setPosts([]));
  }, []);

  useEffect(() => {
    if (params.get("create") === "1") setPicker(true);
  }, [params]);

  const connected = useMemo(() => {
    const seen = new Set<string>();
    return accounts.filter((account) => {
      if (seen.has(account.platform)) return false;
      seen.add(account.platform);
      return true;
    });
  }, [accounts]);

  const connectedIds = useMemo(() => new Set(connected.map((account) => account.platform)), [connected]);

  function closePicker() {
    setPicker(false);
    if (params.get("create") === "1") {
      const next = new URLSearchParams(params);
      next.delete("create");
      setParams(next, { replace: true });
    }
  }

  function startCreating() {
    const live = selected.filter((id) => LIVE_SOCIAL_PLATFORMS.includes(id));
    const query = live.length ? `?platforms=${live.join(",")}` : "";
    closePicker();
    navigate(`/social/compose${query}`);
  }

  function openPicker(initial?: SocialPlatformId) {
    if (initial && LIVE_SOCIAL_PLATFORMS.includes(initial)) {
      setSelected((current) => (current.includes(initial) ? current : [...current, initial]));
    }
    setPicker(true);
  }

  const welcome = t(copy.socialWelcome).replace("{name}", user?.name?.split(" ")[0] || "HOC");
  const cardCopy = {
    idea: { title: copy.socialNeedIdea, body: copy.socialNeedIdeaBody, cta: copy.socialFindInspo },
    calendar: { title: copy.socialTemplates, body: copy.socialTemplatesBody, cta: copy.socialCheckTemplates },
    own: { title: copy.socialOwnIdea, body: copy.socialOwnIdeaBody, cta: copy.socialGetStarted },
  } as const;

  return (
    <SocialChrome locale={locale} t={t} user={user} title={{ ar: welcome, en: welcome }} lede={copy.socialHomeLede}>
      <section className="studio-platforms" aria-label={t(copy.socialAccounts)}>
        {SOCIAL_PLATFORMS.map((item) => {
          const on = connectedIds.has(item.id);
          return (
            <button
              key={item.id}
              type="button"
              className={on ? "studio-chip is-on" : item.live ? "studio-chip" : "studio-chip is-soon"}
              disabled={!item.live}
              title={item.live ? platformLabel(item.id, t) : t(copy.socialComingSoon)}
              onClick={() => item.live && openPicker(item.id)}
            >
              <span className={`studio-chip-icon is-${item.id}`}>
                <SocialBrandIcon platform={item.id} />
              </span>
              <span>{platformLabel(item.id, t)}</span>
              {!item.live ? <small>{t(copy.socialComingSoon)}</small> : null}
            </button>
          );
        })}
        {canSocial(user, "accounts") ? (
          <Link className="studio-chip is-add" to="/social/accounts">
            + {t(copy.socialAddAccount)}
          </Link>
        ) : null}
      </section>

      <div className="studio-cards">
        {STUDIO_HOME_CARDS.map((card) => {
          const text = cardCopy[card.id];
          return (
            <article key={card.id} className={`studio-card is-${card.tone}`}>
              <div className="studio-card-art" aria-hidden>
                <span />
                <span />
                <span />
              </div>
              <h2>{t(text.title)}</h2>
              <p>{t(text.body)}</p>
              {card.id === "idea" ? (
                <button type="button" className="btn btn-primary" onClick={() => openPicker()}>
                  {t(text.cta)}
                </button>
              ) : (
                <Link className="btn" to={card.to}>
                  {t(text.cta)}
                </Link>
              )}
            </article>
          );
        })}
      </div>

      <section className="panel recent-panel">
        <div className="panel-head">
          <h2>{t(copy.socialRecentPosts)}</h2>
          <Link className="btn btn-ghost" to="/social/links">
            {t(copy.socialBioLinks)}
          </Link>
        </div>
        {posts.length === 0 ? <p className="muted empty-copy">{t(copy.socialNoPosts)}</p> : (
          <ul className="studio-recent">
            {posts.map((item) => (
              <li key={item.id}>
                <Link to={`/social/compose/${item.id}`}>
                  <strong>{item.body.slice(0, 72) || t(copy.socialCompose)}</strong>
                  <small>{socialStatusLabel(item.status, t)}</small>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      {picker ? (
        <PlatformPicker
          t={t}
          selected={selected}
          onToggle={(id) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))}
          onStart={startCreating}
          onClose={closePicker}
        />
      ) : null}
    </SocialChrome>
  );
}
