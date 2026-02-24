Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /test/scripts/seed.ps1
# Aplica seeds a MySQL en docker (mysql_test). Postgres opcional.
# =============================================================================

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$RepoRoot = Resolve-Path (Join-Path $TestRoot "..")

function Resolve-Testkit {
  $override = $env:TESTKIT_BIN
  if ($override -and (Test-Path $override)) { return (Resolve-Path $override).Path }

  $candidates = @(
    Join-Path $TestRoot "bin/testkit.ps1",
    Join-Path $RepoRoot "bin/testkit.ps1",
    Join-Path $TestRoot "bin/testkit",
    Join-Path $RepoRoot "bin/testkit"
  )

  foreach ($c in $candidates) {
    if (Test-Path $c) { return (Resolve-Path $c).Path }
  }

  throw "No se encontró TestKit. Seteá TESTKIT_BIN o agregá bin/testkit(.ps1)."
}

$Testkit = Resolve-Testkit
if ($IsWindows -and -not $Testkit.ToLower().EndsWith(".ps1")) {
  throw "En Windows se espera bin/testkit.ps1. Si solo tenés bin/testkit (bash), usá los .sh."
}

Set-Location $TestRoot

# -----------------------------------------------------------------------------
# Env loader (KEY=VALUE)
# -----------------------------------------------------------------------------
function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) { return (Resolve-Path $env:TESTKIT_ENV_FILE).Path }
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
      $pair = $line.Split('=', 2)
      $k = $pair[0]
      $v = $pair[1]
      if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
        $v = $v.Substring(1, $v.Length - 2)
      }
      Set-Item -Path ("Env:{0}" -f $k) -Value $v
    }
  }
}

$envFile = Pick-EnvFile
if ($envFile) { Load-EnvKVSafe $envFile }

# -----------------------------------------------------------------------------
# Config
# -----------------------------------------------------------------------------
$strategy = $env:TEST_DB_STRATEGY
if (-not $strategy) { $strategy = "shared" }

$jobs = 1
if ($env:TEST_JOBS) { [int]::TryParse($env:TEST_JOBS, [ref]$jobs) | Out-Null }
if ($jobs -lt 1) { $jobs = 1 }

$baseDb = $env:TEST_MYSQL_DB
if (-not $baseDb) { $baseDb = "app_test" }

$fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT
if (-not $fmt) { $fmt = "_w%02d" }

function Mk-DbName([int]$w) {
  if ($fmt -match '%0(\d+)d') {
    $width = [int]$Matches[1]
    return ($baseDb + "_w" + $w.ToString("D$width"))
  }
  return ($baseDb + "_w" + $w)
}

# -----------------------------------------------------------------------------
# Seeds MySQL
# -----------------------------------------------------------------------------
function Seed-MySqlDb([string]$db) {
  Write-Host ("==> Seeding MySQL DB: {0}" -f $db)

  ("CREATE DATABASE IF NOT EXISTS {0} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $db) |
  & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

  $mysqlDir = Join-Path $TestRoot "seeds/mysql"
  if (-not (Test-Path $mysqlDir)) { return }

  Get-ChildItem -Path $mysqlDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
    Write-Host ("   - {0}" -f $_.Name)
    (Get-Content -Raw $_.FullName) |
    & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- $db
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  }
}

function Seed-MySqlShared {
  Write-Host "==> Seeding MySQL (shared)…"
  $mysqlDir = Join-Path $TestRoot "seeds/mysql"
  if (-not (Test-Path $mysqlDir)) { return }

  Get-ChildItem -Path $mysqlDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
    Write-Host ("   - {0}" -f $_.Name)
    (Get-Content -Raw $_.FullName) |
    & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  }
}

if ($strategy -eq "per_worker") {
  for ($i = 1; $i -le $jobs; $i++) { Seed-MySqlDb (Mk-DbName $i) }
}
else {
  Seed-MySqlShared
}

# -----------------------------------------------------------------------------
# Postgres opcional
# -----------------------------------------------------------------------------
$services = & $Testkit ps --services
if ($services -match '(^|\r?\n)postgres_test(\r?\n|$)') {
  Write-Host "==> Seeding Postgres…"
  $pgDir = Join-Path $TestRoot "seeds/pgsql"
  if (Test-Path $pgDir) {
    Get-ChildItem -Path $pgDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
      Write-Host ("   - {0}" -f $_.Name)
      (Get-Content -Raw $_.FullName) |
      & $Testkit exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -'
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  }
}
else {
  Write-Host "==> Postgres no activo (ok)."
}