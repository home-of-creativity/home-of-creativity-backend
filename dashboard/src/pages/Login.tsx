import { useEffect, useState, type FormEvent } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../auth";
import { LoadingLottie } from "../components/LoadingLottie";
import { copy, type Copy, type Locale } from "../i18n";

type LoginPhase = "idle" | "loading" | "success" | "error";

export function Login({
  locale,
  t,
  setLocale,
}: {
  locale: Locale;
  t: (c: Copy) => string;
  setLocale: (next: Locale) => void;
}) {
  const { user, login, ready } = useAuth();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [phase, setPhase] = useState<LoginPhase>("idle");
  const [error, setError] = useState("");
  const [redirect, setRedirect] = useState(false);
  const fromPath = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname;
  const afterLoginPath = fromPath && fromPath !== "/login" ? fromPath : "/";

  useEffect(() => {
    if (phase !== "success") return;
    const timer = window.setTimeout(() => setRedirect(true), 700);
    return () => window.clearTimeout(timer);
  }, [phase]);

  if (!ready) {
    return (
      <main className="login">
        <LoadingLottie variant="page" label={t(copy.loading)} />
      </main>
    );
  }

  if (user?.is_admin && phase === "idle") {
    return <Navigate to={afterLoginPath} replace />;
  }

  if (redirect && user?.is_admin) {
    return <Navigate to={afterLoginPath} replace />;
  }

  const busy = phase === "loading" || phase === "success";

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setPhase("loading");
    setError("");
    try {
      await login(email, password);
      setPhase("success");
    } catch (err) {
      setPhase("error");
      setError(
        err instanceof Error && err.message === "forbidden"
          ? t(copy.forbidden)
          : err instanceof Error && err.message
            ? err.message
            : t(copy.failed),
      );
    }
  }

  return (
    <main className="login">
      <button type="button" className="btn btn-ghost login-locale" onClick={() => setLocale(locale === "ar" ? "en" : "ar")}>
        {t(copy.language)}
      </button>
      <div className="login-grid">
        <form
          className={`form-card stack login-card${busy ? " login-card--busy" : ""}`}
          onSubmit={onSubmit}
          aria-busy={busy}
        >
          <p className="eyebrow">{t(copy.staffChip)}</p>
          <p className="brand-lockup">
            HOME <span>of</span> CREATIVITY
          </p>
          <h1>{t(copy.login)}</h1>
          <p className="muted">{t(copy.loginLede)}</p>

          {phase === "success" ? (
            <p className="login-feedback login-feedback--success" role="status" aria-live="polite">
              {t(copy.loginSuccess)}
            </p>
          ) : null}

          {phase === "error" && error ? (
            <p className="login-feedback login-feedback--error" role="alert">
              {error}
            </p>
          ) : null}

          <label className="field-label" htmlFor="staff-email">
            {t(copy.email)}
            <input
              id="staff-email"
              className="field"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                if (phase === "error") {
                  setPhase("idle");
                  setError("");
                }
              }}
              disabled={busy}
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
              onChange={(e) => {
                setPassword(e.target.value);
                if (phase === "error") {
                  setPhase("idle");
                  setError("");
                }
              }}
              disabled={busy}
              required
            />
          </label>

          {phase === "loading" ? (
            <LoadingLottie variant="inline" label={t(copy.loginSigningIn)} />
          ) : null}

          <button className="btn btn-primary" type="submit" disabled={busy}>
            {phase === "loading"
              ? t(copy.loginSigningIn)
              : phase === "success"
                ? t(copy.loginRedirecting)
                : t(copy.submit)}
          </button>
          {import.meta.env.VITE_TELEGRAM_STAFF_BOT ? (
            <a
              className="btn btn-telegram"
              href={`https://t.me/${import.meta.env.VITE_TELEGRAM_STAFF_BOT}`}
              target="_blank"
              rel="noreferrer"
              aria-disabled={busy}
              tabIndex={busy ? -1 : undefined}
            >
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
