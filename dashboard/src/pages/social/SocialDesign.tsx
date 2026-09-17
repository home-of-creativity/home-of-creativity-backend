import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { api, type SocialLinktreeProfile } from "../../api";
import { useAuth } from "../../auth";
import { copy, type Locale } from "../../i18n";
import { LinktreePhone, type LinktreeTheme } from "./LinktreePhone";
import { SocialChrome } from "./SocialChrome";

const themes: LinktreeTheme[] = ["cream", "purple", "dark"];

export function SocialDesign({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [form, setForm] = useState<SocialLinktreeProfile>({ display_name: "", bio: "", theme: "cream" });
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const logoSrc = `${import.meta.env.BASE_URL}hummingbird.svg`;

  useEffect(() => {
    api
      .socialProfile()
      .then((res) => setForm(res.data))
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)));
  }, [t]);

  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    try {
      const res = await api.updateSocialProfile(form);
      setForm(res.data);
      toast.success(t(copy.socialAppearanceSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.loading));
    } finally {
      setSaving(false);
    }
  }

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialDesign} lede={copy.socialDesignLede}>
      <div className="lt-admin">
        <form className="card stack lt-admin-list" onSubmit={(event) => void save(event)}>
          {error ? <p className="error">{error}</p> : null}
          <label className="field-label">
            {t(copy.socialDisplayName)}
            <input
              className="field"
              value={form.display_name}
              onChange={(event) => setForm((current) => ({ ...current, display_name: event.target.value }))}
              maxLength={80}
            />
          </label>
          <label className="field-label">
            {t(copy.socialLinktreeBio)}
            <textarea
              className="field"
              rows={4}
              value={form.bio}
              onChange={(event) => setForm((current) => ({ ...current, bio: event.target.value }))}
              maxLength={280}
            />
          </label>
          <fieldset className="field-label">
            <legend>{t(copy.socialDesign)}</legend>
            <div className="studio-theme-picks">
              {themes.map((theme) => (
                <label key={theme} className={form.theme === theme ? "studio-theme is-on" : "studio-theme"}>
                  <input
                    type="radio"
                    name="linktree-theme"
                    checked={form.theme === theme}
                    onChange={() => setForm((current) => ({ ...current, theme }))}
                  />
                  <span className={`studio-theme-swatch is-${theme}`} aria-hidden />
                  {theme === "cream" ? t(copy.socialThemeCream) : theme === "purple" ? t(copy.socialThemePurple) : t(copy.socialThemeDark)}
                </label>
              ))}
            </div>
          </fieldset>
          <button type="submit" className="btn btn-primary" disabled={saving}>
            {t(copy.socialSaveAppearance)}
          </button>
        </form>
        <aside className="lt-admin-preview">
          <LinktreePhone
            t={t}
            dir={locale === "ar" ? "rtl" : "ltr"}
            theme={form.theme}
            name={form.display_name || "Home of Creativity"}
            bio={form.bio || t(copy.socialLinktreeBio)}
            accounts={[]}
            stories={[]}
            links={[]}
            logoSrc={logoSrc}
          />
        </aside>
      </div>
    </SocialChrome>
  );
}
