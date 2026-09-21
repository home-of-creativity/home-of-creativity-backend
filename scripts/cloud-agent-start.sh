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

# Reassemble backend/.env from Cursor Secrets (4096-char limit per value).
# Prefer one gzip secret; else single b64; else BACKEND_ENV_FILE_B64_PART1, _PART2, …
write_env_from_secrets() {
  local dest=".env" gz="${BACKEND_ENV_FILE_GZ_B64:-}" single="${BACKEND_ENV_FILE_B64:-}"

  if [ -n "$gz" ]; then
    mkdir -p "$(dirname "$dest")"
    local tmp
    tmp="$(mktemp)"
    if printf '%s' "$gz" | base64 -d 2>/dev/null | gzip -dc > "$tmp" 2>/dev/null && [ -s "$tmp" ]; then
      install -m 600 "$tmp" "$dest"
      echo "==> Wrote ${dest} from BACKEND_ENV_FILE_GZ_B64"
      rm -f "$tmp"
      return 0
    fi
    rm -f "$tmp"
    echo "==> WARNING: BACKEND_ENV_FILE_GZ_B64 is invalid; skipped ${dest}" >&2
    return 0
  fi

  if [ -n "$single" ]; then
    write_secret_file "BACKEND_ENV_FILE_B64" "$dest"
    return 0
  fi

  local part=1 combined=""
  while true; do
    local var="BACKEND_ENV_FILE_B64_PART${part}"
    local value="${!var:-}"
    [ -z "$value" ] && break
    combined="${combined}${value}"
    part=$((part + 1))
  done

  if [ -z "$combined" ]; then
    echo "==> No BACKEND_ENV_FILE_* secrets set; skipping ${dest}"
    return 0
  fi

  mkdir -p "$(dirname "$dest")"
  local tmp
  tmp="$(mktemp)"
  if printf '%s' "$combined" | base64 -d 2>/dev/null | tr -d '\r' > "$tmp" && [ -s "$tmp" ]; then
    install -m 600 "$tmp" "$dest"
    echo "==> Wrote ${dest} from BACKEND_ENV_FILE_B64_PART1..$((part - 1))"
  else
    echo "==> WARNING: BACKEND_ENV_FILE_B64_PART* is not valid base64; skipped ${dest}" >&2
  fi
  rm -f "$tmp"
}

write_env_from_secrets

# Google service-account JSON used by Drive/Calendar integrations.
write_secret_file "GOOGLE_SERVICE_ACCOUNT_JSON_B64" "storage/app/private/google-sa.json"

echo "==> Cloud Agent start hook complete"
