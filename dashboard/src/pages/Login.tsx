import { useState, type FormEvent } from "react";
import { Navigate } from "react-router-dom";
import { useAuth } from "../auth";
import { copy, type Copy, type Locale } from "../i18n";

export function Login({
  locale,
  t,
  setLocale,
}: {
  locale: Locale;
  t: (c: Copy) => string;
  setLocale: (next: Locale) => void;
}) {
  const { user, login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  if (user?.is_admin) return <Navigate to="/" replace />;

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError("");
    try {
      await login(email, password);
    } catch (err) {
      setError(err instanceof Error && err.message === "forbidden" ? t(copy.forbidden) : t(copy.failed));
    }
  }

  return (
    <main className="login">
      <button type="button" className="btn btn-ghost login-locale" onClick={() => setLocale(locale === "ar" ? "en" : "ar")}>
        {t(copy.language)}
      </button>
      <div className="login-grid">
        <form className="form-card stack login-card" onSubmit={onSubmit}>
          <p className="eyebrow">{t(copy.staffChip)}</p>
          <p className="brand-lockup">
            HOME <span>of</span> CREATIVITY
          </p>
          <h1>{t(copy.login)}</h1>
          <p className="muted">{t(copy.loginLede)}</p>
          <label className="field-label" htmlFor="staff-email">
            {t(copy.email)}
            <input
              id="staff-email"
              className="field"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </label>
          <label className="field-label" htmlFor="staff-password">
            {t(copy.password)}
            <input
              id="staff-password"
              className="field"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </label>
          {error ? <p className="error">{error}</p> : null}
          <button className="btn btn-primary" type="submit">
            {t(copy.submit)}
          </button>
          {import.meta.env.VITE_TELEGRAM_STAFF_BOT ? (
            <a className="btn btn-telegram" href={`https://t.me/${import.meta.env.VITE_TELEGRAM_STAFF_BOT}`} target="_blank" rel="noreferrer">
              {t(copy.openStaffBot)}
            </a>
          ) : null}
        </form>
        <aside className="form-card signup-card">
          <p className="eyebrow">{t(copy.clients)}</p>
          <h2>{t(copy.signupTitle)}</h2>
          <p className="muted">{t(copy.signupLede)}</p>
          <ol className="signup-steps">
            <li>{t(copy.signupStep1)}</li>
            <li>{t(copy.signupStep2)}</li>
            <li>{t(copy.signupStep3)}</li>
          </ol>
          {import.meta.env.VITE_TELEGRAM_BOT ? (
            <a className="btn btn-telegram" href={`https://t.me/${import.meta.env.VITE_TELEGRAM_BOT}`} target="_blank" rel="noreferrer">
              {t(copy.signupCta)}
            </a>
          ) : null}
        </aside>
      </div>
    </main>
  );
}
