import { expect, type APIRequestContext, type APIResponse, type Page } from "@playwright/test";

export const LANDING = "http://localhost:3000/home-of-creativity-profile/";
export const DASHBOARD = "http://127.0.0.1:5173";
export const API = process.env.E2E_API_URL ?? "http://127.0.0.1:8002/api";
export const N8N_SECRET = process.env.N8N_WEBHOOK_SECRET ?? "change-me";
export const BOT_SECRET = process.env.TELEGRAM_BOT_SECRET ?? "change-me-bot";
export const STAFF_BOT_SECRET = process.env.TELEGRAM_STAFF_BOT_SECRET ?? "change-me-staff";
export const E2E_SALES_TELEGRAM_ID = "6350001";
export const E2E_DESIGN_TELEGRAM_ID = "6350002";
export const E2E_DESIGN_CLICKUP_USER_ID = "cu-design-e2e";

let cachedStaffToken: string | null = null;

export function uniqueTelegramId(prefix = "tg") {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

async function postJson(
  request: APIRequestContext,
  url: string,
  options: { data?: unknown; headers?: Record<string, string> },
  attempts = 4,
): Promise<APIResponse> {
  let last: APIResponse | undefined;
  for (let attempt = 0; attempt < attempts; attempt++) {
    last = await request.post(url, options);
    if (last.status() !== 429) {
      return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 8_000));
  }
  return last as APIResponse;
}

export type TelegramRequest = {
  telegramId: string;
  data: { id: number; number: string; status: string; source: string };
};

export async function submitTelegramRequest(
  request: APIRequestContext,
  data: { title: string; description: string },
): Promise<TelegramRequest> {
  const telegramId = uniqueTelegramId();
  const linked = await postJson(request, `${API}/bot/telegram/link`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: {
      telegram_user_id: telegramId,
      name: "E2E Telegram Client",
      locale: "ar",
    },
  });
  expect(linked.ok(), await linked.text()).toBeTruthy();

  const submitted = await postJson(request, `${API}/bot/telegram/requests`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: {
      telegram_user_id: telegramId,
      title: data.title,
      description: data.description,
    },
  });
  const text = await submitted.text();
  expect(submitted.status(), text).toBe(201);
  return { telegramId, data: JSON.parse(text).data };
}

