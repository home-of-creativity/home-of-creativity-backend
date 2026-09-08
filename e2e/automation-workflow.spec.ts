import { expect, test } from "@playwright/test";
import { API, loginStaff, N8N_SECRET, n8nCallback, openStaffApp, submitTelegramRequest } from "./helpers";

test.describe("Full request automation on the live API", () => {
  test("telegram request walks every n8n stage including revision", async ({ request, page }) => {
    test.setTimeout(90_000);
    const created = await submitTelegramRequest(request, {
      title: "E2E booth identity",
      description: "Full automation path from submit to completion.",
    });
    const number = created.data.number;
    const id = created.data.id;
    expect(number).toMatch(/^REQ-\d{4}-\d{6}$/);
    expect(created.data.status).toBe("submitted");
    expect(created.data.source).toBe("telegram");

    const analyzing = await n8nCallback(request, "REQUEST_SUBMITTED", number, {
      ai_analysis: { services: ["identity"], departments: ["brand"] },
    });
    expect(analyzing.data.status).toBe("ai_analyzing");

    const quoted = await n8nCallback(request, "QUOTATION_READY", number, {
      odoo_partner_id: "P-E2E",
      odoo_quotation_id: `Q-${number}`,
    });
    expect(quoted.data.status).toBe("quotation_sent");

    const paid = await n8nCallback(request, "PAYMENT_CONFIRMED", number, {
      odoo_invoice_id: `INV-${number}`,
    });
    expect(paid.data.status).toBe("payment_confirmed");

    const tasks = await n8nCallback(request, "TASKS_READY", number, {
      briefs: [
        { department: "brand", brief: "Visual system", clickup_task_id: "CU-1" },
        { department: "finance", brief: "Invoice tracking", clickup_task_id: "CU-2" },
      ],
    });
    expect(tasks.data.status).toBe("in_progress");

    const ready = await n8nCallback(request, "DELIVERY_READY", number);
    expect(ready.data.status).toBe("ready_for_review");

    const revision = await n8nCallback(request, "REVISION_REQUESTED", number, {
      comments: "Adjust the booth lighting.",
    });
    expect(revision.data.status).toBe("revision_in_progress");

    const resubmitted = await n8nCallback(request, "DELIVERY_READY", number);
    expect(resubmitted.data.status).toBe("ready_for_review");

    const done = await n8nCallback(request, "PROJECT_COMPLETED", number);
    expect(done.data.status).toBe("completed");

    const staff = await loginStaff(request);
    const show = await request.get(`${API}/admin/requests/${id}`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    expect(show.ok()).toBeTruthy();
    expect((await show.json()).data.status).toBe("completed");

    await openStaffApp(page, request, "/requests");
    await expect(page.getByRole("heading", { name: /Requests|الطلبات/ })).toBeVisible();
    await expect(page.getByRole("link", { name: number })).toBeVisible();
    await page.getByRole("link", { name: number }).click();
    await expect(page.getByRole("heading", { name: number })).toBeVisible();
    await expect(page.getByTestId("request-status")).toHaveText(/Completed|مكتمل/);
    await expect(page.locator("form.toolbar select.field")).toHaveValue("completed");
  });

  test("Telegram bot can link a client and submit a request", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "Telegram campaign",
      description: "Paid ads brief from the bot.",
    });
    expect(created.data.source).toBe("telegram");
    expect(created.data.status).toBe("submitted");
    expect(created.data.number).toMatch(/^REQ-\d{4}-\d{6}$/);
  });

  test("staff can advance a live request from the dashboard", async ({ request, page }) => {
    const created = await submitTelegramRequest(request, {
      title: "Dashboard status move",
      description: "Staff changes status in the UI.",
    });
    const number = created.data.number;
    const id = created.data.id;

    const staff = await loginStaff(request);
    const auth = { Authorization: `Bearer ${staff.data.token}` };
    for (let i = 0; i < 10; i++) {
      const show = await request.get(`${API}/admin/requests/${id}`, { headers: auth });
      const status = ((await show.json()) as { data: { status: string } }).data.status;
      if (status === "quotation_sent" || status === "ai_analyzing" || status === "payment_confirmed") {
        break;
      }
      await page.waitForTimeout(800);
    }

    await openStaffApp(page, request, `/requests/${id}`);
    await expect(page.getByRole("heading", { name: number })).toBeVisible();
    const pin = await request.patch(`${API}/admin/requests/${id}`, {
      headers: auth,
      data: { status: "submitted" },
    });
    expect(pin.ok(), await pin.text()).toBeTruthy();
    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page.getByRole("heading", { name: number })).toBeVisible();
    const statusSelect = page.locator("form.toolbar select.field");
    await expect(statusSelect).toHaveValue("submitted");
    await expect(page.getByRole("button", { name: /Mark as paid|تأكيد الدفع/ })).toHaveCount(0);
    await expect(statusSelect.locator('option[value="payment_confirmed"]')).toHaveCount(0);
    await expect(page.getByText(/Payment cannot be confirmed|لا يمكن تأكيد الدفع/)).toBeVisible();
    await statusSelect.selectOption("quotation_sent");
    const patchPromise = page.waitForResponse(
      (res) => res.url().includes(`/admin/requests/${id}`) && res.request().method() === "PATCH",
    );
    await page.getByRole("button", { name: /Save status|حفظ الحالة/ }).click();
    const patch = await patchPromise;
    expect(patch.ok(), await patch.text()).toBeTruthy();
    await expect(page.getByTestId("request-status")).toHaveText(/Quotation sent|عرض سعر/);
    await expect(statusSelect).toHaveValue("quotation_sent");
    await expect(page.getByRole("button", { name: /Mark as paid|تأكيد الدفع/ })).toBeVisible();
    await expect(statusSelect.locator('option[value="payment_confirmed"]')).toHaveCount(1);

    const verify = await request.get(`${API}/admin/requests/${id}`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    const verifyText = await verify.text();
    expect(verify.ok(), verifyText).toBeTruthy();
    expect(JSON.parse(verifyText).data.status).toBe("quotation_sent");
  });

  test("n8n and telegram webhooks reject a bad secret", async ({ request }) => {
    const n8n = await request.post(`${API}/webhooks/n8n`, {
      headers: { "X-N8N-Secret": "wrong-secret" },
      data: { event: "REQUEST_SUBMITTED", request_number: "REQ-2026-000001" },
    });
    expect(n8n.status()).toBe(401);

    const bot = await request.post(`${API}/bot/telegram/link`, {
      headers: { "X-Webhook-Secret": "wrong-secret" },
      data: { telegram_user_id: "tg-bad", name: "Nope", locale: "ar" },
    });
    expect(bot.status()).toBe(401);
  });

  test("email register is closed and a client cannot list admin requests", async ({ request }) => {
    const closed = await request.post(`${API}/auth/register`, {
      data: {
        name: "Blocked",
        email: "blocked@hoc.test",
        password: "password",
        password_confirmation: "password",
      },
    });
    expect(closed.status()).toBe(403);

    const created = await submitTelegramRequest(request, {
      title: "Client isolation",
      description: "Must stay off the admin API.",
    });

    const clientLogin = await request.post(`${API}/auth/login`, {
      data: { email: "test@example.com", password: "password" },
    });
    expect(clientLogin.ok(), await clientLogin.text()).toBeTruthy();
    const token = (await clientLogin.json()).data.token as string;

    const adminList = await request.get(`${API}/admin/requests`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(adminList.status()).toBe(403);

    const unknown = await request.post(`${API}/webhooks/n8n`, {
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-N8N-Secret": N8N_SECRET,
      },
      data: { event: "NOT_A_REAL_EVENT", request_number: created.data.number },
    });
    expect(unknown.status(), await unknown.text()).toBe(422);
  });
});
