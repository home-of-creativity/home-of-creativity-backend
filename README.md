# Home of Creativity — Backend

Laravel API, Telegram bots, and the staff dashboard.

## Run

```bash
composer install
php artisan serve --host=127.0.0.1 --port=8000
```

Staff dashboard:

```bash
cd dashboard
npm install
npm run dev
```

Login: http://127.0.0.1:5173/staff

Bots:

```bash
cd bot
python main.py    # clients
python staff.py   # staff
```

Copy `.env.example` to `.env` and set secrets locally. Do not commit `.env`.

## E2E (API + dashboard)

Playwright starts the API (`php artisan serve` on port 8000) and dashboard dev server automatically via `playwright.config.ts`. Optional landing checks use `E2E_REQUIRE_LANDING=1`.

```bash
npm install
cd dashboard && npm install && cd ..
npm run e2e
```

Environment overrides:

- `E2E_PORT` / `E2E_API_URL` — default port `8002` locally (`http://127.0.0.1:8002/api`); CI uses `8000`
- `TELEGRAM_STRICT=false` — quotation/invoice survive Telegram failures in E2E

### Optional live Telegram PDF check (not CI)

Requires a real chat that linked the client bot (`/start`):

```bash
E2E_TELEGRAM_CHAT_ID=123456789 php scripts/e2e-live-telegram.php
```

Set `TELEGRAM_BOT_TOKEN` in `.env`. The script sends a quotation PDF and exercises approve + confirm-payment without failing the run when Telegram is misconfigured (`TELEGRAM_STRICT=false`).

