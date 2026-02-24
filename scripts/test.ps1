Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Testkit = Join-Path $TestRoot "bin/testkit.ps1"

Set-Location $TestRoot

# Passthrough args: back|front|front-php|front-js
$target = $args[0]

if ($target) {
  & $Testkit run --rm testkit php runTest.php $target
} else {
  & $Testkit run --rm testkit php runTest.php
}

if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
