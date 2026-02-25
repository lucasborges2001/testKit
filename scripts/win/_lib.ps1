Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# =============================================================================
# test/scripts/win/_lib.ps1
# Librería compartida para scripts de testing en Windows (PowerShell 5.1+).
#
# Objetivos:
# - Resolver paths (RepoRoot/TestRoot/ScriptsDir)
# - Cargar env de tests (KEY=VALUE) de forma segura
# - Resolver TestKit (docker) cuando existe
# - Normalizar opciones: Mode (local|docker|auto) y ResetMode (heavy|dropdb|fast)
# - Helpers para estrategia de DB (shared|per_worker)
# =============================================================================

# -----------------------------------------------------------------------------
# 1) Paths base
# -----------------------------------------------------------------------------
function Get-Paths {
  $ScriptsDir = Split-Path -Parent $MyInvocation.MyCommand.Path  # .../test/scripts/win
  $TestRoot   = Resolve-Path (Join-Path $ScriptsDir "..\..")      # .../test
  $RepoRoot   = Resolve-Path (Join-Path $TestRoot "..")          # .../<repo>

  return [pscustomobject]@{
    ScriptsDir = $ScriptsDir
    TestRoot   = $TestRoot.Path
    RepoRoot   = $RepoRoot.Path
  }
}

# -----------------------------------------------------------------------------
# 2) Logging y fallos
# -----------------------------------------------------------------------------
function Log([string]$msg)  { Write-Host $msg }
function Warn([string]$msg) { Write-Host ("[WARN] {0}" -f $msg) }
function Fail([string]$msg) { Write-Error $msg; exit 1 }

# -----------------------------------------------------------------------------
# 3) Env loader (KEY=VALUE)
# -----------------------------------------------------------------------------
function Pick-EnvFile([string]$TestRoot, [string]$RepoRoot) {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) {
    return (Resolve-Path $env:TESTKIT_ENV_FILE).Path
  }
  $a = Join-Path $TestRoot ".env.test"
  $b = Join-Path $RepoRoot ".env.test"
  if (Test-Path $a) { return (Resolve-Path $a).Path }
  if (Test-Path $b) { return (Resolve-Path $b).Path }
  return $null
}

function Load-EnvKVSafe([string]$Path) {
  if (-not (Test-Path $Path)) { return }

  Get-Content $Path | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq "" -or $line.StartsWith("#")) { return }

    if ($line -match '^[A-Za-z_][A-Za-z0-9_]*=') {
      $pair = $line.Split('=',2)
      $k = $pair[0]
      $v = $pair[1]

      # Quita comillas simples o dobles si están balanceadas
      if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
        $v = $v.Substring(1, $v.Length-2)
      }

      Set-Item -Path ("Env:{0}" -f $k) -Value $v
    }
  }
}

# -----------------------------------------------------------------------------
# 4) Resolución de Mode (auto|local|docker)
# -----------------------------------------------------------------------------
function Resolve-Mode([string]$requested, [string]$RepoRoot, [string]$TestRoot) {
  $m = $requested
  if ($null -eq $m -or $m -eq '') { $m = 'auto' }
  $m = $m.ToLower().Trim()

  if ($m -eq 'auto') {
    if ($env:TEST_DB_MODE) {
      $m = $env:TEST_DB_MODE.ToLower().Trim()
    } else {
      $tk1 = Join-Path $RepoRoot 'bin/testkit.ps1'
      $tk2 = Join-Path $RepoRoot 'bin/testkit'
      $tk3 = Join-Path $TestRoot 'bin/testkit.ps1'
      $tk4 = Join-Path $TestRoot 'bin/testkit'
      if ((Test-Path $tk1) -or (Test-Path $tk2) -or (Test-Path $tk3) -or (Test-Path $tk4)) { $m = 'docker' } else { $m = 'local' }
    }
  }

  if ($m -ne 'local' -and $m -ne 'docker') {
    Fail ("TEST_DB_MODE inválido: {0}. Valores: local|docker|auto" -f $m)
  }
  return $m
}

# -----------------------------------------------------------------------------
# 5) Resolución de ResetMode (heavy|dropdb|fast)
# -----------------------------------------------------------------------------
function Resolve-ResetMode([string]$requested) {
  $r = $requested
  if ($null -eq $r) { $r = '' }
  $r = $r.ToLower().Trim()
  if (-not $r) {
    if ($env:TEST_RESET_MODE) { $r = $env:TEST_RESET_MODE.ToLower().Trim() }
  }
  if (-not $r) { $r = 'dropdb' }

  if ($r -ne 'heavy' -and $r -ne 'dropdb' -and $r -ne 'fast') {
    Fail ("TEST_RESET_MODE inválido: {0}. Valores: heavy|dropdb|fast" -f $r)
  }
  return $r
}

# -----------------------------------------------------------------------------
# 6) TestKit (docker) resolver
# -----------------------------------------------------------------------------
function Resolve-Testkit([string]$TestRoot, [string]$RepoRoot) {
  $override = $env:TESTKIT_BIN
  if ($override -and (Test-Path $override)) { return (Resolve-Path $override).Path }

  $candidates = @(
    Join-Path $TestRoot 'bin/testkit.ps1',
    Join-Path $RepoRoot 'bin/testkit.ps1',
    Join-Path $TestRoot 'bin/testkit',
    Join-Path $RepoRoot 'bin/testkit'
  )

  foreach ($c in $candidates) {
    if (Test-Path $c) { return (Resolve-Path $c).Path }
  }

  Fail 'No se encontró TestKit. Seteá TESTKIT_BIN o agregá bin/testkit(.ps1).'
}

function Assert-Testkit-Windows([string]$TestkitPath) {
  $IsWin = ($env:OS -eq 'Windows_NT')
  if ($IsWin -and -not $TestkitPath.ToLower().EndsWith('.ps1')) {
    Fail 'En Windows se espera bin/testkit.ps1. Si solo tenés bin/testkit (bash), usá los scripts unix/.'
  }
}

# -----------------------------------------------------------------------------
# 7) DB Strategy helpers
# -----------------------------------------------------------------------------
function Get-DbStrategy {
  $strategy = $env:TEST_DB_STRATEGY
  if (-not $strategy) { $strategy = 'shared' }
  return $strategy
}

function Get-Jobs {
  $jobs = 1
  if ($env:TEST_JOBS) { [int]::TryParse($env:TEST_JOBS, [ref]$jobs) | Out-Null }
  if ($jobs -lt 1) { $jobs = 1 }
  return $jobs
}

function Get-BaseDb {
  $baseDb = $env:TEST_MYSQL_DB
  if (-not $baseDb) { $baseDb = 'app_test' }
  return $baseDb
}

function Get-WorkerSuffixFormat {
  $fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT
  if (-not $fmt) { $fmt = '_w%02d' }
  return $fmt
}

function Mk-DbName([string]$baseDb, [string]$fmt, [int]$w) {
  if ($fmt -match '%0(\d+)d') {
    $width = [int]$Matches[1]
    return ($baseDb + '_w' + $w.ToString("D$width"))
  }
  return ($baseDb + '_w' + $w)
}
