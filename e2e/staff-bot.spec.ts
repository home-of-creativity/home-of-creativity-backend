import { expect, test } from "@playwright/test";
import {
  API,
  approveQuotation,
  E2E_DESIGN_TELEGRAM_ID,
  E2E_SALES_TELEGRAM_ID,
  STAFF_BOT_SECRET,
  ensureExecutionReady,
  getRequest,
  sendQuotation,
  staffBotDeliver,
  staffBotQuotation,
  submitTelegramRequest,
} from "./helpers";

test.describe("Staff bot API", () => {
  test("sales staff can send quotation through staff bot", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "Staff bot quotation",
      description: "Sales sends quotation through staff bot.",
    });

    await staffBotQuotation(request, E2E_SALES_TELEGRAM_ID, created.data.number, 800);
    const quoted = await getRequest(request, created.data.id);
    expect(quoted.data.status).toBe("quotation_sent");
  });

  test("non-sales cannot access intake or send quotations", async ({ request }) => {
    const intake = await request.get(`${API}/bot/staff/new-requests?telegram_user_id=${E2E_DESIGN_TELEGRAM_ID}`, {
      headers: { "X-Webhook-Secret": STAFF_BOT_SECRET },
    });
    expect(intake.status()).toBe(403);

    const created = await submitTelegramRequest(request, {
      title: "Forbidden quotation",
      description: "Design staff must not quote.",
    });

    const quote = await request.post(`${API}/bot/staff/quotation`, {
      headers: { "X-Webhook-Secret": STAFF_BOT_SECRET },
      data: {
        telegram_user_id: E2E_DESIGN_TELEGRAM_ID,
        request_number: created.data.number,
        amount: 500,
      },
    });
    expect(quote.status()).toBe(403);
  });

  test("assigned designer can deliver an in-progress task", async ({ request }) => {
    const { telegramId, data } = await submitTelegramRequest(request, {
      title: "Designer delivery",
      description: "Assigned designer delivers work.",
    });

    await sendQuotation(request, data.id, 700);
    await approveQuotation(request, data.number, telegramId);
    const detail = await getRequest(request, data.id);
    await ensureExecutionReady(request, data.id, detail.data.uuid as string, data.number);

    await staffBotDeliver(request, E2E_DESIGN_TELEGRAM_ID, data.number, "Delivery notes");
    const delivered = await getRequest(request, data.id);
    expect(delivered.data.status).toBe("ready_for_review");
  });
});
