param(
  # Passthrough: todos los args se pasan al meta-runner test/runTest.php
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

. "$PSScriptRoot\..\_lib.ps1"

# =============================================================================
# test/scripts/win/docker/test.ps1
#
# Ejecuta el meta-runner dentro del contenedor TestKit:
#   test/runTest.php <target>
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

$Testkit = Resolve-Testkit -TestRoot $TestRoot -RepoRoot $RepoRoot
Assert-Testkit-Windows $Testkit

Set-Location $TestRoot

& $Testkit run --rm testkit php runTest.php @Args
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