export async function loginStaff(request: APIRequestContext) {
  if (cachedStaffToken) {
    return { data: { token: cachedStaffToken, user: { is_admin: true } } };
  }

  const response = await postJson(request, `${API}/auth/login`, {
    data: { email: "admin@example.com", password: "password" },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  const body = (await response.json()) as { data: { token: string; user: { is_admin: boolean } } };
  cachedStaffToken = body.data.token;
  return body;
}

export async function openStaffApp(page: Page, request: APIRequestContext, path = "/") {
  const staff = await loginStaff(request);
  await page.addInitScript((token: string) => {
    localStorage.setItem("hoc-dash-locale", "en");
    localStorage.setItem("hoc-staff-token", token);
  }, staff.data.token);
  const target = path.startsWith("/") ? path : `/${path}`;
  await page.goto(`${DASHBOARD}${target}`, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(500);
}

export async function n8nCallback(
  request: APIRequestContext,
  event: string,
  requestNumber: string,
  payload: Record<string, unknown> = {},
) {
  const response = await request.post(`${API}/webhooks/n8n`, {
    headers: { "X-N8N-Secret": N8N_SECRET },
    data: { event, request_number: requestNumber, payload },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json() as Promise<{ data: { status: string; number: string; id: number } }>;
}

export async function authHeaders(request: APIRequestContext) {
  const staff = await loginStaff(request);
  return { Authorization: `Bearer ${staff.data.token}` };
}

export async function sendQuotation(
  request: APIRequestContext,
  requestId: number,
  amount = 1500,
  notes = "E2E quotation",
) {
  const response = await request.post(`${API}/admin/requests/${requestId}/quotation`, {
    headers: await authHeaders(request),
    data: { amount, notes },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function approveQuotation(request: APIRequestContext, number: string, telegramId: string) {
  const response = await postJson(request, `${API}/bot/telegram/requests/${number}/approve`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: { telegram_user_id: telegramId },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function rejectQuotation(
  request: APIRequestContext,
  number: string,
  telegramId: string,
  reason: string,
) {
  const response = await postJson(request, `${API}/bot/telegram/requests/${number}/reject`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: { telegram_user_id: telegramId, reason },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function uploadReceipt(request: APIRequestContext, number: string, telegramId: string) {
  const response = await postJson(request, `${API}/bot/telegram/requests/${number}/receipt`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: {
      telegram_user_id: telegramId,
      file_name: "receipt.pdf",
      file_base64: Buffer.from("%PDF-1.4 e2e").toString("base64"),
      mime_type: "application/pdf",
    },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function confirmPayment(request: APIRequestContext, requestId: number, amount = 1) {
  const response = await request.post(`${API}/admin/requests/${requestId}/confirm-payment`, {
    headers: await authHeaders(request),
    data: { payment_method: "cash", amount },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function clickUpMapping(
  request: APIRequestContext,
  payload: Record<string, unknown>,
) {
  const response = await request.post(`${API}/integrations/clickup/mapping`, {
    headers: { "X-N8N-Secret": N8N_SECRET },
    data: payload,
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function staffBotQuotation(
  request: APIRequestContext,
  staffTelegramId: string,
  requestNumber: string,
  amount: number,
) {
  const response = await postJson(request, `${API}/bot/staff/quotation`, {
    headers: { "X-Webhook-Secret": STAFF_BOT_SECRET },
    data: { telegram_user_id: staffTelegramId, request_number: requestNumber, amount },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function staffBotDeliver(
  request: APIRequestContext,
  staffTelegramId: string,
  requestNumber: string,
  notes: string,
) {
  const response = await postJson(request, `${API}/bot/staff/deliver`, {
    headers: { "X-Webhook-Secret": STAFF_BOT_SECRET },
    data: { telegram_user_id: staffTelegramId, request_number: requestNumber, notes },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function staffBotComplete(
  request: APIRequestContext,
  staffTelegramId: string,
  requestNumber: string,
) {
  const response = await postJson(request, `${API}/bot/staff/complete`, {
    headers: { "X-Webhook-Secret": STAFF_BOT_SECRET },
    data: { telegram_user_id: staffTelegramId, request_number: requestNumber },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function requestRevision(
  request: APIRequestContext,
  number: string,
  telegramId: string,
  reason: string,
) {
  const response = await postJson(request, `${API}/bot/telegram/requests/${number}/revision`, {
    headers: { "X-Webhook-Secret": BOT_SECRET },
    data: { telegram_user_id: telegramId, reason },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function patchRequestStatus(
  request: APIRequestContext,
  requestId: number,
  status: string,
) {
  const response = await request.patch(`${API}/admin/requests/${requestId}`, {
    headers: await authHeaders(request),
    data: { status },
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

export async function getRequest(request: APIRequestContext, requestId: number) {
  const response = await request.get(`${API}/admin/requests/${requestId}`, {
    headers: await authHeaders(request),
  });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json() as Promise<{ data: Record<string, unknown> }>;
}

export async function ensureExecutionReady(
  request: APIRequestContext,
  requestId: number,
  requestUuid: string,
  number: string,
) {
  let body = await getRequest(request, requestId);
  let status = body.data.status as string;

  if (!["in_progress", "revision_requested", "ready_for_review"].includes(status)) {
    if (status === "awaiting_payment") {
      await confirmPayment(request, requestId).catch(() => undefined);
      body = await getRequest(request, requestId);
      status = body.data.status as string;
    }
    if (status === "payment_confirmed") {
      await clickUpMapping(request, {
        request_number: number,
        request_uuid: requestUuid,
        event_uuid: crypto.randomUUID(),
        task_type: "design",
        integration_key: `${requestUuid}:0:design`,
        clickup_task_id: "CU-E2E-DESIGN",
        clickup_user_id: E2E_DESIGN_CLICKUP_USER_ID,
      }).catch(() => undefined);
      body = await getRequest(request, requestId);
      status = body.data.status as string;
    }
    if (!["in_progress", "revision_requested", "ready_for_review"].includes(status)) {
      await patchRequestStatus(request, requestId, "in_progress").catch(() => undefined);
    }
  }

  await clickUpMapping(request, {
    request_number: number,
    request_uuid: requestUuid,
    event_uuid: crypto.randomUUID(),
    task_type: "design",
    integration_key: `${requestUuid}:0:design:${E2E_DESIGN_CLICKUP_USER_ID}`,
    clickup_task_id: "CU-E2E-DESIGN",
    clickup_user_id: E2E_DESIGN_CLICKUP_USER_ID,
  }).catch(() => undefined);
}

export async function pollRequestStatus(
  request: APIRequestContext,
  requestId: number,
  expected: string,
  attempts = 15,
  delayMs = 1000,
) {
  for (let i = 0; i < attempts; i++) {
    const body = await getRequest(request, requestId);
    if (body.data.status === expected) {
      return body;
    }
    await new Promise((resolve) => setTimeout(resolve, delayMs));
  }
  const finalBody = await getRequest(request, requestId);
  expect(finalBody.data.status).toBe(expected);
  return finalBody;
}
