#!/bin/bash
# Replace the server .env from stdin, but keep the database lines already on the server.
# Usage: keep-server-db-env.sh /path/to/.env < new.env
set -euo pipefail

target="${1:?path to .env}"
umask 177
incoming="$(mktemp)"
rest="$(mktemp)"
kept="$(mktemp)"
cleanup() { rm -f "$incoming" "$rest" "$kept"; }
trap cleanup EXIT

cat > "$incoming"

keys='^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_SOCKET|DB_URL|DB_CHARSET|DB_COLLATION|DB_FOREIGN_KEYS)='

if [ -f "$target" ]; then
  grep -E "$keys" "$target" > "$kept" || true
  if [ -s "$kept" ]; then
    grep -v -E "$keys" "$incoming" > "$rest" || true
    cat "$rest" "$kept" > "$incoming"
  fi
fi

if ! grep -qE '^DB_PASSWORD=.+' "$incoming"; then
  echo "Refusing to write .env: DB_PASSWORD is empty and the server has no database password to keep." >&2
  exit 1
fi

mv "$incoming" "$target"
chmod 600 "$target"
trap - EXIT
cleanup
