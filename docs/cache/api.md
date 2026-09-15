# Laravel API

Path: `backend/`  
Laravel **12.69.x**, PHP **^8.2** (CI 8.3), Sanctum **^4**. JSON prefix `/api`.  
Serve: `php artisan serve --host=127.0.0.1 --port=8000`  
Health: `GET /up`. Web: `GET /` → default welcome (not the marketing site).

## Auth

- Login `POST /auth/login` → Sanctum token. Throttle `AUTH_THROTTLE_PER_MINUTE` (default 5).
- `POST /auth/register` → **403** (accounts only via Telegram bot).
- Admin: `users.is_admin` + `EnsureAdmin`. Policy: `ServiceRequestPolicy`.
- **No Filament.** `dont-discover` leftovers only.

Seeded: `admin@example.com` / `password` (admin), `test@example.com` / `password` (client).

## Public

| Method | Path |
| --- | --- |
| GET | `/pricing` `/contact` |
| GET | `/portfolio/clients` `/portfolio/projects` `/portfolio/projects/{id}` |
| GET | `/social/instagram-feed` `/social/facebook-feed` |

## Client (`auth:sanctum`)

`GET/POST /requests`, `GET /requests/{id}` — own requests only.

## Admin (`auth:sanctum` + `admin`)

Overview, contact channels, clients, employees (approve/reject), service requests (quotation, confirm-payment, retry-gemini, receipts), portfolio CMS, pricing CMS, social (accounts/posts with feed-reel-story placement, carousel, inbox with source post + message replies/staff), Odoo (status, sync partners/employees, import CRM, quotations/invoices), ClickUp members, retry integration events.

## Webhooks (`VerifySharedSecret`)

`POST /webhooks/n8n` and `/integrations/{odoo/quotation,odoo/invoice,clickup/tasks,clickup/mapping,telegram/notify}`. Header `X-Webhook-Secret` or `X-N8N-Secret`. Config: `services.n8n.webhook_secret`.

Bot HTTP APIs: `/bot/telegram/*` (client secret), `/bot/staff/*` (staff secret). See [bots.md](bots.md).

## Domain hub

`ServiceRequest` is the hub: files, events, briefs, revisions, ClickUp tasks, quotations, invoices, deliveries, support, integration outbox.

Other models: User↔Client, Employee (telegram id, no User FK), Portfolio*, Pricing*, ShowcaseClient, ContactChannel, Social*.

Integrations live in `app/Actions/`, `app/Services/` (Odoo, ClickUp, Gemini, Telegram, Facebook Graph). Jobs: Gemini classify, integration dispatch, social publish.

Artisan: `social:publish-due` (every minute, Asia/Damascus), `social:sync-inbox` / `social:sync-posts` (15 min), `integration:process-outbox`, `e2e:purge`.

## Cache / queue

Default `CACHE_STORE=database` (`cache` + `cache_locks` tables). Tests: array store, sync queue, SQLite memory. Instagram landing feed cached 600s.

## Tests / CI

PHPUnit `tests/Feature` + `tests/Unit`. Playwright `e2e/` (starts API + dashboard). Optional landing: `E2E_REQUIRE_LANDING=1`. Local E2E API port **8002**. CI: `.github/workflows/ci.yml` (phpunit, dashboard build, e2e with Gemini/Telegram stubs).

`TELEGRAM_STRICT=false` keeps quotation/invoice flows alive when Telegram is down. `GEMINI_E2E_STUB` for tests.

## Do not assume

Marketing site is **not** in this repo. No public registration, no client web portal, no Docker runtime in-tree, n8n JSON in `n8n/` is not auto-deployed. Live Odoo/ClickUp/Meta need credentials.
