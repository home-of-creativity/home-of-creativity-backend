import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

test("profile pdf refuses a png renamed to .pdf before any upload", async ({ page }) => {
  let posted = false;
  await signedIn(page);
  await mockApi(page, async (route, url) => {
    if (!url.pathname.endsWith("/ops-settings/profile-pdf")) return false;
    if (route.request().method() === "POST") posted = true;
    await route.fulfill({ json: { data: { url: null, name: null, updated_at: null, version: null } } });
    return true;
  });

  await page.goto("./profile-pdf");
  await page.locator("input[type=file]").setInputFiles({
    name: "profile.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  });
  await expect(page.getByText("This file is not a PDF. Choose a real PDF file.")).toBeVisible();
  expect(posted).toBe(false);
});
