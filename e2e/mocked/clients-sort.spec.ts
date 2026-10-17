import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

test("client name header sorts ascending then descending through the API", async ({ page }) => {
  const asked: string[] = [];
  await signedIn(page);
  await mockApi(page, (_route, url) => {
    if (url.pathname.endsWith("/admin/clients")) asked.push(url.search);
    return false;
  });

  await page.goto("./clients");
  await page.locator(".filter-bar input").first().fill("QA-client");
  const header = page.getByRole("button", { name: "Client", exact: true });
  await header.click();
  await expect.poll(() => asked.some((q) => q.includes("sort=name") && q.includes("dir=asc"))).toBe(true);
  await header.click();
  await expect.poll(() => asked.some((q) => q.includes("sort=name") && q.includes("dir=desc"))).toBe(true);
  await expect(page.locator("th[aria-sort='descending']")).toHaveCount(1);
});
