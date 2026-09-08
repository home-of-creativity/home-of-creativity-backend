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

With Laravel (`php artisan serve`), the dashboard (`cd dashboard && npm run dev`), and optionally the landing site running:

```bash
npm install
npm run e2e
```

