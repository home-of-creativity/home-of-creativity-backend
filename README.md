# Home of Creativity — Backend

Laravel API, Telegram bots, and the staff dashboard.

GitHub stores this source only. Actions do **not** start Laravel. Production is the Docker stack in the parent `home_of_creativity` folder:

```powershell
cd ..
.\docker-up.ps1
```

## Local run (without Docker)

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

Login: http://127.0.0.1:5173/dashboard

Bots:

```bash
cd bot
python main.py    # clients
python staff.py   # staff
```

Copy `.env.example` to `.env` and set secrets locally. Do not commit `.env`.

## Deploy (SSH key, not a password)

GitHub Actions copies this repo to the VPS and runs Docker there. Login uses an **SSH private key**. Do not add `DEPLOY_PASSWORD`.

### One-time on your PC

```powershell
ssh-keygen -t ed25519 -f $env:USERPROFILE\.ssh\id_ed25519_hoc_deploy -N "" -C "hoc-github-deploy"
Get-Content $env:USERPROFILE\.ssh\id_ed25519_hoc_deploy.pub
```

### One-time on the VPS (password login, once)

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo "PASTE_PUBLIC_KEY_HERE" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
sudo bash  # then after the first sync:
# sudo bash /var/www/landing/backend/deploy/bootstrap.sh
```

Install Docker with `deploy/bootstrap.sh` after the files exist, or install Docker first.

Create `/var/www/landing/backend/.env` from `.env.example`. Set `APP_KEY`, `APP_URL=http://YOUR_IP`, `DB_PASSWORD`, and `CORS_ALLOWED_ORIGINS=http://YOUR_IP/dashboard,http://YOUR_IP`.

### GitHub secrets (Settings → Secrets and variables → Actions)

| Secret | Value |
|--------|--------|
| `SSH_HOST` | Server IP |
| `SSH_USER` | `root` or your sudo user |
| `SSH_PRIVATE_KEY` | Full contents of `id_ed25519_hoc_deploy` (private file) |
| `SSH_PORT` | Optional, default `22` |
| `DEPLOY_PATH` | Optional, default `/var/www/landing/backend` |

Then **Actions → Deploy (SSH) → Run workflow**.

From this machine (same key, no GitHub):

```powershell
cd backend
.\deploy\push.ps1 -HostName YOUR_IP -User root
```

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

