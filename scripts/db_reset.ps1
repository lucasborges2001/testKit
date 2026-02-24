# File: test/scripts/db_reset.ps1
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Testkit = Join-Path $TestRoot "bin/testkit.ps1"

Set-Location $TestRoot

& $Testkit down -v
# si falla, no cortamos
if ($LASTEXITCODE -ne 0) { Write-Host "[WARN] down -v devolvió $LASTEXITCODE (continuo)" }

& $Testkit up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
