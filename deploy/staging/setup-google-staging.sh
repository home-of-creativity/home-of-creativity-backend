#!/usr/bin/env bash
# Create staging-only Drive parent folder + Calendar, then write IDs into staging .env.
set -euo pipefail

ROOT=/var/www/staging/backend
ENV="$ROOT/.env"
COMPOSE=(docker compose --env-file "$ENV" -f "$ROOT/deploy/staging/compose.yaml" -p hoc-staging)

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

cd "$ROOT/deploy/staging"

php_create='
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$auth = app(App\Services\GoogleServiceAccount::class);
if (!$auth->configured()) {
    fwrite(STDERR, "SA_NOT_CONFIGURED: ".($auth->configurationError() ?? "")."\n");
    exit(1);
}
$token = $auth->accessToken();
if (!$token) {
    fwrite(STDERR, "TOKEN_FAIL\n");
    exit(1);
}
echo "SA_OK\n";

$q = "name = '\''HOC Clients Staging'\'' and mimeType = '\''application/vnd.google-apps.folder'\'' and trashed = false and '\''root'\'' in parents";
$list = Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
    ->get("https://www.googleapis.com/drive/v3/files", [
        "q" => $q,
        "fields" => "files(id,name)",
        "spaces" => "drive",
        "supportsAllDrives" => "true",
    ]);
if (!$list->successful()) {
    fwrite(STDERR, "DRIVE_LIST_FAIL ".$list->status()."\n");
    exit(1);
}
$files = $list->json("files") ?? [];
if ($files) {
    $folderId = $files[0]["id"];
    echo "DRIVE_FOLDER_EXISTING\n";
} else {
    $create = Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
        ->post("https://www.googleapis.com/drive/v3/files?supportsAllDrives=true", [
            "name" => "HOC Clients Staging",
            "mimeType" => "application/vnd.google-apps.folder",
        ]);
    if (!$create->successful()) {
        fwrite(STDERR, "DRIVE_CREATE_FAIL ".$create->status()." ".$create->body()."\n");
        exit(1);
    }
    $folderId = $create->json("id");
    echo "DRIVE_FOLDER_CREATED\n";
}
echo "DRIVE_FOLDER_ID=".$folderId."\n";

$calList = Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
    ->get("https://www.googleapis.com/calendar/v3/users/me/calendarList");
if (!$calList->successful()) {
    fwrite(STDERR, "CAL_LIST_FAIL ".$calList->status()." ".$calList->body()."\n");
    exit(1);
}
$calendarId = null;
foreach (($calList->json("items") ?? []) as $item) {
    if (($item["summary"] ?? "") === "HOC Staging") {
        $calendarId = $item["id"];
        echo "CALENDAR_EXISTING\n";
        break;
    }
}
if (!$calendarId) {
    $cal = Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
        ->post("https://www.googleapis.com/calendar/v3/calendars", [
            "summary" => "HOC Staging",
            "timeZone" => "Asia/Damascus",
        ]);
    if (!$cal->successful()) {
        fwrite(STDERR, "CAL_CREATE_FAIL ".$cal->status()." ".$cal->body()."\n");
        exit(1);
    }
    $calendarId = $cal->json("id");
    echo "CALENDAR_CREATED\n";
}
echo "CALENDAR_ID=".$calendarId."\n";
'

out="$("${COMPOSE[@]}" exec -T api php -r "$php_create")"
printf '%s\n' "$out"

folder="$(printf '%s\n' "$out" | sed -n 's/^DRIVE_FOLDER_ID=//p' | tail -n1)"
cal="$(printf '%s\n' "$out" | sed -n 's/^CALENDAR_ID=//p' | tail -n1)"
if [[ -z "$folder" || -z "$cal" ]]; then
  echo "MISSING_IDS" >&2
  exit 1
fi

backup="$(mktemp)"
cp -p "$ENV" "$backup"
env_set "$ENV" GOOGLE_DRIVE_PARENT_FOLDER_ID "$folder"
env_set "$ENV" GOOGLE_CALENDAR_ID "$cal"

if ! bash "$ROOT/deploy/staging.sh" check; then
  echo "CHECK_FAILED restoring" >&2
  cat "$backup" > "$ENV"
  rm -f "$backup"
  exit 2
fi
rm -f "$backup"

"${COMPOSE[@]}" up -d --force-recreate api scheduler
sleep 8
"${COMPOSE[@]}" exec -T api php artisan config:clear
"${COMPOSE[@]}" exec -T api php artisan config:cache

"${COMPOSE[@]}" exec -T api php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$drive = app(App\Services\GoogleDriveClient::class);
$cal = app(App\Services\GoogleCalendarClient::class);
echo "DRIVE_CONFIGURED=".($drive->configured() ? "yes" : "no")."\n";
echo "DRIVE_ERR=".($drive->configurationError() ?? "")."\n";
echo "CAL_CONFIGURED=".($cal->configured() ? "yes" : "no")."\n";
$list = $drive->listFolders((string) config("services.google.drive_parent_folder_id"));
echo "DRIVE_LIST=".($list === null ? "fail:".($drive->lastError() ?? "") : "ok folders=".count($list["folders"] ?? []))."\n";
$cals = $cal->calendars();
echo "CAL_LIST=".($cals === null ? "fail" : "ok count=".count($cals))."\n";
'

echo DONE
