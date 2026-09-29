import * as Sentry from "@sentry/react";
import { StrictMode, useEffect } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, useLocation } from "react-router-dom";
import { App } from "./App";
import { applyLocale, readLocale } from "./i18n";
import "./styles.css";

const sentryDsn = import.meta.env.VITE_SENTRY_DSN;
if (typeof sentryDsn === "string" && sentryDsn !== "") {
  Sentry.init({
    dsn: sentryDsn,
    tracesSampleRate: 0,
    ignoreErrors: [/navrix\.art/i, /selnor\.fun/i, /Failed to fetch dynamically imported module/i],
  });
}

applyLocale(readLocale());

const basename = (import.meta.env.BASE_URL || "/dashboard/").replace(/\/$/, "") || "/dashboard";

/** React Router renders `/` as the bare basename `/dashboard`. Put the slash back. */
function CanonicalDashboardSlash() {
  const location = useLocation();

  useEffect(() => {
    if (window.location.pathname !== "/dashboard") return;
    const next = `/dashboard/${window.location.search}${window.location.hash}`;
    window.history.replaceState(window.history.state, "", next);
  }, [location.pathname, location.search, location.hash, location.key]);

  return null;
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <BrowserRouter basename={basename}>
      <CanonicalDashboardSlash />
      <App />
    </BrowserRouter>
  </StrictMode>,
);
