# Staff dashboard (Vite SPA)

Path: `backend/dashboard/`  
React **19**, TypeScript, Vite **7**, react-router-dom **7**. No Next.js.

Dev: `npm run dev` → http://127.0.0.1:5173/dashboard/  
API: `VITE_API_URL` default `http://127.0.0.1:8000/api`  
Build: `tsc --noEmit && vite build` (`vite.config` `base` `/dashboard/`, React Router `basename` `/dashboard`)

## Auth

`src/auth.tsx` + `src/api.ts`. Login `POST /auth/login`. Token key `hoc-staff-token` in **localStorage**. Requires `user.is_admin === true`. Canonical URL **https://hoc.agency/dashboard**: logged out → login form, logged in → overview. `/dashboard/login` and old `/staff` redirect there.

Demo: `admin@example.com` / `password`.

## Routes (`src/App.tsx`)

| Path | Page |
| --- | --- |
| `/` | Overview |
| `/requests` `/requests/:id` | Requests — payment badges, remaining confirm, **received amount** before confirm (defaults to expected 50%/full/remaining), paid/remaining %, quotation form **payment plan + line quantity** with **USD** unit prices, client receipt re-request, **shared Sham Cash QR** (sent to every client on quotation approve, separate from client receipts), Drive folder id |
| `/employees` | Employees — GET pulls Odoo HR and hydrates names/phones; POST/PUT push; DELETE unlinks `hr.employee`; table polls every 15s; **no sync button**, **no join-request card** — pending staff stay in the table |
| `/clients` | Clients + logos + live Odoo quotations/invoices — GET pulls every Odoo CRM stage and pushes missing dashboard opportunities; Telegram people appear only after name+phone+company (then partner+lead push); tabs poll every 15s; **no CRM import/sync button** (Excel import remains) |
| `/contact` | Contact channels |
| `/pricing` | Pricing CMS — every category `requires_full_payment` + `allows_renewal` badges; package partial-pay inherit/true/false |
| `/projects` `/categories` | Portfolio |
| `/reels` | Landing reels CMS — upload/replace/publish/sort; empty until staff add clips |
| `/social` `/social/compose` `/social/compose/:id` | Posts (feed, reel, story, album). New compose selects the page with **Facebook + Instagram + Threads** checked; a standalone LinkedIn organization page gets its own feed-only tile. New Pages Experience and unsupported Graph story/reel posts map to Arabic/English copy; Instagram uses `/media`; Threads uses `graph.threads.net`; LinkedIn uses the versioned REST `/rest/posts`. |
| `/social/calendar` `/social/inbox` `/social/accounts` | Calendar, detailed inbox (post + reply), accounts (Facebook + linked Instagram + Threads, plus standalone LinkedIn company Pages). Accounts has **Connect Threads** (`GET /api/admin/social/threads/connect` → Meta → `https://hoc.agency/auth/threads/callback`) and **Connect LinkedIn** (`GET /api/admin/social/linkedin/connect` → LinkedIn → `https://hoc.agency/auth/linkedin/callback`, connects every organization the user administers). Inbox syncs and replies to LinkedIn comments natively (posts published from the dashboard only); LinkedIn has no message-kind inbox items since Pages have no DM API. |

Social nav gated by `social_abilities` (`accounts`, `create`, `approve`, `engage`).

i18n: `src/i18n.ts` (ar/en). `index.html` boots `lang`/`dir` from `hoc-dash-locale` (default Arabic RTL) before React; `readLocale`/`applyLocale` are localStorage-safe. Telegram staff bot link: `VITE_TELEGRAM_STAFF_BOT`.

Writes from the dashboard push to Odoo immediately. **Clients** GET pulls CRM leads from every pipeline stage and creates missing Odoo opportunities for dashboard rows; Telegram stubs without a complete profile stay hidden and are not pushed. Quotation/invoice tabs read Odoo live and refresh every 15s; request detail hydrates quotation/invoice ids, amounts, and live state from Odoo and refreshes every 15s. POST/PUT/DELETE clients push partner + lead the same way. **Employees** GET pulls `hr.employee` and hydrates/deletes local rows; POST/PUT/DELETE push or unlink HR. Confirm payment stays here: staff review the receipt, enter the amount actually received, then the API calculates paid/remaining % and sends the invoice. Gemini classifies title/description, not OCR.

## Do not assume

This is not the marketing site and not Filament. Clients do not use this SPA.
