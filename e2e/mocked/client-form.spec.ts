import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

test("empty new client shows the required name and sends nothing", async ({ page }) => {
  let posted = false;
  await signedIn(page);
  await mockApi(page, (route, url) => {
    if (url.pathname.endsWith("/admin/clients") && route.request().method() === "POST") posted = true;
    return false;
  });

  await page.goto("./clients/new");
  await page.getByRole("button", { name: "Save client" }).click();
  await expect(page.locator(".field-error").first()).toBeVisible();
  expect(posted).toBe(false);
});

test("a 422 from the API shows next to its field", async ({ page }) => {
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/admin/clients") || route.request().method() !== "POST") return false;
    await route.fulfill({
      status: 422,
      json: { message: "The email has already been taken.", errors: { email: ["The email has already been taken."] } },
    });
    return true;
  });

  await page.goto("./clients/new");
  await page.getByLabel("Client", { exact: true }).fill("QA-Form");
  await page.getByLabel("Email").fill("qa@example.test");
  await page.getByRole("button", { name: "Save client" }).click();
  await expect(page.locator(".field-label", { hasText: "Email" }).locator(".field-error")).toHaveText("The email has already been taken.");
});

test("editing a client the API does not know shows not found", async ({ page }) => {
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/admin/clients/999999")) return false;
    await route.fulfill({ status: 404, json: { message: "Not found." } });
    return true;
  });

  await page.goto("./clients/999999/edit");
  await expect(page.getByRole("heading", { name: "Page not found" })).toBeVisible();
});

test("an unknown dashboard path shows not found inside the shell", async ({ page }) => {
  await signedIn(page);
  await mockApi(page);

  await page.goto("./does-not-exist-qa");
  await expect(page.getByRole("heading", { name: "Page not found" })).toBeVisible();
  await expect(page.locator("#dash-nav")).toBeAttached();
});

test("editing an article the API does not know shows not found", async ({ page }) => {
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/articles/999999")) return false;
    await route.fulfill({ status: 404, json: { message: "Not found." } });
    return true;
  });

  await page.goto("./articles/999999/edit");
  await expect(page.getByRole("heading", { name: "Page not found" })).toBeVisible();
});
