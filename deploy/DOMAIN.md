# Domains (Caddy only)

Production uses **Caddy** inside Docker (`hoc-edge`). There is **no nginx** on the VPS.

## DNS records

Point these **A records** to the VPS IP (`161.97.111.183`):

| Host | Type | Value |
|------|------|-------|
| `@` (hoc.agency) | A | VPS IP |
| `www` | A | VPS IP |
| `api` | A | VPS IP |

Disable any CDN/proxy on these records until TLS is issued (DNS-only / grey cloud).

## Routing (Caddyfile)

| Domain | Serves |
|--------|--------|
| `hoc.agency` | Marketing site + `/dashboard` + `/api` |
| `www.hoc.agency` | Redirect to `https://hoc.agency` |
| `api.hoc.agency` | API only (`/api`, `/up`, `/storage`) |

TLS: automatic via Let's Encrypt (`admin@hoc.agency`).

## After DNS changes

```bash
# On the server
cd /var/www/landing/backend
docker compose --env-file .env -f deploy/compose.yaml exec hoc-edge caddy reload --config /etc/caddy/Caddyfile
```

Or re-run GitHub Actions **Deploy (SSH)** on the backend repo.

## Verify from your PC

```powershell
cd deploy
.\verify-domains.ps1
```
