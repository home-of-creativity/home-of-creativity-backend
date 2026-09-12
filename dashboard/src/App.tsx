import { NavLink, Navigate, Outlet, Route, Routes, useLocation } from "react-router-dom";
import { useEffect, useState, type ReactNode } from "react";
import { canSocial } from "./api";
import { AuthProvider, useAuth } from "./auth";
import { applyLocale, copy, readLocale, type Copy, type Locale } from "./i18n";
import { Clients } from "./pages/Clients";
import { Contact } from "./pages/Contact";
import { Employees } from "./pages/Employees";
import { Login } from "./pages/Login";
import { Overview } from "./pages/Overview";
import { Pricing } from "./pages/Pricing";
import { SocialAccounts } from "./pages/social/SocialAccounts";
import { SocialCalendar } from "./pages/social/SocialCalendar";
import { SocialCompose } from "./pages/social/SocialCompose";
import { SocialInbox } from "./pages/social/SocialInbox";
import { SocialPosts } from "./pages/social/SocialPosts";
import { PortfolioCategories } from "./pages/portfolio/PortfolioCategories";
import { PortfolioProjects } from "./pages/portfolio/PortfolioProjects";
import { LoadingLottie } from "./components/LoadingLottie";
import { RequestDetail } from "./pages/RequestDetail";
import { Requests } from "./pages/Requests";
import {
  IconCategories,
  IconClients,
  IconContact,
  IconEmployees,
  IconLanguage,
  IconLogout,
  IconOverview,
  IconPricing,
  IconProjects,
  IconRequests,
  IconSocial,
} from "./components/icons";

function tFactory(locale: Locale) {
  return (entry: Copy) => entry[locale];
}

