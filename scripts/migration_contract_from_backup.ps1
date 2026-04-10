param(
    [Parameter(Mandatory=$true)][ValidateSet('report','metadata')][string]$Kind,
    [Parameter(Mandatory=$true)][string]$SourcePath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot

function Find-Testkit {
    if ($env:TESTKIT_BIN -and (Test-Path $env:TESTKIT_BIN)) { return $env:TESTKIT_BIN }
    $local = Join-Path $repoRoot 'bin\testkit.ps1'
    if (Test-Path $local) { return $local }
    throw 'No se encontró bin/testkit.ps1. Definí TESTKIT_BIN o instalá TestKit.'
}

$tk = Find-Testkit
$envArgs = @(
    '-e', 'TEST_BASELINE_MODE=snapshot',
    '-e', 'TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS=1'
)

switch ($Kind) {
    'report'   { $envArgs += @('-e', "TEST_BASELINE_BACKUPKIT_REPORT_JSON=$SourcePath") }
    'metadata' { $envArgs += @('-e', "TEST_BASELINE_BACKUPKIT_METADATA_JSON=$SourcePath") }
}

& $tk run --rm @envArgs testkit php /workspace/testkit/runTest.php migration-contract
