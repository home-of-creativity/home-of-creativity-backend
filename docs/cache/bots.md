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

`/start` sends an Arabic welcome (what the bot does, why phone and company are required) then collects missing profile fields. Keyboard buttons (طلب جديد / طلباتي / الدعم) are not saved as name, phone, or company. Link account; if name/phone/company missing, collect them then push `crm.lead` on sales team **تلغرام** and stage **تلغرام** immediately (HTTP returns after the Odoo create; the lead id is kept even if the follow-up snapshot is empty). Incomplete Telegram stubs are **not** pushed to Odoo and **not** listed on the staff dashboard until the profile is complete. `GET /me` is local profile only. `/start` uses the link payload and replies even if a later API call times out. Staff **soft-delete** hides the client from the dashboard and unlinks Odoo; the next `/me`, `/requests`, `/catalog`, or `/link` **restores the same row** (profile + طلباتي) without the client deleting the Telegram chat. A 404 (legacy hard delete) re-links in the same chat instead of showing «تعذر الاتصال بالخادم».  
New request: CMS catalog (categories → subcategories or packages → billing periods that exist, **no prices on buttons**, last option always **طلب يدوي**). Catalog callbacks **delete the previous step then send the next** (edit only if delete fails). After a package/period is chosen the catalog message is **deleted**; the client is **not** told «تم إنشاء الطلب…». The quotation PDF (or caption) carries approve/reject buttons — no extra confirmation text. The quotation caption includes **الفترة once**. Package+period creates a `quotation_sent` request with Odoo PDF when Odoo is up. Complete profile (name/phone/company) **pushes the CRM lead immediately** (no background artisan process).  
طلباتي shows Arabic status, package, paid/remaining, renew buttons when `allows_renewal`.  
Quotation: approve or reject first; after reject the client picks غالي / تأخير / سبب مكتوب. Approving a quotation with a payable amount sends the **shared Sham Cash QR** plus 50%/full payment instructions with amounts in **USD**. If the quotation has no amount, the client gets an acknowledgment only (no QR and no payment instructions). That QR is not the client's proof-of-payment photo. The client then uploads their transfer proof; the invoice PDF is sent later, after staff confirm the received amount from the dashboard. Receipt upload prefers the re-requested number, then `receipt_reupload_required`, then awaiting payment.

## Staff bot

Join/register (`POST /api/bot/staff/join`), tasks, new requests (sales), reply templates, send quotation, deliver. New codes take the next free `EMP-%04d` from the highest numeric suffix (not latest `id`), inside a DB transaction. Cards show number, client, company, package or manual, status, assignee. Sales: **قيد التجهيز** (`in_progress` from `payment_confirmed`) and **إكمال الطلب**. Production: in-progress + deliver. HTTP errors from join show an Arabic retry, not a raw traceback.

## Admin bot

Allowlisted Telegram IDs only (`TELEGRAM_ADMIN_IDS`; `/api/bot/admin/me` is 403 otherwise). Invite ClickUp guest (email required), list departments, list tasks with assignees, assign only members of the chosen department list. Calendar due alerts: `ops:clickup-due-alerts`.

## Flowchart tests

`tests/Feature/BotFlowchartTest.php` covers the bot API contract: webhook secrets (401), client profile stairs (phone then company), catalog/manual/edit/cancel/support, quote reject → staff requote → pay → staff in-progress → deliver → revision → client complete, staff join pending until dashboard approve, admin allowlist + guest email + assign-in-department, staff delete then `/me`/`/link` restore with the same requests. Run with `php artisan test --filter=BotFlowchartTest`.

## Do not assume

Bots are not a shopper storefront. Website contact form does not call these endpoints. Live PDF Telegram check: `scripts/e2e-live-telegram.php` + `E2E_TELEGRAM_CHAT_ID` (optional, not CI). Missing Google keys do not abort a request. `TELEGRAM_STRICT=false` keeps catalog/pay local if Odoo/Telegram fail.
