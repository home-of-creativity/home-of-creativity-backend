# Staff dashboard (Vite SPA)

Path: `backend/dashboard/`  
React **19**, TypeScript, Vite **7**, react-router-dom **7**. No Next.js.

Dev: `npm run dev` → http://127.0.0.1:5173/dashboard/  
API: `VITE_API_URL` default `http://127.0.0.1:8000/api`  
Build: `tsc --noEmit && vite build` (`vite.config` `base` `/dashboard/`, React Router `basename` `/dashboard`)

## Auth

`src/auth.tsx` + `src/api.ts`. Login `POST /auth/login`. Token key `hoc-staff-token` in **localStorage**. Requires `user.is_admin === true`. Login routes: `/dashboard/login`, `/dashboard/staff` (redirect). Production: **https://hoc.agency/dashboard**. Old `/staff` URLs redirect there.

Demo: `admin@example.com` / `password`.

## Routes (`src/App.tsx`)

| Path | Page |
| --- | --- |
| `/` | Overview |
| `/requests` `/requests/:id` | Requests — payment badges, remaining confirm, **received amount** before confirm (defaults to expected 50%/full/remaining), paid/remaining %, receipt re-request, renew, Sham Cash QR, Drive folder id |
| `/employees` | Employees (GET pushes missing Odoo ids; **no sync button**, **no join-request card** — pending staff stay in the table) |
| `/clients` | Clients + logos + live Odoo quotations/invoices — GET pulls every Odoo CRM stage and pushes missing dashboard opportunities; tabs poll every 15s; **no CRM import/sync button** (Excel import remains) |
| `/contact` | Contact channels |
| `/pricing` | Pricing CMS — every category `requires_full_payment` + `allows_renewal` badges; package partial-pay inherit/true/false |
| `/projects` `/categories` | Portfolio |
| `/reels` | Landing reels CMS — upload/replace/publish/sort; empty until staff add clips |
| `/social` `/social/compose` `/social/compose/:id` | Posts (feed, reel, story, album). New Pages Experience and unsupported Graph story/reel posts map to Arabic/English copy; Instagram uses `/media`; Threads uses `graph.threads.net`. |
| `/social/calendar` `/social/inbox` `/social/accounts` | Calendar, detailed inbox (post + reply), accounts (Facebook + linked Instagram + Threads). Accounts has **Connect Threads** (`GET /api/admin/social/threads/connect` → Meta → `https://hoc.agency/auth/threads/callback`). |

Social nav gated by `social_abilities` (`accounts`, `create`, `approve`, `engage`).

i18n: `src/i18n.ts` (ar/en). Telegram staff bot link: `VITE_TELEGRAM_STAFF_BOT`.

Writes from the dashboard push to Odoo immediately. **Clients** GET pulls CRM leads from every pipeline stage and creates missing Odoo opportunities for dashboard rows; quotation/invoice tabs read Odoo live and refresh every 15s; request detail hydrates quotation/invoice ids, amounts, and live state from Odoo and refreshes every 15s. POST/PUT/DELETE clients push partner + lead the same way. Confirm payment stays here: staff review the receipt, enter the amount actually received, then the API calculates paid/remaining % and sends the invoice. Gemini classifies title/description, not OCR.

## Do not assume

This is not the marketing site and not Filament. Clients do not use this SPA.
