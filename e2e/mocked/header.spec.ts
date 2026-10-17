import { expect, test } from "@playwright/test";
import { mockApi, signedIn } from "./helpers";

for (const width of [375, 768]) {
  test(`mobile bar keeps its height on a short page at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await signedIn(page);
    await mockApi(page);

    await page.goto("./complaints");
    const bar = page.locator(".mobile-bar");
    await expect(bar).toBeVisible();
    const box = await bar.boundingBox();
    expect(box?.height ?? 0).toBeLessThan(90);
  });
}
