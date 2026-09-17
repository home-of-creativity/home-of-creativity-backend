# Telegram bots

Path: `backend/bot/`  
Python 3, `python-telegram-bot==21.6`, httpx, dotenv. Shared runner: `telegram_http.py` (polling default, webhook if `TELEGRAM_WEBHOOK_URL`).

| File | Role | Webhook port |
| --- | --- | --- |
| `main.py` | Client bot | 8445 (`client-bot`) |
| `staff.py` | Staff bot | 8444 (`staff-bot`) |
| `admin.py` | Admin ClickUp bot | 8446 (`admin-bot`) |

```powershell
cd backend\bot
python main.py
python staff.py
python admin.py
```

Loads parent `.env` + local. API base `HOC_API_URL`. Secrets: `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_SECRET`, staff equivalents, `TELEGRAM_ADMIN_BOT_TOKEN` / `TELEGRAM_ADMIN_BOT_SECRET`, allowlist `TELEGRAM_ADMIN_IDS`. Optional `TELEGRAM_PROXY`.

HTTP: client → `/api/bot/telegram/*`, staff → `/api/bot/staff/*`, admin → `/api/bot/admin/*`, header `X-Webhook-Secret`.

Reply Keyboard flows stay; Inline buttons expand them (catalog, quotation reject reasons, renewal).

## Client bot

`/start` sends an Arabic welcome (what the bot does, why phone and company are required) then collects missing profile fields. Link account; if name/phone/company missing, collect them then push `crm.lead` on stage **تلغرام** (and CRM team تلغرام) in a background `odoo:push-client` process (HTTP returns immediately). `GET /me` is local profile only. `/start` uses the link payload and replies even if a later API call times out.  
New request: CMS catalog (categories → subcategories or packages → billing periods that exist, **no prices on buttons**, last option always **طلب يدوي**). Package+period creates a `quotation_sent` request with Odoo PDF when Odoo is up.  
طلباتي shows Arabic status, package, paid/remaining, renew buttons when `allows_renewal`.  
Quotation: approve or reject first; after reject the client picks غالي / تأخير / سبب مكتوب. Receipt upload prefers the re-requested number, then `receipt_reupload_required`, then awaiting payment. Approving a quotation sends payment instructions (50% or full) only — the invoice PDF is sent later, after staff confirm the received amount from the dashboard.

## Staff bot

Join/register (`POST /api/bot/staff/join`), tasks, new requests (sales), reply templates, send quotation, deliver. New codes take the next free `EMP-%04d` from the highest numeric suffix (not latest `id`), inside a DB transaction. Cards show number, client, company, package or manual, status, assignee. Sales: **قيد التجهيز** (`in_progress` from `payment_confirmed`) and **إكمال الطلب**. Production: in-progress + deliver. HTTP errors from join show an Arabic retry, not a raw traceback.

## Admin bot

Allowlisted Telegram IDs only (`TELEGRAM_ADMIN_IDS`; `/api/bot/admin/me` is 403 otherwise). Invite ClickUp guest (email required), list departments, list tasks with assignees, assign only members of the chosen department list. Calendar due alerts: `ops:clickup-due-alerts`.

## Flowchart tests

`tests/Feature/BotFlowchartTest.php` covers the bot API contract: webhook secrets (401), client profile stairs (phone then company), catalog/manual/edit/cancel/support, quote reject → staff requote → pay → staff in-progress → deliver → revision → client complete, staff join pending until dashboard approve, admin allowlist + guest email + assign-in-department. Run with `php artisan test --filter=BotFlowchartTest`.

## Do not assume

Bots are not a shopper storefront. Website contact form does not call these endpoints. Live PDF Telegram check: `scripts/e2e-live-telegram.php` + `E2E_TELEGRAM_CHAT_ID` (optional, not CI). Missing Google keys do not abort a request. `TELEGRAM_STRICT=false` keeps catalog/pay local if Odoo/Telegram fail.
