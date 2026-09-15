import { expect, test } from "@playwright/test";
import { API, loginStaff, openStaffApp, submitTelegramRequest } from "./helpers";

test.describe("Odoo dashboard smoke", () => {
  test("admin can open odoo-related request tabs", async ({ request, page }) => {
    const created = await submitTelegramRequest(request, {
      title: "Odoo dashboard",
      description: "Verify Odoo links on request detail.",
    });

    await openStaffApp(page, request, `/requests/${created.data.id}`);
    await expect(page.getByRole("heading", { name: created.data.number })).toBeVisible();
    await expect(page.getByTestId("request-status")).toBeVisible();
  });

  test("admin odoo sync partners endpoint responds", async ({ request }) => {
    const staff = await loginStaff(request);
    const response = await request.post(`${API}/admin/odoo/sync-partners`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    expect([200, 422, 503]).toContain(response.status());
  });

  test("admin odoo sync employees endpoint responds", async ({ request }) => {
    const staff = await loginStaff(request);
    const response = await request.post(`${API}/admin/odoo/sync-employees`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    expect([200, 422, 503]).toContain(response.status());
  });
});
