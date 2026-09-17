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

# Caddyfile is a single-file bind-mount. rsync replaces files via temp+rename
# (a new inode), which can detach that bind mount from ever seeing updates —
# so `caddy reload` alone can keep reading a stale cached file forever. Force
# a full recreate of hoc-edge every deploy so it re-mounts the current file.
echo "Recreating edge (Caddy) so it picks up the current Caddyfile..."
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml up -d --force-recreate hoc-edge

# hoc-dashboard's command runs `npm install && npm run build` once at
# container startup, then serves that dist/ forever. `up -d` won't recreate
# it just because the synced dashboard source changed, so every deploy would
# keep serving a stale build. Force a recreate so it rebuilds from the code
# that was just rsynced.
echo "Rebuilding dashboard (this runs npm install + build, can take a bit)..."
docker compose --env-file "$ROOT/.env" -f deploy/compose.yaml up -d --force-recreate hoc-dashboard

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
