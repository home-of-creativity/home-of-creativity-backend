import { NavLink, Navigate, Outlet, Route, Routes, useLocation } from "react-router-dom";
import { AnimatePresence, motion, MotionConfig } from "framer-motion";
import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";
import { Toaster } from "sonner";
import { canAbility, type StaffAbility, type User } from "./api";
import { AuthProvider, useAuth } from "./auth";
import { LiveFeedProvider, useLive } from "./live";
import { applyLocale, applyTheme, copy, readLocale, readTheme, type Copy, type Locale, type Theme } from "./i18n";
import { Moon, Search, Sun } from "lucide-react";
import { BrandLockup } from "./components/BrandLockup";
import { CommandPalette, type CommandItem } from "./components/CommandPalette";
import { ClientChannelsPage } from "./pages/ClientChannels";
import { ClientForm } from "./pages/ClientForm";
import { Clients } from "./pages/Clients";
import { Contact } from "./pages/Contact";
import { ContactChannelForm } from "./pages/ContactChannelForm";
import { EmployeeForm } from "./pages/EmployeeForm";
import { Employees } from "./pages/Employees";
import { Permissions } from "./pages/Permissions";
import { Legal } from "./pages/Legal";
import { ProfilePdf } from "./pages/ProfilePdf";
import { Login } from "./pages/Login";
import { Overview } from "./pages/Overview";
import { Articles } from "./pages/Articles";
import { ArticleForm } from "./pages/ArticleForm";
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
import { SocialWorkspace } from "./pages/social/SocialWorkspace";
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
  IconLegal,
  IconLogout,
  IconArticles,
  IconChannels,
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

  const commandItems: CommandItem[] = [
    { id: "overview", label: t(copy.overview), to: "/", icon: <IconOverview aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "requests", label: t(copy.requests), to: "/requests", icon: <IconRequests aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "employees", label: t(copy.employees), to: "/employees", icon: <IconEmployees aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "clients", label: t(copy.clients), to: "/clients", icon: <IconClients aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "social", label: t(copy.navSocial), to: "/social", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "social-links", label: t(copy.socialBioLinks), to: "/social/links", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "projects", label: t(copy.portfolioTabProjects), to: "/projects", icon: <IconProjects aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "reels", label: t(copy.reelsTitle), to: "/reels", icon: <IconReels aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "articles", label: t(copy.articlesTitle), to: "/articles", icon: <IconArticles aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "categories", label: t(copy.portfolioTabCategories), to: "/categories", icon: <IconCategories aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "pricing", label: t(copy.pricingTitle), to: "/pricing", icon: <IconPricing aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "contact", label: t(copy.contactTitle), to: "/contact", icon: <IconContact aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "profile-pdf", label: t(copy.navProfilePdf), to: "/profile-pdf", icon: <IconLegal aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "privacy", label: t(copy.legalPrivacyTitle), to: "/privacy", icon: <IconLegal aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "terms", label: t(copy.legalTermsTitle), to: "/terms", icon: <IconLegal aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "sham-cash", label: t(copy.navPayments), to: "/payments", icon: <IconQr aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "channels", label: t(copy.navChannels), to: "/channels", icon: <IconChannels aria-hidden width={18} height={18} />, group: t(copy.commandGroupPages) },
    { id: "add-employee", label: t(copy.addEmployee), to: "/employees/new", icon: <IconEmployees aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "add-client", label: t(copy.addClient), to: "/clients/new", icon: <IconClients aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "add-reel", label: t(copy.addReel), to: "/reels/new", icon: <IconReels aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "add-article", label: t(copy.addArticle), to: "/articles/new", icon: <IconArticles aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
    { id: "compose-post", label: t(copy.socialCompose), to: "/social", icon: <IconSocial aria-hidden width={18} height={18} />, group: t(copy.commandGroupActions) },
  ].filter((item) => commandAllowed(user, item.id));

  if (!user) return null;

  const isSocial = location.pathname.startsWith("/social");

  return (
    <LiveFeedProvider locale={locale} t={t}>
    <LiveBadgeSync onBadges={setBadges} />
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
          {canAbility(user, "ops.overview") ? (
          <NavLink to="/" end>
            <IconOverview aria-hidden />
            <span>{t(copy.overview)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "ops.requests") ? (
          <NavLink to="/requests">
            <span className="nav-link-row">
              <IconRequests aria-hidden />
              <span>{t(copy.requests)}</span>
              {badges.openRequests > 0 ? <span className="nav-badge">{badges.openRequests}</span> : null}
            </span>
          </NavLink>
          ) : null}
          {canAbility(user, "ops.employees") ? (
          <NavLink to="/employees">
            <span className="nav-link-row">
              <IconEmployees aria-hidden />
              <span>{t(copy.employees)}</span>
              {badges.pendingEmployees > 0 ? <span className="nav-badge">{badges.pendingEmployees}</span> : null}
            </span>
          </NavLink>
          ) : null}
          {canAbility(user, "ops.clients") ? (
          <NavLink to="/clients">
            <IconClients aria-hidden />
            <span>{t(copy.clients)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "social.content") || canAbility(user, "social.approve") || canAbility(user, "social.engage") || canAbility(user, "social.messages") || canAbility(user, "social.accounts") || canAbility(user, "social.links") ? (
            <NavLink to="/social">
              <IconSocial aria-hidden />
              <span>{t(copy.navSocial)}</span>
            </NavLink>
          ) : null}
          {canAbility(user, "ops.payments") ? (
          <NavLink to="/payments">
            <IconQr aria-hidden />
            <span>{t(copy.navPayments)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "ops.channels") ? (
          <NavLink to="/channels">
            <IconChannels aria-hidden />
            <span>{t(copy.navChannels)}</span>
          </NavLink>
          ) : null}
          <p className="nav-group-label">{t(copy.navSite)}</p>
          {canAbility(user, "site.projects") ? (
          <NavLink to="/projects">
            <IconProjects aria-hidden />
            <span>{t(copy.portfolioTabProjects)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.reels") ? (
          <NavLink to="/reels">
            <IconReels aria-hidden />
            <span>{t(copy.reelsTitle)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.articles") ? (
          <NavLink to="/articles">
            <IconArticles aria-hidden />
            <span>{t(copy.articlesTitle)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.categories") ? (
          <NavLink to="/categories">
            <IconCategories aria-hidden />
            <span>{t(copy.portfolioTabCategories)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.pricing") ? (
          <NavLink to="/pricing">
            <IconPricing aria-hidden />
            <span>{t(copy.pricingTitle)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.contact") ? (
          <NavLink to="/contact">
            <IconContact aria-hidden />
            <span>{t(copy.contactTitle)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.profile_pdf") ? (
          <NavLink to="/profile-pdf">
            <IconLegal aria-hidden />
            <span>{t(copy.navProfilePdf)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.legal") ? (
          <NavLink to="/privacy">
            <IconLegal aria-hidden />
            <span>{t(copy.legalPrivacyTitle)}</span>
          </NavLink>
          ) : null}
          {canAbility(user, "site.legal") ? (
          <NavLink to="/terms">
            <IconLegal aria-hidden />
            <span>{t(copy.legalTermsTitle)}</span>
          </NavLink>
          ) : null}
          {user?.is_admin ? (
          <NavLink to="/permissions">
            <IconEmployees aria-hidden />
            <span>{t(copy.navPermissions)}</span>
          </NavLink>
          ) : null}
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
      <main className={isSocial ? "main is-social" : "main"} id="main-content" tabIndex={-1}>
        <AnimatePresence mode="wait" initial={false}>
          <motion.div
            className="main-pane"
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
    </LiveFeedProvider>
  );
}

function LiveBadgeSync({
  onBadges,
}: {
  onBadges: (next: { pendingEmployees: number; openRequests: number }) => void;
}) {
  const { snapshot } = useLive();
  useEffect(() => {
    if (!snapshot) return;
    onBadges({
      pendingEmployees: snapshot.pending_employees,
      openRequests: snapshot.requests_count,
    });
  }, [snapshot, onBadges]);
  return null;
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
  if (!user?.is_admin && !user?.role) {
    if (location.pathname === "/") {
      return <Login locale={locale} t={t} setLocale={setLocale} />;
    }

    return <Navigate to="/" replace state={{ from: location }} />;
  }

  const fromPath = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname;
  if (location.pathname === "/" && fromPath && fromPath !== "/" && fromPath !== "/login" && pathAllowed(user, fromPath)) {
    return <Navigate to={fromPath} replace />;
  }

  if (user && !pathAllowed(user, location.pathname)) {
    const next = homeFor(user);
    if (next !== location.pathname) return <Navigate to={next} replace />;
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
      offset="4.5rem"
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
          <Route path="/permissions" element={<Permissions locale={locale} t={t} />} />
          <Route path="/clients" element={<Clients locale={locale} t={t} />} />
          <Route path="/clients/new" element={<ClientForm locale={locale} t={t} />} />
          <Route path="/clients/:id/edit" element={<ClientForm locale={locale} t={t} />} />
          <Route path="/clients/logos/new" element={<ClientLogoForm locale={locale} t={t} />} />
          <Route path="/clients/logos/:id/edit" element={<ClientLogoForm locale={locale} t={t} />} />
          <Route path="/contact" element={<Contact locale={locale} t={t} />} />
          <Route path="/contact/new" element={<ContactChannelForm locale={locale} t={t} />} />
          <Route path="/contact/:id/edit" element={<ContactChannelForm locale={locale} t={t} />} />
          <Route path="/legal" element={<Navigate to="/privacy" replace />} />
          <Route path="/profile-pdf" element={<ProfilePdf locale={locale} t={t} />} />
          <Route path="/privacy" element={<Legal locale={locale} t={t} slug="privacy" />} />
          <Route path="/terms" element={<Legal locale={locale} t={t} slug="terms" />} />
          <Route path="/social" element={<SocialWorkspace locale={locale} t={t} />}>
            <Route index element={<SocialHome locale={locale} t={t} />} />
            <Route path="links" element={<SocialLinks locale={locale} t={t} />} />
            <Route path="design" element={<SocialDesign locale={locale} t={t} />} />
            <Route path="compose" element={<Navigate to="/social" replace />} />
            <Route path="compose/:id" element={<SocialCompose locale={locale} t={t} />} />
            <Route path="calendar" element={<SocialCalendar locale={locale} t={t} />} />
            <Route path="inbox" element={<SocialInbox locale={locale} t={t} />} />
            <Route path="accounts" element={<SocialAccounts locale={locale} t={t} />} />
            <Route path="accounts/new" element={<SocialAccountForm locale={locale} t={t} />} />
            <Route path="accounts/:id/edit" element={<SocialAccountForm locale={locale} t={t} />} />
          </Route>
          <Route path="/payments" element={<Payments locale={locale} t={t} />} />
          <Route path="/channels" element={<ClientChannelsPage locale={locale} t={t} />} />
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
          <Route path="/articles" element={<Articles locale={locale} t={t} />} />
          <Route path="/articles/new" element={<ArticleForm locale={locale} t={t} />} />
          <Route path="/articles/:id/edit" element={<ArticleForm locale={locale} t={t} />} />
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

const commandAbility: Record<string, StaffAbility | "social" | "owner"> = {
  overview: "ops.overview",
  requests: "ops.requests",
  employees: "ops.employees",
  clients: "ops.clients",
  social: "social",
  "social-links": "social.links",
  projects: "site.projects",
  reels: "site.reels",
  articles: "site.articles",
  categories: "site.categories",
  pricing: "site.pricing",
  contact: "site.contact",
  "profile-pdf": "site.profile_pdf",
  privacy: "site.legal",
  terms: "site.legal",
  "sham-cash": "ops.payments",
  channels: "ops.channels",
  "add-employee": "ops.employees",
  "add-client": "ops.clients",
  "add-reel": "site.reels",
  "add-article": "site.articles",
  "compose-post": "social.content",
};

function commandAllowed(user: User | null, id: string) {
  return pathAllowed(user, commandPath(id));
}

function commandPath(id: string) {
  const ability = commandAbility[id];
  if (ability === "social") return "/social";
  if (id === "social-links") return "/social/links";
  if (id === "sham-cash") return "/payments";
  if (id === "overview") return "/";
  if (id.startsWith("add-")) return `/${id.replace("add-", "")}/new`;
  if (id === "compose-post") return "/social";
  return `/${id}`;
}

function pathAllowed(user: User | null, pathname: string) {
  if (!user) return false;
  if (pathname === "/permissions") return user.is_admin;
  if (user.is_admin && !user.role) return true;
  if (pathname.startsWith("/requests")) return canAbility(user, "ops.requests");
  if (pathname.startsWith("/employees")) return canAbility(user, "ops.employees");
  if (pathname.startsWith("/clients")) return canAbility(user, "ops.clients");
  if (pathname.startsWith("/payments")) return canAbility(user, "ops.payments");
  if (pathname.startsWith("/channels")) return canAbility(user, "ops.channels");
  if (pathname.startsWith("/projects")) return canAbility(user, "site.projects");
  if (pathname.startsWith("/reels")) return canAbility(user, "site.reels");
  if (pathname.startsWith("/articles")) return canAbility(user, "site.articles");
  if (pathname.startsWith("/categories")) return canAbility(user, "site.categories");
  if (pathname.startsWith("/pricing")) return canAbility(user, "site.pricing");
  if (pathname.startsWith("/contact")) return canAbility(user, "site.contact");
  if (pathname.startsWith("/profile-pdf")) return canAbility(user, "site.profile_pdf");
  if (pathname.startsWith("/privacy") || pathname.startsWith("/terms") || pathname.startsWith("/legal")) return canAbility(user, "site.legal");
  if (pathname.startsWith("/social/links") || pathname.startsWith("/social/design")) return canAbility(user, "social.links");
  if (pathname.startsWith("/social/inbox")) return canAbility(user, "social.engage") || canAbility(user, "social.messages");
  if (pathname.startsWith("/social/accounts")) return canAbility(user, "social.accounts");
  if (pathname.startsWith("/social")) return canAbility(user, "social.content") || canAbility(user, "social.approve") || canAbility(user, "social.engage") || canAbility(user, "social.messages") || canAbility(user, "social.accounts") || canAbility(user, "social.links");
  return user.is_admin;
}

function homeFor(user: User) {
  const candidates = ["/", "/requests", "/employees", "/clients", "/social", "/payments", "/channels", "/projects", "/reels", "/articles", "/categories", "/pricing", "/contact", "/profile-pdf", "/privacy", "/permissions"];
  return candidates.find((path) => pathAllowed(user, path)) ?? "/";
}
