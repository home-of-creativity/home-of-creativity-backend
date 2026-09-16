#!/usr/bin/env bash
# First login on a fresh Ubuntu/Debian VPS (use the password once).
# After this, GitHub Actions deploys with the SSH key only.
set -euo pipefail

BACKEND_PATH="${BACKEND_PATH:-/var/www/landing/backend}"
DESIGN_PATH="${DESIGN_PATH:-/var/www/landing/design}"
DEPLOY_USER="${SUDO_USER:-$USER}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash deploy/bootstrap.sh"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl git rsync

if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi

usermod -aG docker "$DEPLOY_USER" || true

mkdir -p "$BACKEND_PATH" "$DESIGN_PATH"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$BACKEND_PATH" "$DESIGN_PATH"

if [[ ! -f "$BACKEND_PATH/.env" ]]; then
  echo "Create $BACKEND_PATH/.env from .env.example after the first rsync (APP_KEY, DB_PASSWORD, APP_URL, CORS_ALLOWED_ORIGINS)."
fi

echo "Bootstrap done. Add the GitHub deploy public key to ~${DEPLOY_USER}/.ssh/authorized_keys, then run the Deploy workflow."
