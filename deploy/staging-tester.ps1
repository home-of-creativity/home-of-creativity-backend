# Adds a tester login (full admin) to the staging dashboard only. The password is typed on
# the server prompt and never saved on this machine.
#
#   .\deploy\staging-tester.ps1 -Email qa@example.com -Name "QA"
#   .\deploy\staging-tester.ps1 -Email qa@example.com -Generate     random password, printed once
#   .\deploy\staging-tester.ps1 -Email qa@example.com -Remove
param(
    [Parameter(Mandatory = $true)][string]$Email,
    [string]$Name = "",
    [switch]$Generate,
    [switch]$Remove,
    [string]$SshHost = "hoc"
)

$ErrorActionPreference = "Stop"
if ($Email -notmatch '^[^@\s''"]+@[^@\s''"]+\.[^@\s''"]+$') { throw "Not a valid email: $Email" }
if ($Name -match "['""``$\\]") { throw "Name cannot contain quotes, backticks, `$ or \." }

$parts = @("tester", $Email)
if ($Name) { $parts += "--name='$Name'" }
if ($Generate) { $parts += "--generate" }
if ($Remove) { $parts += "--remove" }

ssh -t $SshHost ("bash /var/www/staging/backend/deploy/staging.sh " + ($parts -join " "))
