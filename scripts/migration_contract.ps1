# =============================================================================
# /testkit/scripts/migration_contract.ps1
# Ejecuta el target migration-contract dentro del contenedor TestKit.
# =============================================================================

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

function Find-TestKit {
    if ($env:TESTKIT_BIN -and (Test-Path $env:TESTKIT_BIN)) { return $env:TESTKIT_BIN }
    if (Test-Path '.\bin\testkit.ps1') { return '.\bin\testkit.ps1' }
    if (Test-Path '..\bin\testkit.ps1') { return '..\bin\testkit.ps1' }
    throw 'No se encontró bin/testkit.ps1. Seteá TESTKIT_BIN o instalá TestKit.'
}

$tk = Find-TestKit
& $tk run --rm testkit php /workspace/testkit/runTest.php migration-contract
