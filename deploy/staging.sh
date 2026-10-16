#!/usr/bin/env bash
# Testers' copy (staging) on the VPS. Never touches the production code, .env or database,
# except to read the catalog tables and the whitelisted settings below.
#
#   bash deploy/staging.sh init                 first time: build the staging .env
#   bash deploy/staging.sh deploy               start or update the stack, migrate, build the dashboard
#   bash deploy/staging.sh seed                 copy the catalog from production, add made-up clients
#   bash deploy/staging.sh tester EMAIL [--name=NAME] [--generate] [--remove]
#   bash deploy/staging.sh set KEY              type a staging-only value (hidden), e.g. ODOO_API_KEY
#   bash deploy/staging.sh copy-prod KEY        reuse a shared production credential, e.g. CLICKUP_TOKEN
#   bash deploy/staging.sh check                separation checks and which integrations are set
#   bash deploy/staging.sh status | logs [service] | down
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGING_ROOT=/var/www/staging/backend
PROD_ROOT=/var/www/landing/backend
PROD_ENV="$PROD_ROOT/.env"
ENV_FILE="$ROOT/.env"
PROD_MYSQL=hoc-prod-mysql-1
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$ROOT/deploy/staging/compose.yaml")

die() { echo "ERROR: $*" >&2; exit 1; }

[[ "$ROOT" == "$STAGING_ROOT" ]] || die "staging.sh runs only from $STAGING_ROOT (here: $ROOT)."
[[ "$ROOT" != "$PROD_ROOT" ]] || die "Refusing to run inside the production folder."

# Reads KEY's raw value from an env file. Used only inside this script, never printed.
env_get() {
  local file="$1" key="$2"
  [[ -f "$file" ]] || return 0
  grep -E "^${key}=" "$file" | tail -n 1 | cut -d= -f2- || true
}

env_unquote() {
  local v="$1"
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
  printf '%s' "$v"
}

env_set() {
  local file="$1" key="$2" value="$3" tmp
  tmp="$(mktemp)"
  if [[ -f "$file" ]]; then
    grep -v -E "^${key}=" "$file" > "$tmp" || true
  fi
  printf '%s=%s\n' "$key" "$value" >> "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
  chmod 600 "$file"
}

random_secret() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${1:-40}" || true; }

# Keys that must never carry the production value into staging.
SEPARATE_KEYS=(
  APP_KEY DB_PASSWORD DB_DATABASE DB_USERNAME
  TELEGRAM_BOT_TOKEN TELEGRAM_STAFF_BOT_TOKEN TELEGRAM_ADMIN_BOT_TOKEN TELEGRAM_DEV_BOT_TOKEN
  TELEGRAM_BOT_SECRET TELEGRAM_STAFF_BOT_SECRET TELEGRAM_ADMIN_BOT_SECRET TELEGRAM_DEV_BOT_SECRET
  TELEGRAM_STAFF_CHAT_ID TELEGRAM_DEV_CHAT_ID
  WHATSAPP_TOKEN WHATSAPP_PHONE_NUMBER_ID WHATSAPP_WEB_SECRET
  ODOO_DB GOOGLE_DRIVE_PARENT_FOLDER_ID GOOGLE_CALENDAR_ID GOOGLE_DRIVE_WATCH_TOKEN
  CLICKUP_SPACE_ID CLICKUP_LIST_ID CLICKUP_LIST_SALES CLICKUP_LIST_PHOTOGRAPHY CLICKUP_LIST_CONTENT
  CLICKUP_LIST_DESIGN CLICKUP_LIST_PROGRAMMING CLICKUP_LIST_REVISION
  COMPLAINT_IMAP_USERNAME COMPLAINT_IMAP_PASSWORD FACEBOOK_ACCESS_TOKEN THREADS_ACCESS_TOKEN
  N8N_WEBHOOK_URL N8N_DRIVE_WEBHOOK_URL SENTRY_LARAVEL_DSN SENTRY_DSN
)

