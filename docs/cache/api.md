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

Admin request show includes `google_drive_folder_url` and `client.telegram_url` (`tg://user?id=` when the Telegram id is numeric). Sales approve/reject notifications append `تواصل خاص`. Admin confirm-payment (`POST /admin/requests/{id}/confirm-payment`) requires `payment_method` and **`amount`** (the sum actually received after receipt review). Paid/remaining percents are derived from that amount; the invoice is issued then (`kind=received`), not on quotation approval. Status moves to **`payment_confirmed` (مدفوع)** on confirm even if Gemini is still pending or later fails. Gemini classifies after confirm (Arabic briefs via Google Translate before ClickUp) and does not send a second invoice.

Ops settings (admin): `GET /admin/ops-settings` (`sham_cash_qr`, `sham_cash_qr_updated_at`), `GET/POST /admin/ops-settings/sham-cash-qr` (preview/upload), `GET/PUT /admin/ops-settings/social-profile` (`display_name`, `bio`, `theme` cream|purple|dark stored in OpsSetting `social_linktree_profile`).

## Admin (`auth:sanctum` + `admin`)

Overview (`pending_employees`, `by_status`, `recent` last 6 requests), contact channels, clients (CRUD with **soft delete**; GET lists SQL and, for up to 3 complete Telegram clients missing a lead, creates `crm.lead` on team+stage **تلغرام**; full CRM import stays on `odoo:reconcile` / Excel; bot `/me`/`/link` restores the same `telegram_user_id` so requests survive), employees (CRUD; GET on php-fpm/tests pulls `hr.employee` then lists SQL, prune locals missing from a complete HR list; `cli-server` lists SQL only; show still hydrates one row), service requests (quotation/invoice hydrate from live Odoo on GET, confirm-payment, retry-gemini, receipts, re-request-receipt, renew, Sham Cash QR), portfolio CMS, landing reels CMS (`GET/POST /admin/reels`; published clips on public `GET /reels`; no bundled seeder), pricing CMS (`requires_full_payment` / `allows_renewal` on every category), social (accounts/posts with optional caption; feed-reel-story placement; Facebook Page stories/reels use `/photo_stories`, `/video_stories`, `/video_reels` + rupload; carousel; inbox with source post + message replies/staff; New Pages Experience uses photos/videos and Instagram `/media` instead of `/published_posts` `/feed` `/conversations`; Threads OAuth at `GET /auth/threads/callback` (Caddy `/auth*` → Laravel) and `GET /api/admin/social/threads/connect`; default redirect `https://hoc.agency/auth/threads/callback`; long-lived token stored on `SocialAccount`; also accepts `THREADS_ACCESS_TOKEN`; publishes via `graph.threads.net`; Graph HTTP uses `SOCIAL_GRAPH_CONNECT_TIMEOUT` (default 10s) and maps DNS/connect failures to `graph_timeout` instead of raw cURL 28; Threads images from a local `APP_URL` use `rupload.facebook.com/threads` instead of unpublished Facebook `/photos` so New Pages Pages do not leak `facebook_new_pages_experience`; LinkedIn OAuth at `GET /auth/linkedin/callback` and `GET /api/admin/social/linkedin/connect`, default redirect `https://hoc.agency/auth/linkedin/callback`, publishes text/image/video to organization Pages via `api.linkedin.com/rest/posts` (feed placement only; edit unsupported, so republish instead); LinkedIn inbox comments sync natively via `rest/socialActions/{shareUrn}/comments` for posts published through the dashboard (`SocialInboxItem.external_id` stores the composite `urn:li:comment:(shareUrn,commentId)`; no per-comment liveness pruning since LinkedIn has no single-object lookup, only per-post refresh diffing); replies post natively as nested comments (`parentComment`); LinkedIn has no page-messaging equivalent so DM-kind inbox items are unsupported for it), Odoo (status, Excel CRM import, live quotations/invoices on GET — **no dashboard sync buttons**), ClickUp members, retry integration events.

## Webhooks (`VerifySharedSecret`)

`POST /webhooks/n8n` and `/integrations/{odoo/quotation,odoo/invoice,clickup/tasks,clickup/mapping,telegram/notify}`. Header `X-Webhook-Secret` or `X-N8N-Secret`. Config: `services.n8n.webhook_secret`.

Bot HTTP APIs: `/bot/telegram/*` (client secret), `/bot/staff/*` (staff secret), `/bot/admin/*` (admin secret + `TELEGRAM_ADMIN_IDS`). Client approve returns the shared Sham Cash QR (`sham_cash_qr`, `GET /bot/telegram/sham-cash-qr`) when a payable amount is known — not the client's transfer proof. See [bots.md](bots.md).

## Domain hub

`ServiceRequest` is the hub: files, events, briefs, revisions, ClickUp tasks, quotations, invoices, deliveries, support, integration outbox.

Other models: User↔Client, Employee (telegram id, no User FK; join codes `EMP-%04d` from max suffix), Portfolio*, Pricing*, LandingReel, ShowcaseClient, ContactChannel, Social*.

Integrations live in `app/Actions/`, `app/Services/` (Odoo, ClickUp, Gemini Arabic briefs, Telegram, Facebook Graph, Threads Graph, Google Drive/Calendar, ElevenLabs STT). Jobs: Gemini classify, integration dispatch, social publish, Drive poll, payment reminders. Payment-confirmed requests create a Drive folder under `GOOGLE_DRIVE_PARENT_FOLDER_ID` (Hoc Client) named from the client **company name**, never the Telegram id. If create failed or was skipped, opening the paid request (`GET /admin/requests/{id}`), confirming remaining payment, or `ops:poll-drive` retries until `google_drive_folder_id` is set. Search uses `corpora=allDrives`.

Artisan: `social:publish-due` (every minute, Asia/Damascus), `social:sync-inbox` / `social:sync-posts` (15 min), `social:sync-accounts` (Facebook/Instagram/Threads), `integration:process-outbox`, `odoo:reconcile --limit=500` (every minute: pull CRM/HR then hydrate up to 500 clients — effectively the whole table each run — push missing employees, and always push missing `crm.lead` rows onto the existing **تلغرام** stage/team — never create a second CRM team; this is how an Odoo-side edit, e.g. a manual stage move, reaches the dashboard within ~1 minute). Deploy reloads php-fpm + scheduler and runs `odoo:push-telegram` (prints stage/team/user ids and each create error). Opportunities set `type=opportunity` and `user_id` of the sales team leader (falls back to the API user) so they appear on مخطط سير العمل / مخطط سير عملي. Every Telegram lead always carries the **تلغرام** CRM tag (`crm.tag`, add-only command so it never removes tags a staff member added in Odoo). A dashboard/bot profile edit (`PushClientLeadToOdoo::writeLead`) only ever writes contact fields (name/phone/email/partner) — it never re-sends `stage_id`/`team_id`/`user_id`, so it cannot drag an opportunity that progressed past تلغرام (e.g. **تم الفوز بها**) back to the initial stage; only the initial `create` sets those. Quotations (`sale.order`) are created with `opportunity_id` pointing at the client's `crm.lead` so they show under the opportunity in Odoo. `ConfirmRequestPayment` moves the opportunity to **تم الفوز بها** (`OdooClient::markLeadWon`, sets `probability=100` + `date_closed` + `expected_revenue` = sum of the client's fully paid request totals) once the request is fully paid (100%); sending a quotation writes the client's open+won quote totals to `expected_revenue`; `odoo:reconcile` backfills the same field. `RejectQuotation` moves it to **خسارة**. `odoo:purge-crm` (delete CRM leads/customer partners and reset local Odoo ids), `ops:process-reminders` (every minute), `ops:poll-drive` (5 min, creates missing paid-request folders then retries unsent Drive files), `ops:clickup-due-alerts` (hourly, once per task/due-day; staff chat fallback), `seo:submit-sitemap` (daily 06:15 Damascus; IndexNow includes `/social/` `/locations/` + Search Console API), `e2e:purge`.

## Cache / queue

Default `CACHE_STORE=database` (`cache` + `cache_locks` tables). Tests: array store, sync queue, SQLite memory. Instagram landing feed cached 600s. Admin list syncs: `odoo:hr:index-pull` / `odoo:crm:index-pull` / `social:facebook-sync` / `clickup:members` 60s (skipped on PHP built-in `cli-server` except tests).

## Tests / CI

PHPUnit PHPUnit `tests/Feature` + `tests/Unit`. Playwright `e2e/` (starts API + dashboard). Optional landing: `E2E_REQUIRE_LANDING=1`. Local E2E API port **8002**. CI: `.github/workflows/ci.yml` (placeholder; GitHub does not run Laravel). VPS deploy: `.github/workflows/deploy.yml` (SSH key secrets `SSH_HOST` / `SSH_USER` / `SSH_PRIVATE_KEY`, no password). Remote stack: `deploy/compose.yaml` with Caddy TLS for **https://hoc.agency**. `deploy/remote.sh` always runs `php artisan migrate --force` before seed (php-fpm is not recreated on every push). Dashboard dist is built in a one-shot Node container then served by Caddy from `dashboard/dist` at `/dashboard/` (asset URLs `/dashboard/assets/...`). Missing `*.css`/`*.js` on the marketing site return 404 instead of `index.html`. Clients use `SoftDeletes`; `e2e:purge` `forceDelete`s matching rows.

`TELEGRAM_STRICT=false` keeps quotation/invoice/catalog flows alive when Telegram or Odoo is down. `GEMINI_E2E_STUB` for tests. Gemini uses `GEMINI_API_KEY` (AI Studio auth key, `x-goog-api-key` header) and falls back to `GOOGLE_API_KEY`; Maps/standard keys are rejected by Google as of 2026. A blocked Gemini key marks `gemini_status=failed` and never turns confirm-payment into 422. Production env push forces `QUEUE_CONNECTION=database` and never invents `DB_PASSWORD`. Missing Google Drive/Calendar keys log and retry; they do not abort the request. Won in Odoo only after 100% paid. Confirming payment creates the Odoo `account.move`, then `action_post` + `account.payment.register` so `payment_state` becomes `paid` (or `partial`). Opening a locally paid request hydrates the same post/pay if the invoice is still `draft`/`not_paid`. CORS always allows `https://hoc.agency` plus `CORS_ALLOWED_ORIGINS` and raw `http(s)://IP[:port]`.

## Do not assume

Marketing site is **not** in this repo. No public registration, no client web portal. VPS Docker lives in `deploy/`. n8n JSON in `n8n/` is not auto-deployed. Live Odoo/ClickUp/Meta/Threads/LinkedIn need credentials (`THREADS_APP_ID` / `THREADS_APP_SECRET` for OAuth, or `THREADS_ACCESS_TOKEN` as a Threads user token — not the Facebook Page token; `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` for LinkedIn OAuth — requires the Community Management API product and organization admin access, approved by LinkedIn). Caddy sends `/auth*` to Laravel so Meta/LinkedIn can call `https://hoc.agency/auth/threads/callback` and `https://hoc.agency/auth/linkedin/callback`.
