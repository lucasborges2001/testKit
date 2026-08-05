#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Doctor.BaseChecks.ps1')

$script:DoctorChecks = [System.Collections.Generic.List[object]]::new()

function Add-TestkitDoctorCheck {
  param(
    [string]$Section,
    [string]$Status,
    [string]$Code,
    [string]$Message,
    [string]$Action = ''
  )

  $script:DoctorChecks.Add([PSCustomObject]@{
    Section = $Section
    Status = $Status
    Code = $Code
    Message = $Message
    Action = $Action
  }) | Out-Null
}

function Normalize-TestkitDoctorToken([string]$Value) {
  if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
  return $Value.Trim().ToLowerInvariant()
}

function Test-TestkitPathUnderRoot([string]$Root, [string]$Candidate) {
  $rootResolved = (Resolve-Path $Root).Path.TrimEnd('\', '/')
  $candidateResolved = (Resolve-Path $Candidate).Path
  return $candidateResolved.StartsWith(
    $rootResolved + [System.IO.Path]::DirectorySeparatorChar,
    [System.StringComparison]::OrdinalIgnoreCase
  )
}

function Test-TestkitDoctorEnvPresentAny([string[]]$Names) {
  foreach ($name in $Names) {
    $value = [Environment]::GetEnvironmentVariable($name)
    if (-not [string]::IsNullOrWhiteSpace($value)) { return $true }
  }
  return $false
}

$tempProject = Join-Path ([System.IO.Path]::GetTempPath()) ("testkit-doctor-no-store-" + [guid]::NewGuid().ToString('N'))
$testDir = Join-Path $tempProject 'test'
$envFilePath = Join-Path $testDir '.env.test'

New-Item -ItemType Directory -Path $testDir -Force | Out-Null
Set-Content -Path $envFilePath -Encoding utf8 -Value @(
  'TEST_STORE_DRIVER=none'
  'TEST_STORE_PROVISION=external'
  'TESTKIT_STACK='
)

try {
  $script:ResolvedTestkitRoot = Resolve-Path $repoRoot
  $script:ProjectRoot = Resolve-Path $tempProject
  $env:TEST_STORE_DRIVER = 'none'
  $env:TEST_STORE_PROVISION = 'external'
  $env:TESTKIT_STACK = ''

  $context = [PSCustomObject]@{ ReadOnly = $true }
  $envFile = Resolve-Path $envFilePath
  $ok = $true

  Invoke-TestkitDoctorBaseChecks `
    -Context $context `
    -EnvFile $envFile `
    -StackCsv '' `
    -Ok ([ref]$ok)

  Assert-True (($script:DoctorChecks | Where-Object Code -eq 'STACK_RESOLVED').Count -eq 1) `
    'doctor base checks must accept and report an empty resolved stack'
  Assert-True (($script:DoctorChecks | Where-Object Code -eq 'STORE_DRIVER_NONE').Count -eq 1) `
    'doctor base checks must recognize TEST_STORE_DRIVER=none'
  Assert-True (($script:DoctorChecks | Where-Object Code -like 'MYSQL_*').Count -eq 0) `
    'doctor base checks must not require MySQL variables for TEST_STORE_DRIVER=none'
} finally {
  Remove-Item Env:\TEST_STORE_DRIVER -ErrorAction SilentlyContinue
  Remove-Item Env:\TEST_STORE_PROVISION -ErrorAction SilentlyContinue
  Remove-Item Env:\TESTKIT_STACK -ErrorAction SilentlyContinue
  Remove-Item -Recurse -Force $tempProject -ErrorAction SilentlyContinue
}

Complete-TestkitAssertions
