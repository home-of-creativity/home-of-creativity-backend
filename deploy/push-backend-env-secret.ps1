#Requires -Version 5.1
# Stores backend/.env as GitHub secret BACKEND_ENV_FILE_B64.
# Does not print the file or the secret.
param(
  [string] $Repo = "home-of-creativity/home-of-creativity-backend"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$EnvPath = Join-Path $Root ".env"

if (-not (Test-Path $EnvPath)) {
  throw "Missing $EnvPath"
}

$bytes = [IO.File]::ReadAllBytes($EnvPath)
if ($bytes.Length -lt 40) {
  throw ".env looks empty."
}

$b64 = [Convert]::ToBase64String($bytes)
$b64 | gh secret set BACKEND_ENV_FILE_B64 --repo $Repo
Write-Host "Saved BACKEND_ENV_FILE_B64. The next Deploy (SSH) writes it to the server .env."
