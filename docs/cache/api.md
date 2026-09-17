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
- Threads OAuth: staff `GET /api/admin/social/threads/connect` (Sanctum + admin + accounts ability) → Meta authorize URL. Public web `GET /auth/threads/callback` (Caddy `/auth*` on hoc.agency and api.hoc.agency). Canonical redirect URI `https://hoc.agency/auth/threads/callback`. After success, redirect `https://hoc.agency/dashboard/social/accounts?threads=connected`.
- LinkedIn OAuth: staff `GET /api/admin/social/linkedin/connect` (Sanctum + admin + accounts ability) → LinkedIn authorize URL (`LINKEDIN_CLIENT_ID`/`LINKEDIN_CLIENT_SECRET`, scopes `r_organization_social,w_organization_social,rw_organization_admin`). Public web `GET /auth/linkedin/callback`. Canonical redirect URI `https://hoc.agency/auth/linkedin/callback`. Callback fetches every organization the user administers (`organizationAcls`) and creates one `SocialAccount` per company Page; redirects `?linkedin=connected` or `?linkedin_error=...`.

Seeded: `admin@example.com` / `password` (admin), `test@example.com` / `password` (client).

## Public

| Method | Path |
| --- | --- |
| GET | `/pricing` `/contact` `/reels` |
| GET | `/portfolio/clients` `/portfolio/projects` `/portfolio/projects/{id}` |
| GET | `/social/instagram-feed` `/social/facebook-feed` |

## Client (`auth:sanctum`)

`GET/POST /requests`, `GET /requests/{id}` — own requests only.

Admin confirm-payment (`POST /admin/requests/{id}/confirm-payment`) requires `payment_method` and **`amount`** (the sum actually received after receipt review). Paid/remaining percents are derived from that amount; the invoice is issued then (`kind=received`), not on quotation approval. Gemini classifies after confirm and does not send a second invoice.

## Admin (`auth:sanctum` + `admin`)

Overview, contact channels, clients (CRUD and GET keep Odoo CRM partner+lead in sync both ways: dashboard clients become opportunities, Odoo leads at any pipeline stage appear locally; Excel import remains, **no CRM import button**; Telegram `/start` stubs stay off the dashboard and out of Odoo until name+phone+company are complete), employees (CRUD and GET keep `hr.employee` in sync both ways; approve/reject; **no HR sync button**), service requests (quotation/invoice hydrate from live Odoo on GET, confirm-payment, retry-gemini, receipts, re-request-receipt, renew, Sham Cash QR), portfolio CMS, landing reels CMS (`GET/POST /admin/reels`; published clips on public `GET /reels`; no bundled seeder), pricing CMS (`requires_full_payment` / `allows_renewal` on every category), social (accounts/posts with feed-reel-story placement; Facebook Page stories/reels use `/photo_stories`, `/video_stories`, `/video_reels` + rupload; carousel; inbox with source post + message replies/staff; New Pages Experience uses photos/videos and Instagram `/media` instead of `/published_posts` `/feed` `/conversations`; Threads OAuth at `GET /auth/threads/callback` (Caddy `/auth*` → Laravel) and `GET /api/admin/social/threads/connect`; default redirect `https://hoc.agency/auth/threads/callback`; long-lived token stored on `SocialAccount`; also accepts `THREADS_ACCESS_TOKEN`; publishes via `graph.threads.net`; LinkedIn OAuth at `GET /auth/linkedin/callback` and `GET /api/admin/social/linkedin/connect`, default redirect `https://hoc.agency/auth/linkedin/callback`, publishes text/image/video to organization Pages via `api.linkedin.com/rest/posts` (feed placement only; edit unsupported, so republish instead); LinkedIn inbox comments sync natively via `rest/socialActions/{shareUrn}/comments` for posts published through the dashboard (`SocialInboxItem.external_id` stores the composite `urn:li:comment:(shareUrn,commentId)`; no per-comment liveness pruning since LinkedIn has no single-object lookup, only per-post refresh diffing); replies post natively as nested comments (`parentComment`); LinkedIn has no page-messaging equivalent so DM-kind inbox items are unsupported for it), Odoo (status, Excel CRM import, live quotations/invoices on GET — **no dashboard sync buttons**), ClickUp members, retry integration events.

