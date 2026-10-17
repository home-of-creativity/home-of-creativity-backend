import type { Page, Route } from "@playwright/test";

export const STAFF = {
  id: 1,
  name: "QA Admin",
  email: "qa-admin@example.test",
  is_admin: true,
  role: null,
  abilities: [
    "ops.clients",
    "ops.requests",
    "ops.finance",
    "ops.photography",
    "ops.complaints",
    "site.articles",
    "site.profile_pdf",
  ],
};

type Handler = (route: Route, url: URL) => Promise<boolean> | boolean;

/** Answers every API call. `handler` returns true when it fulfilled the route itself. */
export async function mockApi(page: Page, handler: Handler = () => false) {
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    if (await handler(route, url)) return;
    if (url.pathname.endsWith("/auth/me")) {
      await route.fulfill({ json: { data: STAFF } });
      return;
    }
    await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } });
  });
}

export async function signedIn(page: Page) {
  await page.addInitScript(() => {
    localStorage.setItem("hoc-dash-locale", "en");
    localStorage.setItem("hoc-staff-token", "qa-mock-token");
  });
}
