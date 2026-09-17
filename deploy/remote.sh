#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
  echo "Missing $ROOT/.env — copy .env.example on the server and fill production secrets before deploy."
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Run deploy/bootstrap.sh once as root."
  exit 1
fi

mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

COMPOSE=(docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml)

"${COMPOSE[@]}" up -d --build

echo "Building dashboard from synced source (preview stays up until restart)..."
"${COMPOSE[@]}" exec -T hoc-dashboard npm install
"${COMPOSE[@]}" exec -T hoc-dashboard npm run build
echo "Restarting dashboard preview..."
"${COMPOSE[@]}" restart hoc-dashboard

echo "Waiting for dashboard preview..."
dashboard_ready=0
for _ in $(seq 1 60); do
  if "${COMPOSE[@]}" exec -T hoc-dashboard node -e "require('http').get('http://127.0.0.1:5173/dashboard/',r=>process.exit(r.statusCode<500?0:1)).on('error',()=>process.exit(1))"; then
    dashboard_ready=1
    break
  fi
  sleep 2
done
if [[ "$dashboard_ready" != 1 ]]; then
  echo "Dashboard preview did not become ready."
  "${COMPOSE[@]}" logs --tail 80 hoc-dashboard
  exit 1
fi

echo "Validating Caddyfile before recreating edge..."
docker run --rm -v "$ROOT/deploy/Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2-alpine \
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

# Caddyfile is a single-file bind-mount. rsync replaces files via temp+rename
# (a new inode), which can detach that bind mount from ever seeing updates.
# Recreate hoc-edge only after the dashboard is serving so /dashboard is not 502.
echo "Recreating edge (Caddy) so it picks up the current Caddyfile..."
"${COMPOSE[@]}" up -d --force-recreate --wait --wait-timeout 60 hoc-edge

echo "Waiting for API php-fpm..."
for _ in $(seq 1 60); do
  if "${COMPOSE[@]}" exec -T hoc-api php artisan --version >/dev/null 2>&1; then
    break
  fi
  sleep 5
done

# hoc-api entrypoint already runs migrate --force and storage:link on start.
"${COMPOSE[@]}" exec -T hoc-api php artisan storage:link || true

echo "Running database seeders..."
"${COMPOSE[@]}" exec -T hoc-api php artisan db:seed --force

"${COMPOSE[@]}" exec -T hoc-api php artisan config:cache
"${COMPOSE[@]}" exec -T hoc-api php artisan route:cache
"${COMPOSE[@]}" exec -T hoc-api php artisan view:cache || true
"${COMPOSE[@]}" exec -T hoc-api php artisan seo:submit-sitemap || true

echo "Deploy finished. API: https://api.hoc.agency/up | Site: https://hoc.agency"
