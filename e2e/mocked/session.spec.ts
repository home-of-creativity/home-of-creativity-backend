import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

for (const status of [502, 503]) {
  test(`one ${status} from /auth/me keeps the session`, async ({ page }) => {
    await signedIn(page);
    let calls = 0;
    await mockApi(page, async (route, url) => {
      if (!url.pathname.endsWith("/auth/me")) return false;
      calls += 1;
      // Two failures keep the retry notice up for the 1s and 2s waits.
      if (calls <= 2) {
        await route.fulfill({ status, contentType: "text/html", body: "<h1>Bad gateway</h1>" });
        return true;
      }
      return false;
    });

    await page.goto("./");
    await expect(page.getByText("The server is unavailable. Retrying…")).toBeVisible();
    await expect.poll(() => calls).toBeGreaterThanOrEqual(3);
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.getItem("hoc-staff-token"))).toBe("qa-mock-token");
  });
}

test("a network error on /auth/me keeps the session", async ({ page }) => {
  await signedIn(page);
  let calls = 0;
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/auth/me")) return false;
    calls += 1;
    if (calls === 1) {
      await route.abort("failed");
      return true;
    }
    return false;
  });

  await page.goto("./");
  await expect.poll(() => calls).toBeGreaterThanOrEqual(2);
  await expect(page.locator('input[type="password"]')).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem("hoc-staff-token"))).toBe("qa-mock-token");
});

test("a 401 from /auth/me signs out", async ({ page }) => {
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/auth/me")) return false;
    await route.fulfill({ status: 401, json: { message: "Unauthenticated." } });
    return true;
  });

  await page.goto("./");
  await expect(page.locator('input[type="password"]')).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem("hoc-staff-token"))).toBeNull();
});
