param(
  # Passthrough: todos los args se pasan al meta-runner test/runTest.php
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

. "$PSScriptRoot/../_lib.ps1"

# =============================================================================
# test/scripts/win/local/test.ps1
#
# Ejecuta el meta-runner en modo LOCAL:
#   <PHP_BIN> test/runTest.php <target>
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

$EnvFile = Pick-EnvFile -TestRoot $TestRoot -RepoRoot $RepoRoot
if ($EnvFile) { Load-EnvKVSafe $EnvFile }

$PhpBin = $env:PHP_BIN; if (-not $PhpBin) { $PhpBin = "C:/xampp/php/php.exe" }
if (-not (Test-Path $PhpBin)) { Fail "No existe PHP_BIN: $PhpBin" }

$MetaRunner = Join-Path $TestRoot "runTest.php"
if (-not (Test-Path $MetaRunner)) { Fail "Falta meta-runner: $MetaRunner" }

Set-Location $TestRoot
& $PhpBin $MetaRunner @Args
exit $LASTEXITCODE
