import * as Sentry from "@sentry/react";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { App } from "./App";
import { applyLocale, readLocale } from "./i18n";
import "./styles.css";

const sentryDsn = import.meta.env.VITE_SENTRY_DSN;
if (typeof sentryDsn === "string" && sentryDsn !== "") {
  Sentry.init({ dsn: sentryDsn, tracesSampleRate: 0 });
}

applyLocale(readLocale());

const basename = (import.meta.env.BASE_URL || "/dashboard/").replace(/\/$/, "") || "/dashboard";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <BrowserRouter basename={basename}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
