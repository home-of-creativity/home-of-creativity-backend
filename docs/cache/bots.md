# Telegram bots

Path: `backend/bot/`  
Python 3, `python-telegram-bot==21.6`, httpx, dotenv. Shared runner: `telegram_http.py` (polling default, webhook if `TELEGRAM_WEBHOOK_URL`).

| File | Role | Webhook port |
| --- | --- | --- |
| `main.py` | Client bot | 8445 (`client-bot`) |
| `staff.py` | Staff bot | 8444 (`staff-bot`) |

```powershell
cd backend\bot
python main.py
python staff.py
```

Loads parent `.env` + local. API base `HOC_API_URL`. Secrets: `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_SECRET` and staff equivalents. Optional `TELEGRAM_PROXY`.

HTTP: client → `/api/bot/telegram/*`, staff → `/api/bot/staff/*`, header `X-Webhook-Secret`.

## Client bot

Link account, new/list/edit requests, support, receipt upload, approve/reject quotations, lifecycle (acknowledge, cancel, complete, revision).

## Staff bot

Join/register, tasks, new requests (sales), reply templates, send quotation, deliver, complete.

## Do not assume

Bots are not a shopper storefront. Website contact form does not call these endpoints. Live PDF Telegram check: `scripts/e2e-live-telegram.php` + `E2E_TELEGRAM_CHAT_ID` (optional, not CI).
