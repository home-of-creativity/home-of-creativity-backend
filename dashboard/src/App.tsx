import { NavLink, Navigate, Outlet, Route, Routes, useLocation } from "react-router-dom";
import { AnimatePresence, motion, MotionConfig } from "framer-motion";
import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";
import { Toaster } from "sonner";
import { api, canSocial } from "./api";
import { AuthProvider, useAuth } from "./auth";
import { applyLocale, applyTheme, copy, readLocale, readTheme, type Copy, type Locale, type Theme } from "./i18n";
import { Moon, Search, Sun } from "lucide-react";
import { BrandLockup } from "./components/BrandLockup";
import { CommandPalette, type CommandItem } from "./components/CommandPalette";
import { ClientForm } from "./pages/ClientForm";
import { Clients } from "./pages/Clients";
import { Contact } from "./pages/Contact";
import { ContactChannelForm } from "./pages/ContactChannelForm";
import { EmployeeForm } from "./pages/EmployeeForm";
import { Employees } from "./pages/Employees";
import { Login } from "./pages/Login";
import { Overview } from "./pages/Overview";
import { Pricing } from "./pages/Pricing";
import { PricingCategoryForm } from "./pages/pricing/PricingCategoryForm";
import { PricingPackageForm } from "./pages/pricing/PricingPackageForm";
import { PricingSubcategoryForm } from "./pages/pricing/PricingSubcategoryForm";
import { SocialHome } from "./pages/social/SocialHome";
import { SocialLinks } from "./pages/social/SocialLinks";
import { SocialDesign } from "./pages/social/SocialDesign";
import { SocialAccounts } from "./pages/social/SocialAccounts";
import { SocialAccountForm } from "./pages/social/SocialAccountForm";
import { SocialCalendar } from "./pages/social/SocialCalendar";
import { SocialCompose } from "./pages/social/SocialCompose";
import { SocialInbox } from "./pages/social/SocialInbox";
import { ClientLogoForm } from "./pages/portfolio/ClientLogoForm";
import { PortfolioCategories } from "./pages/portfolio/PortfolioCategories";
import { PortfolioCategoryForm } from "./pages/portfolio/PortfolioCategoryForm";
import { PortfolioProjectForm } from "./pages/portfolio/PortfolioProjectForm";
import { PortfolioProjects } from "./pages/portfolio/PortfolioProjects";
import { LoadingLottie } from "./components/LoadingLottie";
import { RequestDetail } from "./pages/RequestDetail";
import { Requests } from "./pages/Requests";
import { Payments } from "./pages/Payments";
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
  IconQr,
  IconReels,
  IconRequests,
  IconSocial,
} from "./components/icons";

const LandingReels = lazy(() => import("./pages/LandingReels"));
const ReelForm = lazy(() => import("./pages/ReelForm"));

function tFactory(locale: Locale) {
  return (entry: Copy) => entry[locale];
}

