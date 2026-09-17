import { useEffect } from "react";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Copy } from "../../i18n";
import { SOCIAL_PLATFORMS, type SocialPlatformId } from "./catalog";
import { platformLabel } from "./helpers";

export function PlatformPicker({
  t,
  selected,
  onToggle,
  onStart,
  onClose,
}: {
  t: (c: Copy) => string;
  selected: SocialPlatformId[];
  onToggle: (id: SocialPlatformId) => void;
  onStart: () => void;
  onClose: () => void;
}) {
  const liveSelected = selected.filter((id) => SOCIAL_PLATFORMS.find((item) => item.id === id)?.live);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="studio-modal" role="dialog" aria-modal="true" aria-labelledby="platform-picker-title">
      <button type="button" className="studio-modal-backdrop" aria-label={t(copy.cancel)} onClick={onClose} />
      <div className="studio-modal-card">
        <h2 id="platform-picker-title">{t(copy.socialPickPlatformsTitle)}</h2>
        <ul className="studio-platform-grid">
          {SOCIAL_PLATFORMS.map((item) => {
            const on = selected.includes(item.id);
            return (
              <li key={item.id}>
                <button
                  type="button"
                  className={on ? "studio-platform is-on" : "studio-platform"}
                  disabled={!item.live}
                  aria-pressed={item.live ? on : undefined}
                  onClick={() => item.live && onToggle(item.id)}
                >
                  <span className={`studio-platform-icon is-${item.id}`}>
                    <SocialBrandIcon platform={item.id} />
                  </span>
                  <span>{platformLabel(item.id, t)}</span>
                  {!item.live ? <small>{t(copy.socialComingSoon)}</small> : null}
                </button>
              </li>
            );
          })}
        </ul>
        <button type="button" className="btn btn-primary studio-start" disabled={liveSelected.length === 0} onClick={onStart}>
          {t(copy.socialStartCreating)}
        </button>
      </div>
    </div>
  );
}