# Non-secret settings and read-only services copied from production at init.
COPY_AT_INIT=(
  APP_NAME APP_LOCALE APP_FALLBACK_LOCALE APP_FAKER_LOCALE APP_MAINTENANCE_DRIVER BCRYPT_ROUNDS
  LOG_CHANNEL LOG_STACK LOG_DEPRECATIONS_CHANNEL SESSION_LIFETIME SESSION_ENCRYPT SESSION_PATH
  BROADCAST_CONNECTION FILESYSTEM_DISK DB_QUEUE_RETRY_AFTER FFMPEG_BINARY LIBREOFFICE_BINARY
  APP_ACCESS_TTL_MINUTES APP_REFRESH_TTL_DAYS AUTH_THROTTLE_PER_MINUTE TELEGRAM_STRICT
  BOT_SLA_QUOTE_HOURS BOT_SLA_RECEIPT_HOURS BOT_SLA_RECEIPT_SALES_HOURS BOT_SLA_RECEIPT_UNCONFIRMED_HOURS
  BOT_SLA_REVISION_HOURS BOT_SLA_SUPPORT_HOURS BOT_SLA_PROFILE_HOURS BOT_SLA_DIGEST_HOUR
  ODOO_USE_JSON2 ODOO_TIMEOUT ODOO_CURRENCY_CODE
  CLICKUP_TIMEOUT CLICKUP_STATUS_PROGRESS CLICKUP_STATUS_REVIEW CLICKUP_STATUS_COMPLETE CLICKUP_STATUS_CANCELLED
  GEMINI_VERTEX_PROJECT GEMINI_VERTEX_LOCATION GEMINI_API_KEY GOOGLE_API_KEY GEMINI_MODEL GEMINI_IMAGE_MODEL
  GEMINI_TIMEOUT GOOGLE_TRANSLATE_ENABLED GOOGLE_TRANSLATE_API_KEY ELEVENLABS_API_KEY ELEVENLABS_TIMEOUT
  SOCIAL_TIMEZONE MAIL_FROM_NAME
)

# Production values that copy-prod refuses: they point at live chats, inboxes, pages or records.
COPY_PROD_DENY='^(APP_|DB_|STAGING_|TELEGRAM_|WHATSAPP_|ODOO_(ENABLED|URL|DB|USERNAME|API_KEY)$|COMPLAINT_IMAP_|FACEBOOK_|THREADS_|LINKEDIN_|INSTAGRAM_|SENTRY_|N8N_|DEV_|GOOGLE_DRIVE_PARENT_FOLDER_ID$|GOOGLE_CALENDAR_ID$|GOOGLE_DRIVE_WATCH_TOKEN$|GOOGLE_SEARCH_|INDEXNOW_|CLICKUP_LIST_|CLICKUP_SPACE_ID$|CONTACT_MAIL_(INFO|SALES|SUPPORT)$|SOCIAL_LANDING_)'

# Values a person types for staging only. Database, key and environment stay managed here.
SET_DENY='^(APP_ENV|APP_KEY|APP_URL|DB_[A-Z_]+|STAGING_[A-Z_]+)$'