function Shell({
  locale,
  setLocale,
  theme,
  setTheme,
}: {
  locale: Locale;
  setLocale: (next: Locale) => void;
  theme: Theme;
  setTheme: (next: Theme) => void;
}) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const t = tFactory(locale);
  const [navOpen, setNavOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [badges, setBadges] = useState({ pendingEmployees: 0, openRequests: 0 });

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

  useEffect(() => {
    function loadBadges() {
      api
        .employees()
        .then((res) => setBadges((prev) => ({ ...prev, pendingEmployees: res.data.filter((row) => row.status === "pending").length })))
        .catch(() => {});
      api
        .requests()
        .then((res) => setBadges((prev) => ({ ...prev, openRequests: res.meta.total })))
        .catch(() => {});
    }
    loadBadges();
    const timer = window.setInterval(loadBadges, 30000);
    return () => window.clearInterval(timer);
  }, []);

  const commandItems: CommandItem[] = [
    { id: "overview", label: t(copy.overview), to: "/", icon: <IconOverview aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "requests", label: t(copy.requests), to: "/requests", icon: <IconRequests aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "employees", label: t(copy.employees), to: "/employees", icon: <IconEmployees aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "clients", label: t(copy.clients), to: "/clients", icon: <IconClients aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "social", label: t(copy.navSocial), to: "/social", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "social-links", label: t(copy.socialBioLinks), to: "/social/links", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "projects", label: t(copy.portfolioTabProjects), to: "/projects", icon: <IconProjects aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "reels", label: t(copy.reelsTitle), to: "/reels", icon: <IconReels aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "categories", label: t(copy.portfolioTabCategories), to: "/categories", icon: <IconCategories aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "pricing", label: t(copy.pricingTitle), to: "/pricing", icon: <IconPricing aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "contact", label: t(copy.contactTitle), to: "/contact", icon: <IconContact aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "sham-cash", label: t(copy.navPayments), to: "/payments", icon: <IconQr aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "add-employee", label: t(copy.addEmployee), to: "/employees/new", icon: <IconEmployees aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "add-client", label: t(copy.addClient), to: "/clients/new", icon: <IconClients aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "add-reel", label: t(copy.addReel), to: "/reels/new", icon: <IconReels aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "compose-post", label: t(copy.socialCompose), to: "/social/compose", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
  ];

  if (!user?.is_admin) return <Navigate to="/" replace />;

  return (
    <div className={navOpen ? "app-shell nav-open" : "app-shell"}>
      <a className="skip-link" href="#main-content">
        {t(copy.skipToContent)}
      </a>
      <header className="mobile-bar">
        <p className="brand">
          <BrandLockup compact inverted />
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
          <BrandLockup inverted />
          <p className="brand-mark">{t(copy.brandMark)}</p>
        </div>
        <nav className="nav-links" aria-label={t(copy.menu)}>
          <p className="nav-group-label">{t(copy.navOps)}</p>
          <NavLink to="/" end>
            <IconOverview aria-hidden />
            <span>{t(copy.overview)}</span>
          </NavLink>
          <NavLink to="/requests">
            <span className="nav-link-row">
              <IconRequests aria-hidden />
              <span>{t(copy.requests)}</span>
              {badges.openRequests > 0 ? <span className="nav-badge">{badges.openRequests}</span> : null}
            </span>
          </NavLink>
          <NavLink to="/employees">
            <span className="nav-link-row">
              <IconEmployees aria-hidden />
              <span>{t(copy.employees)}</span>
              {badges.pendingEmployees > 0 ? <span className="nav-badge">{badges.pendingEmployees}</span> : null}
            </span>
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
          <NavLink to="/payments">
            <IconQr aria-hidden />
            <span>{t(copy.navPayments)}</span>
          </NavLink>
          <p className="nav-group-label">{t(copy.navSite)}</p>
          <NavLink to="/projects">
            <IconProjects aria-hidden />
            <span>{t(copy.portfolioTabProjects)}</span>
          </NavLink>
          <NavLink to="/reels">
            <IconReels aria-hidden />
            <span>{t(copy.reelsTitle)}</span>
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
            <button type="button" className="btn btn-ghost command-trigger" onClick={() => setPaletteOpen(true)}>
              <Search size={16} aria-hidden />
              {t(copy.search)}
              <kbd className="command-trigger-kbd">Ctrl K</kbd>
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setLocale(locale === "ar" ? "en" : "ar")}>
              <IconLanguage aria-hidden />
              {t(copy.language)}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setTheme(theme === "dark" ? "light" : "dark")}>
              {theme === "dark" ? <Sun size={16} aria-hidden /> : <Moon size={16} aria-hidden />}
              {theme === "dark" ? t(copy.lightMode) : t(copy.darkMode)}
            </button>
            <button type="button" className="btn btn-ghost btn-logout" onClick={() => void logout()}>
              <IconLogout aria-hidden />
              {t(copy.logout)}
            </button>
          </div>
        </div>
      </aside>
      <main className="main" id="main-content" tabIndex={-1}>
        <AnimatePresence mode="wait" initial={false}>
          <motion.div
            key={location.pathname}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            transition={{ duration: 0.18, ease: "easeOut" }}
          >
            <Outlet />
          </motion.div>
        </AnimatePresence>
      </main>
      <CommandPalette items={commandItems} locale={locale} t={t} open={paletteOpen} setOpen={setPaletteOpen} />
    </div>
  );
}

function Guarded({
  children,
  locale,
  t,
  setLocale,
}: {
  children: ReactNode;
  locale: Locale;
  t: (c: Copy) => string;
  setLocale: (next: Locale) => void;
}) {
  const { user, ready } = useAuth();
  const location = useLocation();
  if (!ready) return <LoadingLottie variant="page" label={copy.loading[readLocale()]} />;
  if (!user?.is_admin) {
    if (location.pathname === "/") {
      return <Login locale={locale} t={t} setLocale={setLocale} />;
    }

    return <Navigate to="/" replace state={{ from: location }} />;
  }

  const fromPath = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname;
  if (location.pathname === "/" && fromPath && fromPath !== "/" && fromPath !== "/login") {
    return <Navigate to={fromPath} replace />;
  }

  return children;
}

