#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$seedScript = Join-Path $repoRoot 'scripts\seed.ps1'
$cleanScript = Join-Path $repoRoot 'scripts\db_clean.ps1'
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("testkit-store-contract-{0}" -f ([Guid]::NewGuid().ToString('N')))
$envFile = Join-Path $tempRoot '.env.test'

$trackedEnv = @('TESTKIT_PROJECT_ROOT', 'TESTKIT_ENV_FILE', 'TEST_STORE_DRIVER')
$snapshot = @{}
foreach ($key in $trackedEnv) {
  $snapshot[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}

function Set-ProcessEnv([string]$Name, [AllowNull()][object]$Value) {
  $envPath = "Env:{0}" -f $Name
  if ($null -eq $Value) {
    Remove-Item -Path $envPath -ErrorAction SilentlyContinue
    return
  }
  Set-Item -Path $envPath -Value ([string]$Value)
}

function Assert-Case(
  [string]$Label,
  [string]$Script,
  [string[]]$EnvLines,
  [AllowNull()][object]$ExplicitDriver,
  [int]$ExpectedExit,
  [string]$ExpectedText
) {
  Set-Content -LiteralPath $envFile -Value $EnvLines -Encoding utf8
  Set-ProcessEnv 'TEST_STORE_DRIVER' $ExplicitDriver

  $output = @(& pwsh -NoProfile -NonInteractive -File $Script 2>&1 | ForEach-Object { $_.ToString() })
  $exitCode = $LASTEXITCODE
  $joined = $output -join "`n"

  if ($exitCode -ne $ExpectedExit) {
    throw "${Label}: expected exit ${ExpectedExit}, got ${exitCode}. Output:`n${joined}"
  }
  if ($joined -notmatch [Regex]::Escape($ExpectedText)) {
    throw "${Label}: missing '${ExpectedText}'. Output:`n${joined}"
  }
}

New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
Set-ProcessEnv 'TESTKIT_PROJECT_ROOT' $tempRoot
Set-ProcessEnv 'TESTKIT_ENV_FILE' $envFile

try {
  foreach ($script in @($seedScript, $cleanScript)) {
    $name = Split-Path -Leaf $script

    Assert-Case -Label "${name} missing driver" -Script $script -EnvLines @('TESTKIT_STACK=mysql') -ExplicitDriver $null -ExpectedExit 2 -ExpectedText 'TEST_STORE_DRIVER_REQUIRED'
    Assert-Case -Label "${name} invalid driver" -Script $script -EnvLines @('TEST_STORE_DRIVER=postgres') -ExplicitDriver $null -ExpectedExit 2 -ExpectedText 'TEST_STORE_DRIVER_INVALID'

    $noneText = if ($name -eq 'seed.ps1') { 'bootstrap estructural no aplica' } else { 'limpieza estructural no aplica' }
    Assert-Case -Label "${name} exported driver wins over env file" -Script $script -EnvLines @('TEST_STORE_DRIVER=mysql') -ExplicitDriver 'none' -ExpectedExit 0 -ExpectedText $noneText
  }

  Write-Host 'PowerShell store driver explicit contract PASS'
}
finally {
  foreach ($key in $trackedEnv) {
    Set-ProcessEnv $key $snapshot[$key]
  }
  Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction SilentlyContinue
}
