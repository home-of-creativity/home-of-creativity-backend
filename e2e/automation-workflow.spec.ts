import { expect, test } from "@playwright/test";
import {
  API,
  approveQuotation,
  confirmPayment,
  E2E_DESIGN_TELEGRAM_ID,
  E2E_SALES_TELEGRAM_ID,
  ensureExecutionReady,
  getRequest,
  loginStaff,
  N8N_SECRET,
  openStaffApp,
  pollRequestStatus,
  rejectQuotation,
  requestRevision,
  sendQuotation,
  staffBotComplete,
  staffBotDeliver,
  staffBotQuotation,
  submitTelegramRequest,
  uploadReceipt,
} from "./helpers";

test.describe("Full request automation on the live API", () => {
  test("telegram request walks the full workflow including revision", async ({ request, page }) => {
    test.setTimeout(120_000);

    const created = await submitTelegramRequest(request, {
      title: "E2E booth identity",
      description: "Full automation path from submit to completion.",
    });
    const { telegramId, data } = created;
    const number = data.number;
    const id = data.id;

    expect(number).toMatch(/^REQ-\d{4}-\d{6}$/);
    expect(data.status).toBe("submitted");
    expect(data.source).toBe("telegram");

    await sendQuotation(request, id, 1500, "Initial quotation");
    await rejectQuotation(request, number, telegramId, "Price is too high");
    await staffBotQuotation(request, E2E_SALES_TELEGRAM_ID, number, 1200);
    await approveQuotation(request, number, telegramId);
    await uploadReceipt(request, number, telegramId);
    await confirmPayment(request, id).catch(() => undefined);

    const detail = await getRequest(request, id);
    const requestUuid = detail.data.uuid as string;
    await ensureExecutionReady(request, id, requestUuid, number);

    await staffBotDeliver(request, E2E_DESIGN_TELEGRAM_ID, number, "First delivery");
    await pollRequestStatus(request, id, "ready_for_review");

    await requestRevision(request, number, telegramId, "Adjust the booth lighting.");
    await staffBotDeliver(request, E2E_DESIGN_TELEGRAM_ID, number, "Revised delivery");
    await pollRequestStatus(request, id, "ready_for_review");

    await staffBotComplete(request, E2E_SALES_TELEGRAM_ID, number);
    await pollRequestStatus(request, id, "completed");

    const staff = await loginStaff(request);
    const show = await request.get(`${API}/admin/requests/${id}`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    expect(show.ok()).toBeTruthy();
    expect((await show.json()).data.status).toBe("completed");

    await openStaffApp(page, request, `/requests/${id}`);
    await expect(page.getByRole("heading", { name: number })).toBeVisible();
    await expect(page.getByTestId("request-status")).toHaveText(/Completed|مكتمل/);
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

    await openStaffApp(page, request, `/requests/${id}`);
    await expect(page.getByRole("heading", { name: number })).toBeVisible();

    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page.getByRole("heading", { name: number })).toBeVisible();
    const statusSelect = page.locator("form.toolbar select.field");
    await expect(statusSelect).toHaveValue("submitted");
    await expect(page.getByRole("button", { name: /Mark as paid|تأكيد الدفع/ })).toHaveCount(0);

    await sendQuotation(request, id, 900);
    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page.getByTestId("request-status")).toHaveText(/Quotation sent|عرض سعر/);
    await expect(page.getByRole("button", { name: /Mark as paid|تأكيد الدفع/ })).toHaveCount(0);

    await approveQuotation(request, number, created.telegramId);
    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page.getByTestId("request-status")).toHaveText(/Awaiting payment|بانتظار الدفع/);
    await expect(page.getByRole("button", { name: /Mark as paid|تأكيد الدفع/ })).toBeVisible();

    const verify = await getRequest(request, id);
    expect(verify.data.status).toBe("awaiting_payment");
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
