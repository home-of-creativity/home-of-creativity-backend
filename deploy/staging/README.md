# Staging (testers' copy)

A separate copy of the platform for testers, on the same VPS as production (`161.97.111.183`).

| | Production | Staging |
|---|---|---|
| Dashboard | `https://hoc.agency/dashboard` | `https://staging.hoc.agency/dashboard/` |
| API | `https://api.hoc.agency` | `https://staging-api.hoc.agency` |
| Code on server | `/var/www/landing/backend` | `/var/www/staging/backend` |
| Compose project | `hoc-prod` | `hoc-staging` |
| Database | `hoc_platform` in `hoc-prod-mysql-1` | `hoc_testing` in its own MySQL container |

## Safety rules

- Staging has its own MySQL container, its own `.env` and its own storage. Its API and bots sit on the `hoc-staging_staging` network, which has no route to the production MySQL.
- `StagingGuard` stops the app at boot if `APP_ENV=staging` uses any database other than `hoc_testing`, or if production points at `hoc_testing`.
- `staging.sh check` fails if a separated key (APP_KEY, DB password, bot tokens, bot secrets, chat IDs) has the same value as production.
- Never edit the local `backend/.env` for staging. It is the source of the production `.env`.
- Staging data is made up: 5 demo clients (`demo-client-N@example.com`, no phones) and 6 requests `REQ-TEST-000N`. Only the non-personal catalog is copied from production (packages, categories, contact channels, legal pages, roles, report templates, work hours).
- On staging, the scheduler skips the production-only jobs: social publishing, Odoo sync, complaint emails, sitemap submission, health and signal watchers, and the dev digest.

## Production backup

Taken before staging was created, independent of staging:

- Server: `/var/backups/hoc-db/hoc_platform-20261015-080646Z.sql.gz`
- This machine: `E:\backups\hoc-db\hoc_platform-20261015-080646Z.sql.gz`

## 1. DNS (Hostinger, not cPanel)

`hoc.agency` uses Hostinger's nameservers (`aster.dns-parking.com`, `helios.dns-parking.com`). Records added in cPanel are ignored.

In hPanel, go to **Domains**, then **hoc.agency**, then **DNS / Nameservers**, and add these two records. Do not edit or delete any existing record, and do not change the nameservers.

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `staging` | `161.97.111.183` | 300 |
| A | `staging-api` | `161.97.111.183` | 300 |

Check that they resolve:

```powershell
nslookup staging.hoc.agency aster.dns-parking.com
nslookup staging-api.hoc.agency aster.dns-parking.com
```

Both must answer `161.97.111.183`.

## 2. Enable HTTPS for staging (after DNS works)

The production Caddy (`hoc-prod-hoc-edge-1`) owns ports 80 and 443. The staging block at the end of `backend/deploy/Caddyfile` forwards both staging hostnames to `hoc-staging-edge:80`.

On the server, write the updated Caddyfile in place (to keep the bind mount), validate it, then reload:

```bash
docker exec hoc-prod-hoc-edge-1 caddy validate --config /etc/caddy/Caddyfile
docker exec hoc-prod-hoc-edge-1 caddy reload --config /etc/caddy/Caddyfile
```

Then check:

```bash
curl -sI https://staging.hoc.agency/dashboard/ | head -n 1
curl -sI https://api.hoc.agency/up | head -n 1
```

Commit the Caddyfile change before the next production deploy, or the deploy restores the old file and staging stops answering on HTTPS.

## 3. Add a tester login

The tester gets a full admin account on staging only.

From `backend/` on this machine:

```powershell
.\deploy\staging-tester.ps1 -Email tester@example.com -Name "Tester"
.\deploy\staging-tester.ps1 -Email tester@example.com -Generate
.\deploy\staging-tester.ps1 -Email tester@example.com -Remove
```

- Without `-Generate`, you type the password twice (hidden, at least 10 characters).
- With `-Generate`, a password is printed once. Copy it then.
- Running it again for the same email resets the password and signs out old sessions.

The same command directly on the server:

```bash
ssh -t hoc "bash /var/www/staging/backend/deploy/staging.sh tester tester@example.com"
```

## 4. Connect test integrations

Every value is typed into a hidden prompt and only lands in the staging `.env`. If the separation check fails, the old value is restored.

```bash
ssh -t hoc "bash /var/www/staging/backend/deploy/staging.sh set KEY"
ssh -t hoc "bash /var/www/staging/backend/deploy/staging.sh copy-prod KEY"
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh check"
```

| Integration | What to prepare | Keys |
|---|---|---|
| Odoo | A duplicate test database on odoo.com | `set ODOO_URL`, `ODOO_DB`, `ODOO_USERNAME`, `ODOO_API_KEY`, then `ODOO_ENABLED` = `true` |
| Telegram | New test bots from BotFather and a test staff group | `set TELEGRAM_BOT_TOKEN`, `TELEGRAM_STAFF_BOT_TOKEN`, `TELEGRAM_ADMIN_BOT_TOKEN`, `TELEGRAM_STAFF_CHAT_ID` |
| WhatsApp | A separate test number | Scan the QR on the staging dashboard, Channels page |
| ClickUp | Test lists and space | `set CLICKUP_LIST_*`, `CLICKUP_SPACE_ID`, then `copy-prod CLICKUP_TOKEN` |
| Google Drive / Calendar | A test folder and a test calendar | `set GOOGLE_DRIVE_PARENT_FOLDER_ID`, `GOOGLE_CALENDAR_ID`, then `copy-prod GOOGLE_SERVICE_ACCOUNT_JSON` |
| Mail | Optional | `copy-prod` the SMTP keys. Until then, mail is written to the log only |

An Odoo duplicate still contains the real data copied from production Odoo. Delete it when testing is over.

After changing integrations, restart the stack:

```bash
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh deploy"
```

## 5. Update staging code

From `backend/` on this machine (uploads the working tree without `.env`, storage or vendor, then deploys staging only):

```powershell
.\deploy\push-staging.ps1
.\deploy\push-staging.ps1 -UploadOnly
```

Production is not touched. The new safety code (StagingGuard, `staging:tester`, scheduler skips) reaches production only after it is committed and deployed normally.

## Other commands

```bash
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh status"
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh logs api"
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh seed"
ssh hoc "bash /var/www/staging/backend/deploy/staging.sh down"
```

`seed` reads the catalog from production read-only and adds the demo clients again. `down` stops staging; production keeps running.
