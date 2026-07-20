#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Env.ps1')

$tempBase = Join-Path ([System.IO.Path]::GetTempPath()) ("testkit-pathcheck-" + [guid]::NewGuid().ToString('N'))
$root = Join-Path $tempBase 'Pruebas'
$siblingPrefix = Join-Path $tempBase 'Pruebas-otro'
$subdir = Join-Path $root 'sub\dir'

New-Item -ItemType Directory -Path $subdir -Force | Out-Null
New-Item -ItemType Directory -Path $siblingPrefix -Force | Out-Null

try {
  Assert-True (Test-TestkitPathUnderRoot $root $root) `
    'a root must be considered under itself (exact match)'

  Assert-True (Test-TestkitPathUnderRoot $root $subdir) `
    'a nested subdirectory must be considered under root'

  Assert-True (Test-TestkitPathUnderRoot "$root\" $subdir) `
    'a trailing separator on root must not break containment'

  Assert-True (Test-TestkitPathUnderRoot $root.ToUpper() $subdir) `
    'containment must be case-insensitive (NTFS semantics)'

  Assert-True (-not (Test-TestkitPathUnderRoot $root $siblingPrefix)) `
    'a sibling directory that only shares a name PREFIX (Pruebas vs Pruebas-otro) must NOT be treated as under root'
} finally {
  Remove-Item -Recurse -Force $tempBase -ErrorAction SilentlyContinue
}

Complete-TestkitAssertions
