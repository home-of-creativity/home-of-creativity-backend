import { expect, test } from "@playwright/test";
import { API, BOT_SECRET, N8N_SECRET, submitTelegramRequest } from "./helpers";

test.describe("Integration endpoints", () => {
  test("odoo quotation and invoice placeholders respond", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "Odoo integration",
      description: "Quotation and invoice smoke test.",
    });
    const number = created.data.number;

    const quotation = await request.post(`${API}/integrations/odoo/quotation`, {
      headers: { "X-N8N-Secret": N8N_SECRET },
      data: { request_number: number, title: "Odoo integration" },
    });
    expect(quotation.ok(), await quotation.text()).toBeTruthy();
    const quotationBody = await quotation.json();
    expect(quotationBody.data.odoo_quotation_id).toBeTruthy();

    const invoice = await request.post(`${API}/integrations/odoo/invoice`, {
      headers: { "X-N8N-Secret": N8N_SECRET },
      data: {
        request_number: number,
        odoo_partner_id: quotationBody.data.odoo_partner_id,
        odoo_quotation_id: quotationBody.data.odoo_quotation_id,
      },
    });
    expect(invoice.ok(), await invoice.text()).toBeTruthy();
    expect((await invoice.json()).data.odoo_invoice_id).toBeTruthy();
  });

  test("clickup tasks endpoint accepts department briefs", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "ClickUp tasks",
      description: "Department brief provisioning.",
    });

    const response = await request.post(`${API}/integrations/clickup/tasks`, {
      headers: { "X-N8N-Secret": N8N_SECRET },
      data: {
        request_number: created.data.number,
        briefs: [{ department: "design", brief: "Visual identity" }],
      },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    expect((await response.json()).data.briefs).toHaveLength(1);
  });

  test("telegram notify and secret rejection", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "Telegram notify",
      description: "Notify endpoint smoke test.",
    });

    const notify = await request.post(`${API}/integrations/telegram/notify`, {
      headers: { "X-N8N-Secret": N8N_SECRET },
      data: {
        request_number: created.data.number,
        text: "Work has started on your request.",
      },
    });
    expect(notify.ok(), await notify.text()).toBeTruthy();

    const bad = await request.post(`${API}/integrations/odoo/quotation`, {
      headers: { "X-N8N-Secret": "wrong-secret" },
      data: { request_number: created.data.number, title: "Blocked" },
    });
    expect(bad.status()).toBe(401);

    const botBad = await request.post(`${API}/bot/telegram/link`, {
      headers: { "X-Webhook-Secret": "wrong-secret" },
      data: { telegram_user_id: "tg-bad", name: "Blocked", locale: "ar" },
    });
    expect(botBad.status()).toBe(401);
    expect(BOT_SECRET).not.toBe("wrong-secret");
  });
});
