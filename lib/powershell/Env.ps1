function Initialize-TestkitEnv {
  $script:ResolvedTestkitRoot = if ($env:TESTKIT_ROOT) { Resolve-Path $env:TESTKIT_ROOT } else { Resolve-Path $script:TestkitRoot }
  $script:ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $script:ResolvedTestkitRoot '..') }
}

function Get-TestkitEnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) { return (Resolve-Path $env:TESTKIT_ENV_FILE) }
  $a = Join-Path $script:ProjectRoot 'test\.env.test'
  $b = Join-Path $script:ProjectRoot '.env.test'
  if (Test-Path $a) { return (Resolve-Path $a) }
  if (Test-Path $b) { return (Resolve-Path $b) }
  return $null
}

function Convert-TestkitEnvFileToContainerPath([string]$EnvFilePath) {
  $projectRootPath = (Resolve-Path $script:ProjectRoot).Path
  $envFileResolved = (Resolve-Path $EnvFilePath).Path
  $rootEnv = Join-Path $script:ProjectRoot '.env.test'
  if ((Test-Path $rootEnv) -and ($envFileResolved -eq (Resolve-Path $rootEnv).Path)) { return '/workspace/project/.env.test' }
  $rel = $envFileResolved.Substring($projectRootPath.Length) -replace '^[\\/]+', ''
  return "/workspace/project/$($rel -replace '\\','/')"
}

function Import-TestkitEnvKV([string]$Path) {
  if (-not (Test-Path $Path)) { return }
  Get-Content $Path | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { return }
    if ($line -match '^[A-Za-z_][A-Za-z0-9_]*=') {
      $pair = $line.Split('=',2)
      Set-Item -Path ("Env:{0}" -f $pair[0]) -Value $pair[1]
    }
  }
}

function Test-TestkitPathUnderRoot([string]$Root, [string]$Candidate) {
  $rootResolved = (Resolve-Path $Root).Path
  $candidateResolved = (Resolve-Path $Candidate).Path
  return $candidateResolved.StartsWith($rootResolved, [System.StringComparison]::OrdinalIgnoreCase)
}
