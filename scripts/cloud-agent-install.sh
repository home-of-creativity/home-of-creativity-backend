#!/usr/bin/env bash
# Idempotent Cloud Agent bootstrap for the Home of Creativity backend.
# Prepares the Laravel API, staff dashboard SPA, and Telegram bot tooling.
# System packages (php, composer, node, python) come from the base image/snapshot.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist

echo "==> Preparing .env (local SQLite dev config)"
if [ ! -f .env ]; then
  cp .env.example .env
  sed -i 's/^DB_CONNECTION=mysql/DB_CONNECTION=sqlite/' .env
  sed -i "s|^DB_DATABASE=home_of_creativity|DB_DATABASE=${repo_root}/database/database.sqlite|" .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate
fi

echo "==> Migrating and seeding SQLite database"
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force

echo "==> Installing root Node dependencies"
npm ci

echo "==> Installing dashboard Node dependencies"
(cd dashboard && npm ci)

echo "==> Preparing Telegram bot Python environment"
python3 -m venv bot/.venv
bot/.venv/bin/pip install --quiet --upgrade pip
bot/.venv/bin/pip install --quiet -r bot/requirements.txt

echo "==> Cloud Agent setup complete"
