const { chromium } = require("playwright");

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

  await page.goto("http://127.0.0.1:5173/dashboard/login", { waitUntil: "networkidle" });
  await page.waitForSelector("#staff-email", { state: "visible" });
  await page.screenshot({ path: "shot2-login.png" });

  await page.fill("#staff-email", "admin@example.com");
  await page.fill("#staff-password", "password");
  await Promise.all([
    page.waitForURL("http://127.0.0.1:5173/dashboard/", { timeout: 8000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1200);
  await page.screenshot({ path: "shot2-overview.png", fullPage: true });

  await page.goto("http://127.0.0.1:5173/dashboard/requests", { waitUntil: "networkidle" });
  await page.waitForTimeout(800);
  await page.screenshot({ path: "shot2-requests.png", fullPage: true });

  await page.goto("http://127.0.0.1:5173/dashboard/employees", { waitUntil: "networkidle" });
  await page.waitForTimeout(800);
  await page.screenshot({ path: "shot2-employees.png", fullPage: true });

  await browser.close();
})();
