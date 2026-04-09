Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /testkit/scripts/test.ps1
# Ejecuta el meta-runner dentro del contenedor TestKit.
# Uso: .\test\scripts\test.ps1 [all|back|back-py|front|smoke|perf|...]
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

# Passthrough: todos los args se pasan al meta-runner
& $Testkit run --rm testkit php /workspace/testkit/runTest.php @args
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }