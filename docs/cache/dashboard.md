# Staff dashboard (Vite SPA)

Path: `backend/dashboard/`  
React **19**, TypeScript, Vite **7**, react-router-dom **7**. No Next.js.

Dev: `npm run dev` → http://127.0.0.1:5173/dashboard/  
API: same-origin `/api` via Vite proxy → `http://127.0.0.1:8000` (`VITE_API_PROXY` overrides the target; proxy timeout 180s). Production builds use `VITE_API_URL` (default `https://api.hoc.agency/api`).  
Build: `tsc --noEmit && vite build` (`vite.config` `base` `/dashboard/`, React Router `basename` `/dashboard`). Production Caddy serves `dashboard/dist` at `/dashboard/` (no Vite preview proxy).

## Auth

`src/auth.tsx` + `src/api.ts`. Login `POST /auth/login`. Token key `hoc-staff-token` in **localStorage**. Requires `user.is_admin === true`. Canonical URL **https://hoc.agency/dashboard**: logged out → login form, logged in → overview. `/dashboard/login` and old `/staff` redirect there.

Demo: `admin@example.com` / `password`.

## Routes (`src/App.tsx`)

| Path | Page |
| --- | --- |
| `/` | Overview — one `GET /admin/overview` (counts, `pending_employees`, recent 6). Nav badges use the same endpoint every 30s, not employees/requests. |
| `/requests` `/requests/:id` | Requests — payment badges, remaining confirm, **received amount** before confirm (defaults to expected 50%/full/remaining), paid/remaining %, quotation form **payment plan + line quantity** with **USD** unit prices, client receipt re-request, Drive folder id |
| `/employees` | Employees — table from local HR rows; php-fpm GET also pulls Odoo (cached 60s); `artisan serve` skips the live pull. Poll 30s. ClickUp members + Odoo status load once. POST/PUT push; DELETE unlinks `hr.employee`; **no sync button**, **no join-request card** — pending staff stay in the table |
| `/clients` | Clients + logos + live Odoo quotations/invoices — GET lists SQL immediately (no full CRM import). Up to 3 complete Telegram clients missing a lead are created on Odoo team+stage **تلغرام** during that GET. Tabs poll every 30s; **no CRM import/sync button** (Excel import remains). Telegram clients appear after name/phone/company and are pushed to Odoo CRM on that last field. Delete hides the row (soft delete + unlink Odoo); if the same Telegram user messages the bot again they are restored with their requests. |
| `/contact` | Contact channels (numbers, social, addresses) plus **Sham Cash QR** on its own tab (same studio as `/payments`) |
| `/payments` | Client Sham Cash QR studio: Telegram-style preview, upload/replace, 4-step payment path |
| `/pricing` | Pricing CMS — every category `requires_full_payment` + `allows_renewal` badges; package partial-pay inherit/true/false |
| `/projects` `/categories` | Portfolio |
| `/reels` | Landing reels CMS — upload/replace/publish/sort; empty until staff add clips |
| `/social` | Account picker lists **pages** only. After a page is chosen, the rail dock **checks** networks to show/publish. Under the icons: **ستوري / ريلز / بوست** (Reels only while Instagram is checked). Checked Instagram + Threads phones sit side by side like the landing social section (IG profile grid / reels, Threads feed). Compose drop lives inside an Instagram-style post card. The rail lists this account’s posts as Instagram post cards. **Post** tab lists feed only (reels stay on Reels). Tap a photo or video on the phone (grid tile, feed, reel, or story) to open it **full screen inside the bezel** (close, Escape, carousel arrows). Edit stays on ⋯. Saving media shows a Sonner **upload toast** (percent, remaining time, then elapsed). |
| `/social/compose/:id` | Edit an existing post on the same phone studio (new compose redirects to `/social`). |
| `/social/links` | Linktree admin: Links/Stories list + cream/purple/dark phone preview (hummingbird avatar, stacked pills) |
| `/social/design` | Link-in-bio name, bio, theme (`GET/PUT /api/admin/ops-settings/social-profile`) |
| `/social/calendar` `/social/inbox` `/social/accounts` | Calendar, detailed inbox (post + reply), accounts (Facebook + linked Instagram + Threads, plus standalone LinkedIn company Pages). Accounts has **Connect Threads** (`GET /api/admin/social/threads/connect` → Meta → `https://hoc.agency/auth/threads/callback`) and **Connect LinkedIn** (`GET /api/admin/social/linkedin/connect` → LinkedIn → `https://hoc.agency/auth/linkedin/callback`, connects every organization the user administers). Inbox syncs and replies to LinkedIn comments natively (posts published from the dashboard only); LinkedIn has no message-kind inbox items since Pages have no DM API. |

Social nav gated by `social_abilities` (`accounts`, `create`, `approve`, `engage`). Entering `/social` loads `GET /admin/social/accounts` once (`SocialWorkspace`); inner tabs reuse that list. The picker shows pages, not every network row. Home is an immersive phone studio: dock checks which linked networks to show and publish; Story / Reels / Post sit under the icons (Reels only if Instagram is checked). Instagram and Threads phones follow the landing social layout. Change-page sits in the chrome. Calendar/inbox/links filter by the focused network on that page.

i18n: `src/i18n.ts` (ar/en). `index.html` boots `lang`/`dir` from `hoc-dash-locale` (default Arabic RTL) before React; `readLocale`/`applyLocale` are localStorage-safe. Telegram staff bot link: `VITE_TELEGRAM_STAFF_BOT`.

Request detail shows the current Sham Cash QR thumb and links to `/payments` to replace it. Writes from the dashboard push to Odoo immediately. **Clients** GET stays SQL-fast; complete Telegram rows missing `odoo_lead_id` create an opportunity on sales team **تلغرام** and stage **تلغرام** (so the card appears on that kanban). Telegram stubs without a complete profile stay hidden and are not pushed. A client keeps one `odoo_partner_id`; quotations reuse it instead of creating a new partner. Person names are not overwritten by the partner/company label. Quotation/invoice tabs read Odoo live and refresh every 15s; request detail hydrates quotation/invoice ids, amounts, and live state from Odoo and refreshes every 15s. POST/PUT/DELETE clients push partner + lead the same way. **Employees** on php-fpm GET pull `hr.employee` (60s cache) and prune locals missing from a complete list; `artisan serve` reads SQL only so nav/overview stay fast. Confirm payment stays here: staff review the receipt, enter the amount actually received, then the API calculates paid/remaining % and sends the invoice. Gemini classifies title/description, not OCR.

## Do not assume

This is not the marketing site and not Filament. Clients do not use this SPA.
