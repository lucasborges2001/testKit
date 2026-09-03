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

  $overrides = [ordered]@{
    'TESTKIT_STACK_OVERRIDE' = 'TESTKIT_STACK'
    'TESTKIT_MYSQL_ROOT_PASSWORD_OVERRIDE' = 'TEST_MYSQL_ROOT_PASSWORD'
    'TESTKIT_MYSQL_DB_OVERRIDE' = 'TEST_MYSQL_DB'
    'TESTKIT_MYSQL_USER_OVERRIDE' = 'TEST_MYSQL_USER'
    'TESTKIT_MYSQL_PASSWORD_OVERRIDE' = 'TEST_MYSQL_PASSWORD'
  }
  foreach ($source in $overrides.Keys) {
    $value = [Environment]::GetEnvironmentVariable($source)
    if (-not [string]::IsNullOrWhiteSpace($value)) {
      Set-Item -Path ("Env:{0}" -f $overrides[$source]) -Value $value
    }
  }
}

function Test-TestkitPathUnderRoot([string]$Root, [string]$Candidate) {
  $rootResolved = (Resolve-Path $Root).Path.TrimEnd('\', '/')
  $candidateResolved = (Resolve-Path $Candidate).Path
  if ($candidateResolved.Equals($rootResolved, [System.StringComparison]::OrdinalIgnoreCase)) {
    return $true
  }
  $rootWithSeparator = $rootResolved + [System.IO.Path]::DirectorySeparatorChar
  return $candidateResolved.StartsWith($rootWithSeparator, [System.StringComparison]::OrdinalIgnoreCase)
}