function Shell({ locale, setLocale }: { locale: Locale; setLocale: (next: Locale) => void }) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const t = tFactory(locale);
  const [navOpen, setNavOpen] = useState(false);

  useEffect(() => {
    setNavOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    document.body.style.overflow = navOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [navOpen]);

  useEffect(() => {
    if (!navOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setNavOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [navOpen]);

  if (!user?.is_admin) return <Navigate to="/staff" replace />;

  return (
    <div className={navOpen ? "app-shell nav-open" : "app-shell"}>
      <a className="skip-link" href="#main-content">
        {t(copy.skipToContent)}
      </a>
      <header className="mobile-bar">
        <p className="brand">
          HOME <span>of</span> CREATIVITY
        </p>
        <button
          type="button"
          className="nav-toggle"
          aria-expanded={navOpen}
          aria-controls="dash-nav"
          aria-label={navOpen ? t(copy.closeMenu) : t(copy.menu)}
          onClick={() => setNavOpen((open) => !open)}
        >
          <span aria-hidden="true" className={navOpen ? "nav-toggle-bars is-open" : "nav-toggle-bars"}>
            <span />
            <span />
          </span>
        </button>
      </header>
      <button
        type="button"
        className="nav-backdrop"
        tabIndex={navOpen ? 0 : -1}
        aria-hidden={!navOpen}
        aria-label={t(copy.closeMenu)}
        onClick={() => setNavOpen(false)}
      />
      <aside id="dash-nav" className={navOpen ? "sidebar is-open" : "sidebar"}>
        <div className="sidebar-brand">
          <p className="brand">
            HOME <span>of</span> CREATIVITY
          </p>
          <p className="brand-mark">{t(copy.brandMark)}</p>
        </div>
        <nav className="nav-links" aria-label={t(copy.menu)}>
          <p className="nav-group-label">{t(copy.navOps)}</p>
          <NavLink to="/" end>
            <IconOverview aria-hidden />
            <span>{t(copy.overview)}</span>
          </NavLink>
          <NavLink to="/requests">
            <IconRequests aria-hidden />
            <span>{t(copy.requests)}</span>
          </NavLink>
          <NavLink to="/employees">
            <IconEmployees aria-hidden />
            <span>{t(copy.employees)}</span>
          </NavLink>
          <NavLink to="/clients">
            <IconClients aria-hidden />
            <span>{t(copy.clients)}</span>
          </NavLink>
          {canSocial(user, "create") || canSocial(user, "approve") || canSocial(user, "engage") || canSocial(user, "accounts") ? (
            <NavLink to="/social">
              <IconSocial aria-hidden />
              <span>{t(copy.navSocial)}</span>
            </NavLink>
          ) : null}
          <p className="nav-group-label">{t(copy.navSite)}</p>
          <NavLink to="/projects">
            <IconProjects aria-hidden />
            <span>{t(copy.portfolioTabProjects)}</span>
          </NavLink>
          <NavLink to="/categories">
            <IconCategories aria-hidden />
            <span>{t(copy.portfolioTabCategories)}</span>
          </NavLink>
          <NavLink to="/pricing">
            <IconPricing aria-hidden />
            <span>{t(copy.pricingTitle)}</span>
          </NavLink>
          <NavLink to="/contact">
            <IconContact aria-hidden />
            <span>{t(copy.contactTitle)}</span>
          </NavLink>
        </nav>
        <div className="sidebar-foot">
          <div className="staff-chip">
            <span className="staff-avatar" aria-hidden="true">
              {user.name.trim().charAt(0).toUpperCase() || "S"}
            </span>
            <span>
              <strong>{user.name}</strong>
              <small>{t(copy.staffChip)}</small>
            </span>
          </div>
          <div className="sidebar-actions">
            <button type="button" className="btn btn-ghost" onClick={() => setLocale(locale === "ar" ? "en" : "ar")}>
              <IconLanguage aria-hidden />
              {t(copy.language)}
            </button>
            <button type="button" className="btn btn-ghost btn-logout" onClick={() => void logout()}>
              <IconLogout aria-hidden />
              {t(copy.logout)}
            </button>
          </div>
        </div>
      </aside>
      <main className="main" id="main-content" tabIndex={-1}>
        <Outlet />
      </main>
    </div>
  );
}

function Guarded({ children }: { children: ReactNode }) {
  const { user, ready } = useAuth();
  if (!ready) return <LoadingLottie variant="page" label={copy.loading[readLocale()]} />;
  if (!user?.is_admin) return <Navigate to="/staff" replace />;
  return children;
}

export function App() {
  const [locale, setLocaleState] = useState<Locale>(() => {
    const next = readLocale();
    applyLocale(next);
    return next;
  });
  const t = tFactory(locale);

  function setLocale(next: Locale) {
    applyLocale(next);
    setLocaleState(next);
  }

  return (
    <AuthProvider>
      <Routes>
        <Route path="/staff" element={<Login locale={locale} t={t} setLocale={setLocale} />} />
        <Route path="/login" element={<Navigate to="/staff" replace />} />
        <Route
          element={
            <Guarded>
              <Shell locale={locale} setLocale={setLocale} />
            </Guarded>
          }
        >
          <Route path="/" element={<Overview locale={locale} t={t} />} />
          <Route path="/requests" element={<Requests locale={locale} t={t} />} />
          <Route path="/requests/:id" element={<RequestDetail locale={locale} t={t} />} />
          <Route path="/employees" element={<Employees locale={locale} t={t} />} />
          <Route path="/clients" element={<Clients locale={locale} t={t} />} />
          <Route path="/contact" element={<Contact locale={locale} t={t} />} />
          <Route path="/social" element={<SocialPosts locale={locale} t={t} />} />
          <Route path="/social/compose" element={<SocialCompose locale={locale} t={t} />} />
          <Route path="/social/compose/:id" element={<SocialCompose locale={locale} t={t} />} />
          <Route path="/social/calendar" element={<SocialCalendar locale={locale} t={t} />} />
          <Route path="/social/inbox" element={<SocialInbox locale={locale} t={t} />} />
          <Route path="/social/accounts" element={<SocialAccounts locale={locale} t={t} />} />
          <Route path="/client-logos" element={<Navigate to="/clients?tab=logos" replace />} />
          <Route path="/projects" element={<PortfolioProjects locale={locale} t={t} />} />
          <Route path="/categories" element={<PortfolioCategories locale={locale} t={t} />} />
          <Route path="/pricing" element={<Pricing locale={locale} t={t} />} />
          <Route path="/portfolio/clients" element={<Navigate to="/clients?tab=logos" replace />} />
          <Route path="/portfolio/categories" element={<Navigate to="/categories" replace />} />
          <Route path="/portfolio/*" element={<Navigate to="/projects" replace />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
