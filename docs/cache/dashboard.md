# Staff dashboard (Vite SPA)

Path: `backend/dashboard/`  
React **19**, TypeScript, Vite **7**, react-router-dom **7**. No Next.js.

Dev: `npm run dev` → http://127.0.0.1:5173/staff  
API: `VITE_API_URL` default `http://127.0.0.1:8000/api`  
Build: `tsc --noEmit && vite build`

## Auth

`src/auth.tsx` + `src/api.ts`. Login `POST /auth/login`. Token key `hoc-staff-token` in **localStorage**. Requires `user.is_admin === true`. Login routes: `/staff`, `/login`.

Demo: `admin@example.com` / `password`.

## Routes (`src/App.tsx`)

| Path | Page |
| --- | --- |
| `/` | Overview |
| `/requests` `/requests/:id` | Requests |
| `/employees` | Employees |
| `/clients` | Clients + logos |
| `/contact` | Contact channels |
| `/pricing` | Pricing CMS |
| `/projects` `/categories` | Portfolio |
| `/social` `/social/compose` `/social/compose/:id` | Posts (feed, reel, story, album) |
| `/social/calendar` `/social/inbox` `/social/accounts` | Calendar, detailed inbox (post + reply), accounts |

Social nav gated by `social_abilities` (`accounts`, `create`, `approve`, `engage`).

i18n: `src/i18n.ts` (ar/en). Telegram staff bot link: `VITE_TELEGRAM_STAFF_BOT`.

## Do not assume

This is not the marketing site and not Filament. Clients do not use this SPA.
