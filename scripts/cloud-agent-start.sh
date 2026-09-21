#!/usr/bin/env bash
# Per-boot Cloud Agent start hook.
# Materializes base64-encoded file secrets (provided via the Cursor Secrets
# panel as environment variables) into the paths the app expects. This runs on
# every container start — not during the build/install snapshot — so secrets are
# never baked into a shared image. Safe to run repeatedly and when secrets are
# absent (local dev simply skips them).
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

# Decode a base64 environment secret into a private file (mode 0600).
# Mirrors deploy/.github: `base64 -d | tr -d '\r'`. A malformed secret only
# warns instead of aborting the boot, so the API/dashboard terminals still come
# up.
write_secret_file() {
  local var_name="$1" dest="$2" value="${!1:-}"

  if [ -z "$value" ]; then
    echo "==> ${var_name} not set; skipping ${dest}"
    return 0
  fi

  mkdir -p "$(dirname "$dest")"
  local tmp
  tmp="$(mktemp)"
  if printf '%s' "$value" | base64 -d 2>/dev/null | tr -d '\r' > "$tmp" && [ -s "$tmp" ]; then
    install -m 600 "$tmp" "$dest"
    echo "==> Wrote ${dest} from ${var_name}"
  else
    echo "==> WARNING: ${var_name} is not valid base64; skipped ${dest}" >&2
  fi
  rm -f "$tmp"
}

# Google service-account JSON used by Drive/Calendar integrations.
write_secret_file "GOOGLE_SERVICE_ACCOUNT_JSON_B64" "storage/app/private/google-sa.json"

echo "==> Cloud Agent start hook complete"
