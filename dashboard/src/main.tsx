import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { App } from "./App";
import { applyLocale, readLocale } from "./i18n";
import "./styles.css";

applyLocale(readLocale());

const basename = (import.meta.env.BASE_URL || "/dashboard/").replace(/\/$/, "") || "/dashboard";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <BrowserRouter basename={basename}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
