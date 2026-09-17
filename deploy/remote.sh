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
"${COMPOSE[@]}" stop hoc-dashboard >/dev/null 2>&1 || true
"${COMPOSE[@]}" rm -f hoc-dashboard >/dev/null 2>&1 || true

echo "Building dashboard dist for Caddy..."
"${COMPOSE[@]}" --profile tools run --rm --no-deps hoc-dashboard
if [[ ! -f "$ROOT/dashboard/dist/index.html" ]] || ! grep -q '/dashboard/assets/' "$ROOT/dashboard/dist/index.html"; then
  echo "Dashboard build is missing /dashboard/ asset prefix."
  exit 1
fi

echo "Validating Caddyfile before recreating edge..."
docker run --rm -v "$ROOT/deploy/Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2-alpine \
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

# Caddyfile is a single-file bind-mount. rsync replaces files via temp+rename
# (a new inode), which can detach that bind mount from ever seeing updates.
# Recreate hoc-edge after dist exists so /dashboard is static files, not a 502 proxy.
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
