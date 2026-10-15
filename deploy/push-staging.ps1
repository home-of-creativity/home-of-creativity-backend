# Uploads this machine's backend code to the testers' copy (/var/www/staging/backend) and deploys it.
# Never uploads .env files, storage, vendor, node_modules or the built dashboard, and never
# touches /var/www/landing (production).
#
#   .\deploy\push-staging.ps1              upload and deploy
#   .\deploy\push-staging.ps1 -UploadOnly  upload only
param(
    [string]$SshHost = "hoc",
    [switch]$UploadOnly
)

$ErrorActionPreference = "Stop"
$backend = Split-Path -Parent $PSScriptRoot
$archive = Join-Path $env:TEMP ("hoc-staging-" + (Get-Date -Format "yyyyMMddHHmmss") + ".tgz")
$remote = "/var/www/staging/backend"

Write-Host "Packing $backend ..."
Push-Location $backend
try {
    # Files git tracks or would track (.gitignore keeps out .env, vendor, storage, builds).
    $files = git ls-files -co --exclude-standard |
        Where-Object { $_ -notmatch '(^|/)\.env($|\.)' -or $_ -match '\.env\.example$' } |
        Where-Object { $_ -notmatch '^(storage|vendor|tests|e2e|\.cursor|playwright-report|test-results)/|node_modules/|^dashboard/dist/|\.pem$' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }
    if (-not $files) { throw "No files to upload." }
    $list = Join-Path $env:TEMP "hoc-staging-files.txt"
    [IO.File]::WriteAllLines($list, [string[]]$files)
    tar -czf $archive -T $list
    if ($LASTEXITCODE -ne 0) { throw "tar failed" }
    Remove-Item $list
} finally {
    Pop-Location
}

$leaks = tar -tzf $archive | Where-Object { $_ -match '(^|/)\.env($|\.)' -and $_ -notmatch '\.env\.example$' }
if ($leaks) { Remove-Item $archive; throw "Refusing to upload: the archive contains an .env file." }

Write-Host "Uploading ..."
scp -q $archive "${SshHost}:/tmp/hoc-staging.tgz"
if ($LASTEXITCODE -ne 0) { throw "scp failed" }
Remove-Item $archive

$sync = @(
    "set -e",
    "rm -rf /tmp/hoc-staging-src && mkdir -p /tmp/hoc-staging-src $remote",
    "tar -xzf /tmp/hoc-staging.tgz -C /tmp/hoc-staging-src",
    "rsync -a --delete --exclude=/.env --exclude=/storage/ --exclude=/vendor/ --exclude=/dashboard/node_modules/ --exclude=/dashboard/dist/ --exclude=/bot/whatsapp-web/node_modules/ --exclude='/bootstrap/cache/*.php' --exclude=/public/storage /tmp/hoc-staging-src/ $remote/",
    "find $remote/deploy -name '*.sh' -exec sed -i 's/\r$//' {} +",
    "rm -rf /tmp/hoc-staging-src /tmp/hoc-staging.tgz",
    "echo uploaded"
) -join " && "
ssh -o BatchMode=yes $SshHost $sync
if ($LASTEXITCODE -ne 0) { throw "sync failed" }

if (-not $UploadOnly) {
    ssh -t $SshHost "bash $remote/deploy/staging.sh deploy"
}
