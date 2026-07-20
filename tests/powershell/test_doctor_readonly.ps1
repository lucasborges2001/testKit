#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Env.ps1')
. (Join-Path $repoRoot 'lib\powershell\Stack.ps1')
. (Join-Path $repoRoot 'lib\powershell\Doctor.ps1')

function Invoke-DoctorAgainstFreshProject([string[]]$DoctorArgs) {
  $tempProject = Join-Path ([System.IO.Path]::GetTempPath()) ("testkit-doctorreadonly-" + [guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $tempProject -Force | Out-Null

  try {
    $env:TESTKIT_ROOT = $repoRoot
    $env:TESTKIT_PROJECT_ROOT = $tempProject
    Initialize-TestkitEnv

    Invoke-TestkitDoctor $DoctorArgs *> $null | Out-Null

    return [PSCustomObject]@{
      ProjectRoot = $tempProject
      TestDirCreated = Test-Path (Join-Path $tempProject 'test')
    }
  } finally {
    Remove-Item Env:\TESTKIT_ROOT -ErrorAction SilentlyContinue
    Remove-Item Env:\TESTKIT_PROJECT_ROOT -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $tempProject -ErrorAction SilentlyContinue
  }
}

$readonlyResult = Invoke-DoctorAgainstFreshProject @('--readonly', '--compact')
Assert-True (-not $readonlyResult.TestDirCreated) `
  'doctor --readonly must not create <project>/test'

$defaultResult = Invoke-DoctorAgainstFreshProject @('--compact')
Assert-True $defaultResult.TestDirCreated `
  'sanity check: doctor WITHOUT --readonly must still create <project>/test (proves the readonly branch is actually being exercised above, not just always skipping)'

Complete-TestkitAssertions
