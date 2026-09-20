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
New request: CMS catalog (categories → subcategories or packages → billing periods that exist, **no prices on buttons**, last option always **طلب يدوي**, **السابق** on every step including the first — first step returns to the main menu). Catalog callbacks replace the previous step. Choosing a package/period shows loading («جاري إنشاء طلبك وانتظار عرض السعر») then an Arabic confirmation. If `quotation_delivered` the client is told the quote is arriving; otherwise they are told to open طلباتي. Sending the quotation PDF does **not** send «تمت الموافقة على عرض السعر». After the client taps موافقة, Laravel sends payment instructions / Sham Cash QR only (no extra approval line). Keyboard buttons cancel pending revision/reject and leave a manual-request or support conversation. Complete profile (name/phone/company) **pushes the CRM lead immediately**.  
طلباتي sends one card per request with Arabic `status_label`, package, paid/remaining, and buttons (10 at a time, then **عرض الأقدم**). Drive files are the client delivery: each file has **تعديل هذه الصورة**, **أوافق على هذه الصورة**, and **تعديل الطلب بالكامل**; **اعتماد التسليم** appears after the folder is idle ~2 minutes (or staff deliver). A file revision notifies sales/production with that image plus the client’s reason. Approving a file (or the whole delivery) notifies staff that the client accepted it. Staff/admin cannot mark a Drive request completed until every sent file is approved. Poll lists the request folder recursively, resolves Google shortcuts (Photos “add”), and also picks loose files sitting in the company folder (not the Hoc Client root). Replacing the same Drive file (new `modifiedTime`/hash) or uploading a new Drive id with the **same filename** updates that delivery and the client caption says it was modified; an unknown name is a new file. Revision taps (`revision:` / `revfile:`) are handled first: silent callback ack + a normal ForceReply chat message (no Telegram dialog). File approval (`okfile:`) posts `POST /bot/telegram/requests/{id}/approve-file`. Intent is kept even if the client is mid “طلب جديد”; the reason is not stolen as a new title, and API errors (e.g. no files yet) are shown in Arabic. Completed/cancelled folders are not polled. Support notifies sales. Photos become receipts only after **رفع وصل الدفع** or quotation approve, and the hint clears on nav. Catalog period taps are debounced (same package/period within 3 minutes returns the existing request). `ops:poll-drive` every minute over live folders (limit 200), or `ops:poll-drive --file={id}` / `--request={id}`. n8n Drive trigger only sees items sitting **directly** in HOC Clients (not nested request folders). Nested uploads go out immediately via Google `changes.watch` → `POST /api/integrations/drive/changed` (lists the changed file ids) or within a minute via `ops:poll-drive`. Dashboard **تحديث الإرسال** is a manual fallback. Default API `:8000`. The staff request page shows whether each Drive file reached the client bot. The **client bot must be running** for revision buttons; Drive send itself is Laravel → Telegram.  
Quotation: approve or reject first; after reject the client picks غالي / تأخير / سبب مكتوب. Sales staff are notified with a private Telegram link (`tg://user?id=`) to the client who decided. Approving a quotation with a payable amount sends the **shared Sham Cash QR** plus 50%/full payment instructions with amounts in **USD**. If the quotation has no amount, the client gets an acknowledgment only (no QR and no payment instructions). That QR is not the client's proof-of-payment photo. The client then uploads their transfer proof. Sales receive the receipt (or the approval notice) with a **ClickUp work plan** (department, assignee, priority, hours) and an inline **تأكيد الدفع** button (`payok:{number}` → `POST /api/bot/staff/confirm-payment`). Staff can still confirm the received amount from the dashboard. The invoice PDF is sent after confirm. Package work plans are cached so a repeated catalog package assigns immediately without a second large AI review.

## Bot SLA (`ops:process-bot-sla`)

