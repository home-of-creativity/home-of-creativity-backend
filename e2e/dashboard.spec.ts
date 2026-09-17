import { expect, test } from "@playwright/test";
import { DASHBOARD, openStaffApp } from "./helpers";

test.describe("Staff dashboard", () => {
  test("rejects a client account and accepts admin", async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem("hoc-dash-locale", "en");
      localStorage.removeItem("hoc-staff-token");
    });
    await page.goto(`${DASHBOARD}/login`, { waitUntil: "networkidle" });
    await expect(page).toHaveURL(/127\.0\.0\.1:5173\/dashboard\/?$/);
    await expect(page.getByRole("heading", { name: /Staff login|دخول الفريق/ })).toBeVisible();
    await expect(page.getByRole("link", { name: /Open Telegram bot|فتح بوت تيليجرام/ })).toHaveCount(1);
    await page.getByLabel(/Email|البريد/).fill("test@example.com");
    await page.getByLabel(/Password|كلمة المرور/).fill("password");
    await page.getByRole("button", { name: /Sign in|دخول/ }).click();
    await expect(page.getByText(/not a staff user|ليس حساب فريق/)).toBeVisible();

    await page.getByLabel(/Email|البريد/).fill("admin@example.com");
    await page.getByLabel(/Password|كلمة المرور/).fill("password");
    await page.getByRole("button", { name: /Sign in|دخول/ }).click();
    await expect(page).toHaveURL(/127\.0\.0\.1:5173\/dashboard\/?$/);
    await expect(page.getByRole("heading", { name: /Overview|نظرة عامة/ })).toBeVisible();
  });

  test("opens requests and clients after login", async ({ page, request }) => {
    await openStaffApp(page, request, "/");
    await expect(page.getByRole("heading", { name: /Overview|نظرة عامة/ })).toBeVisible();
    await page.getByRole("link", { name: /Requests|الطلبات/ }).first().click();
    await expect(page.getByRole("heading", { name: /Requests|الطلبات/ })).toBeVisible();
    await expect(page.locator("table")).toBeVisible();
    const requestPager = page.getByRole("navigation", { name: /Pagination|تنقل الصفحات/ });
    if (await requestPager.count()) {
      await expect(requestPager).toBeVisible();
      const nextRequests = page.getByRole("button", { name: /Next|التالي/ });
      if (await nextRequests.count()) {
        await nextRequests.click();
        await expect(page.getByText(/Page 2 of|صفحة 2 من/)).toBeVisible();
      }
    }

    await page.getByRole("link", { name: /Clients|العملاء/ }).click();
    await expect(page.getByRole("heading", { name: /Clients|العملاء/ })).toBeVisible();
    const clientPager = page.getByRole("navigation", { name: /Pagination|تنقل الصفحات/ });
    if (await clientPager.count()) {
      await expect(clientPager).toBeVisible();
      const nextClients = page.getByRole("button", { name: /Next|التالي/ });
      if (await nextClients.count()) {
        await nextClients.click();
        await expect(page.getByText(/Page 2 of|صفحة 2 من/)).toBeVisible();
      }
    }

    await page.getByRole("link", { name: /Employees|الموظفون/ }).click();
    await expect(page.getByRole("heading", { name: /Employees|الموظفون/ })).toBeVisible();
    await expect(page.getByText(/Do not type Telegram or ClickUp IDs|لا تدخل آيدي/)).toBeVisible();
    await expect(page.getByRole("button", { name: /Add employee|إضافة موظف/ })).toBeVisible();
    await expect(page.getByRole("button", { name: /Add employee|إضافة موظف/ })).toBeVisible();
  });
});