export function App() {
  const [locale, setLocaleState] = useState<Locale>(() => {
    const next = readLocale();
    applyLocale(next);
    return next;
  });
  const [theme, setThemeState] = useState<Theme>(() => {
    const next = readTheme();
    applyTheme(next);
    return next;
  });
  const t = tFactory(locale);

  function setLocale(next: Locale) {
    applyLocale(next);
    setLocaleState(next);
  }

  function setTheme(next: Theme) {
    applyTheme(next);
    setThemeState(next);
  }

  return (
    <MotionConfig reducedMotion="user">
    <Toaster
      position={locale === "ar" ? "top-left" : "top-right"}
      dir={locale === "ar" ? "rtl" : "ltr"}
      richColors
      closeButton
    />
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Navigate to="/" replace />} />
        <Route path="/staff" element={<Navigate to="/" replace />} />
        <Route
          element={
            <Guarded locale={locale} t={t} setLocale={setLocale}>
              <Shell locale={locale} setLocale={setLocale} theme={theme} setTheme={setTheme} />
            </Guarded>
          }
        >
          <Route path="/" element={<Overview locale={locale} t={t} />} />
          <Route path="/requests" element={<Requests locale={locale} t={t} />} />
          <Route path="/requests/:id" element={<RequestDetail locale={locale} t={t} />} />
          <Route path="/employees" element={<Employees locale={locale} t={t} />} />
          <Route path="/employees/new" element={<EmployeeForm locale={locale} t={t} />} />
          <Route path="/employees/:id/edit" element={<EmployeeForm locale={locale} t={t} />} />
          <Route path="/employees/:id/approve" element={<EmployeeForm locale={locale} t={t} />} />
          <Route path="/clients" element={<Clients locale={locale} t={t} />} />
          <Route path="/clients/new" element={<ClientForm locale={locale} t={t} />} />
          <Route path="/clients/:id/edit" element={<ClientForm locale={locale} t={t} />} />
          <Route path="/clients/logos/new" element={<ClientLogoForm locale={locale} t={t} />} />
          <Route path="/clients/logos/:id/edit" element={<ClientLogoForm locale={locale} t={t} />} />
          <Route path="/contact" element={<Contact locale={locale} t={t} />} />
          <Route path="/contact/new" element={<ContactChannelForm locale={locale} t={t} />} />
          <Route path="/contact/:id/edit" element={<ContactChannelForm locale={locale} t={t} />} />
          <Route path="/social" element={<SocialHome locale={locale} t={t} />} />
          <Route path="/social/links" element={<SocialLinks locale={locale} t={t} />} />
          <Route path="/social/design" element={<SocialDesign locale={locale} t={t} />} />
          <Route path="/social/compose" element={<SocialCompose locale={locale} t={t} />} />
          <Route path="/social/compose/:id" element={<SocialCompose locale={locale} t={t} />} />
          <Route path="/social/calendar" element={<SocialCalendar locale={locale} t={t} />} />
          <Route path="/social/inbox" element={<SocialInbox locale={locale} t={t} />} />
          <Route path="/social/accounts" element={<SocialAccounts locale={locale} t={t} />} />
          <Route path="/social/accounts/new" element={<SocialAccountForm locale={locale} t={t} />} />
          <Route path="/social/accounts/:id/edit" element={<SocialAccountForm locale={locale} t={t} />} />
          <Route path="/payments" element={<Payments locale={locale} t={t} />} />
          <Route path="/client-logos" element={<Navigate to="/clients?tab=logos" replace />} />
          <Route path="/projects" element={<PortfolioProjects locale={locale} t={t} />} />
          <Route path="/projects/new" element={<PortfolioProjectForm locale={locale} t={t} />} />
          <Route path="/projects/:id/edit" element={<PortfolioProjectForm locale={locale} t={t} />} />
          <Route
            path="/reels"
            element={
              <Suspense fallback={<LoadingLottie variant="page" label={t(copy.loading)} />}>
                <LandingReels locale={locale} t={t} />
              </Suspense>
            }
          />
          <Route
            path="/reels/new"
            element={
              <Suspense fallback={<LoadingLottie variant="page" label={t(copy.loading)} />}>
                <ReelForm locale={locale} t={t} />
              </Suspense>
            }
          />
          <Route
            path="/reels/:id/edit"
            element={
              <Suspense fallback={<LoadingLottie variant="page" label={t(copy.loading)} />}>
                <ReelForm locale={locale} t={t} />
              </Suspense>
            }
          />
          <Route path="/categories" element={<PortfolioCategories locale={locale} t={t} />} />
          <Route path="/categories/new" element={<PortfolioCategoryForm locale={locale} t={t} />} />
          <Route path="/categories/:id/edit" element={<PortfolioCategoryForm locale={locale} t={t} />} />
          <Route path="/pricing" element={<Pricing locale={locale} t={t} />} />
          <Route path="/pricing/categories/new" element={<PricingCategoryForm locale={locale} t={t} />} />
          <Route path="/pricing/categories/:id/edit" element={<PricingCategoryForm locale={locale} t={t} />} />
          <Route path="/pricing/subcategories/new" element={<PricingSubcategoryForm locale={locale} t={t} />} />
          <Route path="/pricing/subcategories/:id/edit" element={<PricingSubcategoryForm locale={locale} t={t} />} />
          <Route path="/pricing/packages/new" element={<PricingPackageForm locale={locale} t={t} />} />
          <Route path="/pricing/packages/:id/edit" element={<PricingPackageForm locale={locale} t={t} />} />
          <Route path="/portfolio/clients" element={<Navigate to="/clients?tab=logos" replace />} />
          <Route path="/portfolio/categories" element={<Navigate to="/categories" replace />} />
          <Route path="/portfolio/*" element={<Navigate to="/projects" replace />} />
        </Route>
      </Routes>
    </AuthProvider>
    </MotionConfig>
  );
}
