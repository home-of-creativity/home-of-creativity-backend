#Requires -Version 5.1
param(
  [Parameter(Mandatory = $true)]
  [string] $HostName,

  [Parameter(Mandatory = $true)]
  [string] $User,

  [string] $IdentityFile = (Join-Path $env:USERPROFILE ".ssh\id_ed25519_hoc_deploy"),

  [string] $RemotePath = "/var/www/landing/backend",

  [int] $Port = 22
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

if (-not (Test-Path $IdentityFile)) {
  throw "SSH key missing: $IdentityFile — generate it with ssh-keygen (see README Deploy)."
}

$ssh = @(
  "-i", $IdentityFile,
  "-p", "$Port",
  "-o", "IdentitiesOnly=yes",
  "-o", "StrictHostKeyChecking=accept-new",
  "${User}@${HostName}"
)

Write-Host "Syncing $Root -> ${User}@${HostName}:${RemotePath}"

ssh @ssh "mkdir -p '$RemotePath'"

$excludes = @(
  "--exclude=.git",
  "--exclude=.env",
  "--exclude=.env.*",
  "--exclude=vendor",
  "--exclude=node_modules",
  "--exclude=dashboard/node_modules",
  "--exclude=storage/logs",
  "--exclude=storage/framework/cache",
  "--exclude=playwright-report",
  "--exclude=test-results",
  "--exclude=bot/.env"
)

if (Get-Command rsync -ErrorAction SilentlyContinue) {
  & rsync -az --delete @excludes -e "ssh -i `"$IdentityFile`" -p $Port -o IdentitiesOnly=yes" "$Root/" "${User}@${HostName}:${RemotePath}/"
} else {
  $tarExcludes = @(
    "--exclude=.git",
    "--exclude=.env",
    "--exclude=vendor",
    "--exclude=node_modules",
    "--exclude=dashboard/node_modules",
    "--exclude=storage/logs",
    "--exclude=playwright-report",
    "--exclude=test-results"
  )
  Push-Location $Root
  try {
    tar -cf - @tarExcludes . | ssh @ssh "mkdir -p '$RemotePath' && tar -xf - -C '$RemotePath'"
  } finally {
    Pop-Location
  }
}

$saLocal = Join-Path $Root "storage\app\private\google-sa.json"
if (Test-Path $saLocal) {
  ssh @ssh "mkdir -p '$RemotePath/storage/app/private'"
  scp -i $IdentityFile -P $Port -o IdentitiesOnly=yes $saLocal "${User}@${HostName}:${RemotePath}/storage/app/private/google-sa.json"
  ssh @ssh "chmod 600 '$RemotePath/storage/app/private/google-sa.json'"
}

ssh @ssh "bash '$RemotePath/deploy/remote.sh'"
Write-Host "Done."