## Webhooks (`VerifySharedSecret`)

`POST /webhooks/n8n` and `/integrations/{odoo/quotation,odoo/invoice,clickup/tasks,clickup/mapping,telegram/notify}`. Header `X-Webhook-Secret` or `X-N8N-Secret`. Config: `services.n8n.webhook_secret`.

Bot HTTP APIs: `/bot/telegram/*` (client secret), `/bot/staff/*` (staff secret), `/bot/admin/*` (admin secret + `TELEGRAM_ADMIN_IDS`). Client approve returns the shared Sham Cash QR (`sham_cash_qr`, `GET /bot/telegram/sham-cash-qr`) when a payable amount is known — not the client's transfer proof. See [bots.md](bots.md).

## Domain hub

`ServiceRequest` is the hub: files, events, briefs, revisions, ClickUp tasks, quotations, invoices, deliveries, support, integration outbox.

Other models: User↔Client, Employee (telegram id, no User FK; join codes `EMP-%04d` from max suffix), Portfolio*, Pricing*, LandingReel, ShowcaseClient, ContactChannel, Social*.

Integrations live in `app/Actions/`, `app/Services/` (Odoo, ClickUp, Gemini Arabic briefs, Telegram, Facebook Graph, Threads Graph, Google Drive/Calendar, ElevenLabs STT). Jobs: Gemini classify, integration dispatch, social publish, Drive poll, payment reminders.

Artisan: `social:publish-due` (every minute, Asia/Damascus), `social:sync-inbox` / `social:sync-posts` (15 min), `social:sync-accounts` (Facebook/Instagram/Threads), `integration:process-outbox`, `odoo:reconcile` (every minute: pull CRM/HR then hydrate rotating client batches and push missing employees), `odoo:purge-crm` (delete CRM leads/customer partners and reset local Odoo ids), `ops:process-reminders` (every minute), `ops:poll-drive` (5 min, retries unsent Drive files), `ops:clickup-due-alerts` (hourly, once per task/due-day; staff chat fallback), `seo:submit-sitemap` (daily 06:15 Damascus; IndexNow + Search Console API), `e2e:purge`.

## Cache / queue

Default `CACHE_STORE=database` (`cache` + `cache_locks` tables). Tests: array store, sync queue, SQLite memory. Instagram landing feed cached 600s.

## Tests / CI

PHPUnit `tests/Feature` + `tests/Unit`. Playwright `e2e/` (starts API + dashboard). Optional landing: `E2E_REQUIRE_LANDING=1`. Local E2E API port **8002**. CI: `.github/workflows/ci.yml` (placeholder; GitHub does not run Laravel). VPS deploy: `.github/workflows/deploy.yml` (SSH key secrets `SSH_HOST` / `SSH_USER` / `SSH_PRIVATE_KEY`, no password). Remote stack: `deploy/compose.yaml` with Caddy TLS for **https://hoc.agency**. Dashboard dist is built in a one-shot Node container then served by Caddy from `dashboard/dist` at `/dashboard/` (asset URLs `/dashboard/assets/...`). Missing `*.css`/`*.js` on the marketing site return 404 instead of `index.html`.

`TELEGRAM_STRICT=false` keeps quotation/invoice/catalog flows alive when Telegram or Odoo is down. `GEMINI_E2E_STUB` for tests. Missing Google Drive/Calendar keys log and retry; they do not abort the request. Won in Odoo only after 100% paid. CORS also reads `CORS_ALLOWED_ORIGINS` and allows raw `http(s)://IP[:port]` for the VPS dashboard.

## Do not assume

Marketing site is **not** in this repo. No public registration, no client web portal. VPS Docker lives in `deploy/`. n8n JSON in `n8n/` is not auto-deployed. Live Odoo/ClickUp/Meta/Threads/LinkedIn need credentials (`THREADS_APP_ID` / `THREADS_APP_SECRET` for OAuth, or `THREADS_ACCESS_TOKEN` as a Threads user token — not the Facebook Page token; `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` for LinkedIn OAuth — requires the Community Management API product and organization admin access, approved by LinkedIn). Caddy sends `/auth*` to Laravel so Meta/LinkedIn can call `https://hoc.agency/auth/threads/callback` and `https://hoc.agency/auth/linkedin/callback`.
