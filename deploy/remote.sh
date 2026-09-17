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

docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml up -d --build

# Caddyfile is a read-only bind-mount, so `up -d` never restarts hoc-edge just
# because its contents changed. Force a reload every deploy so routing edits
# (e.g. /dashboard, /staff redirects) actually take effect.
echo "Reloading edge (Caddy) config..."
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-edge \
  caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile \
  || docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml restart hoc-edge

echo "Waiting for API php-fpm..."
for _ in $(seq 1 60); do
  if docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan --version >/dev/null 2>&1; then
    break
  fi
  sleep 5
done

# hoc-api entrypoint already runs migrate --force and storage:link on start.
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan storage:link || true

echo "Running database seeders..."
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan db:seed --force

docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan config:cache
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan route:cache
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan view:cache || true
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml exec -T hoc-api php artisan seo:submit-sitemap || true

echo "Deploy finished. API: https://api.hoc.agency/up | Site: https://hoc.agency"
