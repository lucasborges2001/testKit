#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Doctor.BaseChecks.ps1')
. (Join-Path $repoRoot 'lib\powershell\Doctor.Render.ps1')

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
    Summary = $Message
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
  $env:TESTKIT_DB_ENV_PATH = '/workspace/project/test/.env.test'

  $context = [PSCustomObject]@{
    ReadOnly = $true
    Mode = 'compact'
    Target = ''
    Dump = $false
  }
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

  $script:TestkitDoctorBaseChecks = $script:DoctorChecks
  $script:TestkitDoctorCapabilityChecks = [System.Collections.Generic.List[object]]::new()
  $script:TestkitDoctorBaseStatus = if ($ok) { 'PASS' } else { 'FAIL' }
  $script:TestkitDoctorCapabilityStatus = 'PASS'

  $compactRendered = $true
  try {
    Show-TestkitDoctorCompact -Context $context -EnvFile $envFile -StackCsv '' *> $null
  } catch {
    $compactRendered = $false
  }
  Assert-True $compactRendered `
    'compact doctor renderer must accept an empty resolved stack'

  $fullContext = [PSCustomObject]@{
    ReadOnly = $true
    Mode = 'full'
    Target = ''
    Dump = $false
  }
  $fullRendered = $true
  try {
    Show-TestkitDoctorFull -Context $fullContext -EnvFile $envFile -StackCsv '' *> $null
  } catch {
    $fullRendered = $false
  }
  Assert-True $fullRendered `
    'full doctor renderer must accept an empty resolved stack'

  $dumpRendered = $true
  try {
    Show-TestkitDoctorDump -Context $fullContext -EnvFile $envFile -StackCsv '' *> $null
  } catch {
    $dumpRendered = $false
  }
  Assert-True $dumpRendered `
    'doctor dump renderer must accept an empty resolved stack'
} finally {
  Remove-Item Env:\TEST_STORE_DRIVER -ErrorAction SilentlyContinue
  Remove-Item Env:\TEST_STORE_PROVISION -ErrorAction SilentlyContinue
  Remove-Item Env:\TESTKIT_STACK -ErrorAction SilentlyContinue
  Remove-Item Env:\TESTKIT_DB_ENV_PATH -ErrorAction SilentlyContinue
  Remove-Item -Recurse -Force $tempProject -ErrorAction SilentlyContinue
}

Complete-TestkitAssertions
