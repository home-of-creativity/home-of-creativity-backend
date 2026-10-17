import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

test("the login card does not promise a telegram button that is missing", async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem("hoc-dash-locale", "en"));
  await mockApi(page);
  await page.goto("./");
  const link = page.getByRole("link", { name: /Telegram bot/i });
  if (await link.count()) {
    await expect(link).toBeVisible();
  } else {
    await expect(page.getByText("The bot link is not added yet.")).toBeVisible();
    await expect(page.getByText("Open the bot from the button below.")).toHaveCount(0);
  }
  await expect(page.getByRole("link", { name: /forgot/i })).toHaveCount(0);
});

test("an event without a title is refused before any request", async ({ page }) => {
  let posted = false;
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (route.request().method() === "POST" && url.pathname.endsWith("/calendar/events")) posted = true;
    if (url.pathname.endsWith("/photography")) {
      await route.fulfill({
        json: {
          data: {
            calendar: [], queue: [], waiting_client: [], mine: [], all: [], unbooked: [], needs_count: [],
            me: { employee_id: 1, is_photographer: false, can_all: true },
            settings: { lead_days: 2, shoot_hours: 2, gap_hours: 1, reply_hours: 4 },
            calendar_url: null,
          },
        },
      });
      return true;
    }
    if (url.pathname.endsWith("/photography/calendar/calendars")) {
      await route.fulfill({
        json: { data: { configured: true, service_email: null, can_manage: true, calendars: [{ id: "cal", summary: "Shoots", description: null, color: "#2e0e5c", foreground: null, access_role: "owner", main: true, writable: true }] } },
      });
      return true;
    }
    if (url.pathname.includes("/photography/") && route.request().method() !== "GET") {
      await route.abort();
      return true;
    }
    return false;
  });

  await page.goto("./photography");
  await page.getByRole("button", { name: "Create" }).click();
  await page.getByRole("button", { name: "Save" }).click();
  await expect(page.getByText("This field is required.")).toBeVisible();
  expect(posted).toBe(false);
});
