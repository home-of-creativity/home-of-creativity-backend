#Requires -Version 5.1
# Stores the local Google service account JSON as a GitHub Actions secret.
# Does not print the key. Deploy writes it to storage/app/private/google-sa.json.
param(
  [string] $Repo = "home-of-creativity/home-of-creativity-backend",
  [string] $JsonPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
if (-not $JsonPath) {
  $JsonPath = Join-Path $Root "storage\app\private\google-sa.json"
}

if (-not (Test-Path $JsonPath)) {
  throw "Missing service account file: $JsonPath"
}

$bytes = [IO.File]::ReadAllBytes($JsonPath)
if ($bytes.Length -lt 80) {
  throw "Service account file looks empty."
}

$b64 = [Convert]::ToBase64String($bytes)
$b64 | gh secret set GOOGLE_SERVICE_ACCOUNT_JSON_B64 --repo $Repo
Write-Host "Saved GOOGLE_SERVICE_ACCOUNT_JSON_B64. Next deploy copies it to storage/app/private/google-sa.json."
