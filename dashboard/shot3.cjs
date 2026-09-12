const { chromium } = require("playwright");

(async () => {
  const browser = await chromium.launch();

  // Mobile check
  const mpage = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await mpage.goto("http://127.0.0.1:5173/staff", { waitUntil: "networkidle" });
  await mpage.waitForSelector("#staff-email", { state: "visible" });
  await mpage.screenshot({ path: "shot3-login-mobile.png", fullPage: true });
  await mpage.fill("#staff-email", "admin@example.com");
  await mpage.fill("#staff-password", "password");
  await Promise.all([
    mpage.waitForURL("http://127.0.0.1:5173/", { timeout: 8000 }).catch(() => {}),
    mpage.click('button[type="submit"]'),
  ]);
  await mpage.waitForTimeout(1000);
  await mpage.screenshot({ path: "shot3-overview-mobile.png", fullPage: true });
  await mpage.click(".nav-toggle");
  await mpage.waitForTimeout(400);
  await mpage.screenshot({ path: "shot3-nav-mobile.png" });

  // Desktop: request detail + modal
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto("http://127.0.0.1:5173/staff", { waitUntil: "networkidle" });
  await page.waitForSelector("#staff-email", { state: "visible" });
  await page.fill("#staff-email", "admin@example.com");
  await page.fill("#staff-password", "password");
  await Promise.all([
    page.waitForURL("http://127.0.0.1:5173/", { timeout: 8000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.goto("http://127.0.0.1:5173/requests/37", { waitUntil: "networkidle" });
  await page.waitForTimeout(800);
  await page.screenshot({ path: "shot3-detail.png", fullPage: true });
  const attBtn = await page.$("text=عرض المرفق");
  if (attBtn) {
    await attBtn.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: "shot3-modal.png" });
  } else {
    console.log("no attachment button found on this request");
  }

  await browser.close();
})();