copy_prod_file_if_path() {
  local raw="$1" rel src dst
  raw="$(env_unquote "$raw")"
  case "$raw" in
    /var/www/hoc/*) rel="${raw#/var/www/hoc/}" ;;
    storage/*|./storage/*) rel="${raw#./}" ;;
    *) return 0 ;;
  esac
  src="$PROD_ROOT/$rel"
  dst="$ROOT/$rel"
  if [[ -f "$src" ]]; then
    mkdir -p "$(dirname "$dst")"
    install -m 600 "$src" "$dst"
    echo "  copied the file it points to: $rel"
  fi
}

cmd_init() {
  [[ -f "$PROD_ENV" ]] || die "Production .env not found at $PROD_ENV."
  if [[ -f "$ENV_FILE" ]]; then
    echo "Staging .env already exists. Nothing changed. Use 'set' or 'copy-prod' to edit it."
    return 0
  fi
  install -m 600 /dev/null "$ENV_FILE"
  local key value
  for key in "${COPY_AT_INIT[@]}"; do
    value="$(env_get "$PROD_ENV" "$key")"
    [[ -n "$value" ]] && env_set "$ENV_FILE" "$key" "$value"
  done
  for key in GEMINI_VERTEX_CREDENTIALS GOOGLE_APPLICATION_CREDENTIALS; do
    value="$(env_get "$PROD_ENV" "$key")"
    if [[ -n "$value" ]]; then
      env_set "$ENV_FILE" "$key" "$value"
      copy_prod_file_if_path "$value"
    fi
  done

  env_set "$ENV_FILE" APP_ENV staging
  env_set "$ENV_FILE" APP_DEBUG false
  env_set "$ENV_FILE" APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
  env_set "$ENV_FILE" APP_URL https://staging.hoc.agency
  env_set "$ENV_FILE" CORS_ALLOWED_ORIGINS https://staging.hoc.agency
  env_set "$ENV_FILE" LOG_LEVEL info
  env_set "$ENV_FILE" SESSION_DRIVER database
  env_set "$ENV_FILE" CACHE_STORE database
  env_set "$ENV_FILE" QUEUE_CONNECTION sync
  env_set "$ENV_FILE" DB_CONNECTION mysql
  env_set "$ENV_FILE" DB_HOST mysql
  env_set "$ENV_FILE" DB_PORT 3306
  env_set "$ENV_FILE" DB_DATABASE hoc_testing
  env_set "$ENV_FILE" DB_USERNAME hoc_testing
  env_set "$ENV_FILE" DB_PASSWORD "$(random_secret 40)"
  env_set "$ENV_FILE" STAGING_DB_ROOT_PASSWORD "$(random_secret 40)"
  env_set "$ENV_FILE" MAIL_MAILER log
  env_set "$ENV_FILE" MAIL_FROM_ADDRESS staging@hoc.agency
  env_set "$ENV_FILE" HOC_API_URL http://edge/api
  env_set "$ENV_FILE" TELEGRAM_WEBHOOK_URL ""
  for key in TELEGRAM_BOT_SECRET TELEGRAM_STAFF_BOT_SECRET TELEGRAM_ADMIN_BOT_SECRET TELEGRAM_DEV_BOT_SECRET WHATSAPP_WEB_SECRET; do
    env_set "$ENV_FILE" "$key" "$(random_secret 40)"
  done
  env_set "$ENV_FILE" WHATSAPP_ENABLED false
  env_set "$ENV_FILE" WHATSAPP_TRANSPORT web
  env_set "$ENV_FILE" WHATSAPP_WEB_URL http://whatsapp-web:8090
  env_set "$ENV_FILE" ODOO_ENABLED false
  chmod 600 "$ENV_FILE"
  echo "Staging .env created at $ENV_FILE (mode 600). Integrations are empty until you set them."
}

cmd_check() {
  [[ -f "$ENV_FILE" ]] || die "No staging .env. Run: bash deploy/staging.sh init"
  local failed=0 key s p
  [[ "$(env_get "$ENV_FILE" APP_ENV)" == "staging" ]] || { echo "FAIL APP_ENV is not staging"; failed=1; }
  [[ "$(env_get "$ENV_FILE" DB_DATABASE)" == "hoc_testing" ]] || { echo "FAIL DB_DATABASE is not hoc_testing"; failed=1; }
  [[ "$(env_get "$ENV_FILE" DB_USERNAME)" == "hoc_testing" ]] || { echo "FAIL DB_USERNAME is not hoc_testing"; failed=1; }
  [[ "$(env_get "$ENV_FILE" DB_HOST)" == "mysql" ]] || { echo "FAIL DB_HOST is not the staging mysql"; failed=1; }
  for key in "${SEPARATE_KEYS[@]}"; do
    s="$(env_unquote "$(env_get "$ENV_FILE" "$key")")"
    p="$(env_unquote "$(env_get "$PROD_ENV" "$key")")"
    if [[ -n "$s" && "$s" == "$p" ]]; then
      echo "FAIL $key has the production value"
      failed=1
    fi
  done
  s="$(env_unquote "$(env_get "$ENV_FILE" ODOO_URL)")|$(env_unquote "$(env_get "$ENV_FILE" ODOO_DB)")"
  p="$(env_unquote "$(env_get "$PROD_ENV" ODOO_URL)")|$(env_unquote "$(env_get "$PROD_ENV" ODOO_DB)")"
  if [[ "$s" != "|" && "$s" == "$p" ]]; then
    echo "FAIL Odoo points at the production database"
    failed=1
  fi
  ((failed == 0)) || die "Separation check failed. Nothing was started."
  echo "Separation check passed: own database, own keys, no production chat, inbox or Odoo database."

  echo "Integrations (set / empty):"
  for key in ODOO_ENABLED ODOO_URL ODOO_DB ODOO_API_KEY TELEGRAM_BOT_TOKEN TELEGRAM_STAFF_BOT_TOKEN TELEGRAM_ADMIN_BOT_TOKEN \
    TELEGRAM_STAFF_CHAT_ID CLICKUP_TOKEN CLICKUP_LIST_ID GOOGLE_SERVICE_ACCOUNT_JSON GOOGLE_DRIVE_PARENT_FOLDER_ID \
    GOOGLE_CALENDAR_ID MAIL_MAILER GEMINI_API_KEY GEMINI_VERTEX_CREDENTIALS; do
    s="$(env_unquote "$(env_get "$ENV_FILE" "$key")")"
    case "$key" in
      ODOO_ENABLED|MAIL_MAILER) printf '  %-32s %s\n' "$key" "${s:-empty}" ;;
      *) printf '  %-32s %s\n' "$key" "$([[ -n "$s" ]] && echo set || echo empty)" ;;
    esac
  done
}

bot_profiles() {
  local profiles=()
  [[ -n "$(env_unquote "$(env_get "$ENV_FILE" TELEGRAM_BOT_TOKEN)")" ]] && profiles+=(--profile telegram-client)
  [[ -n "$(env_unquote "$(env_get "$ENV_FILE" TELEGRAM_STAFF_BOT_TOKEN)")" ]] && profiles+=(--profile telegram-staff)
  [[ -n "$(env_unquote "$(env_get "$ENV_FILE" TELEGRAM_ADMIN_BOT_TOKEN)")" ]] && profiles+=(--profile telegram-admin)
  if ((${#profiles[@]} > 0)); then
    printf '%s\n' "${profiles[@]}"
  fi
}

bot_services() {
  local p
  for p in "$@"; do
    [[ "$p" == telegram-* ]] && printf '%s-bot\n' "${p#telegram-}"
  done
  return 0
}

cmd_deploy() {
  cmd_check
  cd "$ROOT"
  mkdir -p storage/app/private storage/app/public storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
  chmod -R ug+rwx storage bootstrap/cache || true
  rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php

  mapfile -t profiles < <(bot_profiles)
  "${COMPOSE[@]}" "${profiles[@]}" up -d --build --remove-orphans

  echo "Building the staging dashboard..."
  "${COMPOSE[@]}" --profile tools run --rm --no-deps dashboard

  echo "Waiting for the staging API..."
  for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T api php artisan --version >/dev/null 2>&1; then break; fi
    sleep 5
  done
  "${COMPOSE[@]}" exec -T api composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
  "${COMPOSE[@]}" exec -T api php artisan migrate --force
  "${COMPOSE[@]}" exec -T api php artisan config:cache
  "${COMPOSE[@]}" exec -T api php artisan route:cache
  "${COMPOSE[@]}" exec -T api sh -c 'ps -e -o pid,comm | awk '\''$2=="php-fpm"{print $1; exit}'\'' | xargs -r kill -USR2' || true
  mapfile -t bots < <(bot_services "${profiles[@]}")
  "${COMPOSE[@]}" "${profiles[@]}" restart scheduler whatsapp-web "${bots[@]}" || true
  echo "Staging is up: https://staging.hoc.agency/dashboard/ (needs DNS and the production Caddy block)."
}

cmd_seed() {
  cmd_check
  local tables="pricing_categories pricing_subcategories pricing_packages contact_channels legal_pages roles report_templates"
  local settings="'team_work_open','team_work_close','whatsapp_bot_open','whatsapp_bot_close','work_holidays','work_hours_per_day','photography_lead_days','client_telegram_enabled','client_whatsapp_enabled'"
  local dump
  dump="$(mktemp)"
  trap 'rm -f "$dump"' RETURN

  echo "Reading the catalog from production (read only)..."
  docker exec "$PROD_MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot "$@"' sh \
    --single-transaction --no-create-info --skip-triggers --complete-insert --set-gtid-purged=OFF \
    --no-tablespaces --default-character-set=utf8mb4 hoc_platform $tables > "$dump"
  docker exec "$PROD_MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot "$@"' sh \
    --single-transaction --no-create-info --skip-triggers --complete-insert --set-gtid-purged=OFF \
    --no-tablespaces --default-character-set=utf8mb4 --where="\`key\` IN ($settings)" hoc_platform ops_settings >> "$dump"

  echo "Writing it into hoc_testing..."
  {
    echo "SET FOREIGN_KEY_CHECKS=0;"
    for t in $tables; do echo "DELETE FROM \`$t\`;"; done
    echo "DELETE FROM ops_settings WHERE \`key\` IN ($settings);"
    cat "$dump"
    echo "SET FOREIGN_KEY_CHECKS=1;"
  } | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 hoc_testing'

  if [[ -d "$PROD_ROOT/storage/app/private/report-templates" ]]; then
    mkdir -p "$ROOT/storage/app/private/report-templates"
    cp -a "$PROD_ROOT/storage/app/private/report-templates/." "$ROOT/storage/app/private/report-templates/"
  fi

  "${COMPOSE[@]}" exec -T api php artisan db:seed --class='Database\Seeders\StagingSeeder' --force
  echo "Catalog copied and made-up clients added."
}

cmd_tester() {
  [[ $# -ge 1 ]] || die "Usage: bash deploy/staging.sh tester EMAIL [--name=NAME] [--generate] [--remove]"
  local tty=(-T)
  [[ -t 0 && -t 1 ]] && tty=()
  "${COMPOSE[@]}" exec "${tty[@]}" api php artisan staging:tester "$@"
}

recreate_with_env() {
  mapfile -t profiles < <(bot_profiles)
  mapfile -t bots < <(bot_services "${profiles[@]}")
  "${COMPOSE[@]}" "${profiles[@]}" up -d --force-recreate api scheduler whatsapp-web "${bots[@]}"
  for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T api php artisan --version >/dev/null 2>&1; then break; fi
    sleep 5
  done
  "${COMPOSE[@]}" exec -T api php artisan config:cache
  # Edge sits on two networks. After the API container is recreated, its DNS
  # lookup of "api" returns SERVFAIL until this proxy is restarted.
  "${COMPOSE[@]}" restart edge
}

cmd_set() {
  local key="${1:-}" value again
  [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die "Usage: bash deploy/staging.sh set KEY"
  [[ ! "$key" =~ $SET_DENY ]] || die "$key is managed by this script."
  [[ -t 0 ]] || die "Run it in a terminal (ssh -t) so the value is typed, not passed on the command line."
  read -r -s -p "Value for $key (hidden): " value; echo
  read -r -s -p "Again: " again; echo
  [[ "$value" == "$again" ]] || die "The two values differ. Nothing changed."
  save_checked "$key" "$value"
  recreate_with_env
  echo "$key saved for staging."
}

# Writes KEY, then puts the old line back if the separation check fails.
save_checked() {
  local key="$1" value="$2" backup
  backup="$(mktemp)"
  cp -p "$ENV_FILE" "$backup"
  env_set "$ENV_FILE" "$key" "$value"
  if ! (cmd_check); then
    cat "$backup" > "$ENV_FILE"
    rm -f "$backup"
    die "$key would break the separation (see above). The old value was kept."
  fi
  rm -f "$backup"
}

cmd_copy_prod() {
  local key="${1:-}" value
  [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die "Usage: bash deploy/staging.sh copy-prod KEY"
  [[ ! "$key" =~ $COPY_PROD_DENY ]] || die "$key points at live chats, inboxes or records. Use 'set' with a test value."
  value="$(env_get "$PROD_ENV" "$key")"
  [[ -n "$value" ]] || die "$key is empty in production."
  save_checked "$key" "$value"
  copy_prod_file_if_path "$value"
  recreate_with_env
  echo "$key copied from production to staging."
}

case "${1:-}" in
  init) cmd_init ;;
  check) cmd_check ;;
  deploy) cmd_deploy ;;
  seed) cmd_seed ;;
  tester) shift; cmd_tester "$@" ;;
  set) shift; cmd_set "$@" ;;
  copy-prod) shift; cmd_copy_prod "$@" ;;
  status) "${COMPOSE[@]}" ps ;;
  logs) shift; "${COMPOSE[@]}" logs --tail=100 "$@" ;;
  down) "${COMPOSE[@]}" --profile telegram-client --profile telegram-staff --profile telegram-admin down ;;
  *) sed -n '2,13p' "$0"; exit 1 ;;
esac
