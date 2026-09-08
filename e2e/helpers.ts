import { expect, type APIRequestContext, type APIResponse, type Page } from "@playwright/test";

export const LANDING = "http://localhost:3000/home-of-creativity-profile/";
export const DASHBOARD = "http://127.0.0.1:5173";
export const API = "http://127.0.0.1:8000/api";
export const N8N_SECRET = process.env.N8N_WEBHOOK_SECRET ?? "change-me";
export const BOT_SECRET = process.env.TELEGRAM_BOT_SECRET ?? "change-me-bot";

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

export async function submitTelegramRequest(
  request: APIRequestContext,
  data: { title: string; description: string },
) {
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
  return JSON.parse(text) as { data: { id: number; number: string; status: string; source: string } };
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
  await page.goto(`${DASHBOARD}${path}`, { waitUntil: "networkidle" });
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