Laravel decides eligibility (every minute, Asia/Damascus, `ops_follow_ups` once-per-kind). Conversation stays in the Python bots — catalog, approve/reject, receipt upload, support chat, and reject reasons are **not** n8n canvases. n8n may retry a staff/client text via `POST /integrations/telegram/notify`, or kick Drive delivery via `POST /integrations/drive/poll` (published **HOC Drive to Client Bot**: OAuth **Google Drive account** watches **HOC Clients only** for fileCreated / fileUpdated / folderCreated, plus webhook `hoc-drive-file`; files and folders outside HOC Clients are ignored). Live n8n HTTP nodes do not read `$env` (Cloud blocks it) and call `https://api.hoc.agency` with `X-N8N-Secret`.

| Gap | After | Who | What |
| --- | --- | --- | --- |
| Submitted, no quotation | 2h | Sales | Card: number, client, `tg://user?id=` |
| `awaiting_payment`, no receipt | 12h then 24h | Client then sales | Client: reminder + Sham Cash QR; sales escalate. Skipped when `isFullyPaid()` / no amount due |
| Receipt uploaded, still unpaid | 3h | Sales | Re-send payok card with expected USD. Skipped when the invoice is already fully paid |
| Payment confirmed | immediately | Client | «بدأ التنفيذ — القسم — المتوقع» (not cron) |
| `revision_requested` / support unanswered | 1h | Production + sales / sales | Staff card |
| Incomplete Telegram profile | 1 day | Client | One reminder (name/phone/company) |
| Morning digest | 09:00 Damascus | Sales | Counts: awaiting quote, awaiting receipt, receipts to review, open revisions |
| Quote / invoice / Drive Telegram send failed | on failure | Sales + admin ids | Once per artifact |

Hours: `services.telegram.sla.*` / `BOT_SLA_*`. Remaining-balance cron (`ops:process-reminders`) also closes leftover reminders when the invoice is complete. Tests: `tests/Feature/BotSlaAutomationTest.php`.

## Staff bot

Join/register (`POST /api/bot/staff/join`), tasks, new requests (sales), reply templates, send quotation, deliver. New codes take the next free `EMP-%04d` from the highest numeric suffix (not latest `id`), inside a DB transaction. Cards show number, client, company, package or manual, status, assignee. Sales: **قيد التجهيز** (`in_progress` from `payment_confirmed`), **إكمال الطلب**, and the **تأكيد الدفع** button on the payment-stage card. Production: in-progress + deliver. HTTP errors from join show an Arabic retry, not a raw traceback.

## Admin bot

Allowlisted Telegram IDs only (`TELEGRAM_ADMIN_IDS`; `/api/bot/admin/me` is 403 otherwise). This is the ops desk: **المهام** (open a ClickUp task → status / priority / due hours / assign), **إسناد ClickUp**, **العملاء** (review visible clients and their requests), **العمليات** (work-plan departments/assignees + rebuild), **المالية** (received invoice revenue, open remaining, `ops_expenses`, net; add expense by amount/category/note), **الملخص**, plus guest invite and department lists. Calendar due alerts: `ops:clickup-due-alerts`.

## Flowchart tests

`tests/Feature/BotFlowchartTest.php` covers the bot API contract: webhook secrets (401), client profile stairs (phone then company), catalog/manual/edit/cancel/support, quote reject → staff requote → pay → staff in-progress → deliver → revision → client complete, staff join pending until dashboard approve, admin allowlist + guest email + assign-in-department, staff delete then `/me`/`/link` restore with the same requests. Run with `php artisan test --filter=BotFlowchartTest`.

## Do not assume

Bots are not a shopper storefront. Website contact form does not call these endpoints. Live PDF Telegram check: `scripts/e2e-live-telegram.php` + `E2E_TELEGRAM_CHAT_ID` (optional, not CI). Missing Google keys do not abort a request. `TELEGRAM_STRICT=false` keeps catalog/pay local if Odoo/Telegram fail.
