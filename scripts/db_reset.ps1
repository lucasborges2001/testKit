Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /testkit/scripts/db_reset.ps1
# Resetea el entorno Docker de tests: down -v, up -d
# =============================================================================

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $TestRoot "..") }

function Resolve-Testkit {
    $override = $env:TESTKIT_BIN
    if ($override -and (Test-Path $override)) { return (Resolve-Path $override).Path }

    $candidates = @(
        $(Join-Path $TestRoot "bin/testkit.ps1"),
        $(Join-Path $ProjectRoot "bin/testkit.ps1"),
        $(Join-Path $TestRoot "bin/testkit"),
        $(Join-Path $ProjectRoot "bin/testkit")
    )

    foreach ($c in $candidates) {
        if (Test-Path $c) { return (Resolve-Path $c).Path }
    }

    throw "No se encontró TestKit. Seteá TESTKIT_BIN o agregá bin/testkit(.ps1)."
}

function Test-IsWindowsHost {
    $isWindowsVar = Get-Variable -Name IsWindows -ErrorAction SilentlyContinue
    if ($null -ne $isWindowsVar) {
        return [bool]$isWindowsVar.Value
    }

    return ([System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Win32NT)
}

$Testkit = Resolve-Testkit
if ((Test-IsWindowsHost) -and -not $Testkit.ToLowerInvariant().EndsWith(".ps1")) {
    throw "En Windows se espera bin/testkit.ps1. Si solo tenés bin/testkit (bash), usá los .sh."
}

Set-Location $TestRoot

& $Testkit down -v
if ($LASTEXITCODE -ne 0) {
    Write-Host ("[WARN] testkit down -v devolvió {0} (continúo)" -f $LASTEXITCODE)
}

& $Testkit up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }