. "$PSScriptRoot\..\_lib.ps1"

# =============================================================================
# test/scripts/win/docker/seed.ps1
#
# Aplica schema (opcional) + seeds (fixtures) en MySQL dentro de Docker (mysql_test).
# Postgres: si el servicio postgres_test está levantado, aplica seeds/pgsql (si existe).
#
# Convención:
# - Schema MySQL (opcional): test/schema/mysql/*.sql
# - Seeds  MySQL (fixtures):  test/seeds/mysql/*.sql
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

# -----------------------------------------------------------------------------
# 1) Env (opcional)
# -----------------------------------------------------------------------------
$EnvFile = Pick-EnvFile -TestRoot $TestRoot -RepoRoot $RepoRoot
if ($EnvFile) { Load-EnvKVSafe $EnvFile }

$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$baseDb   = Get-BaseDb
$fmt      = Get-WorkerSuffixFormat

$SchemaDir = Join-Path $TestRoot 'schema/mysql'
$SeedsDir  = Join-Path $TestRoot 'seeds/mysql'

# -----------------------------------------------------------------------------
# 2) TestKit
# -----------------------------------------------------------------------------
$Testkit = Resolve-Testkit -TestRoot $TestRoot -RepoRoot $RepoRoot
Assert-Testkit-Windows $Testkit
Set-Location $TestRoot

function Apply-SqlFiles([string]$db, [string]$dir, [string]$label) {
  if (-not (Test-Path $dir)) { return }

  Get-ChildItem -Path $dir -Filter '*.sql' | Sort-Object Name | ForEach-Object {
    Log ("==> {0}: {1}" -f $label, $_.Name)
    (Get-Content -Raw $_.FullName) |
      & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- $db
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  }
}

function Ensure-Db([string]$db) {
  $sql = "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  $sql | & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Seed-Db([string]$db) {
  Log ("==> Seeding MySQL DB: {0}" -f $db)
  Ensure-Db $db

  Apply-SqlFiles -db $db -dir $SchemaDir -label 'schema'
  Apply-SqlFiles -db $db -dir $SeedsDir  -label 'seed'
}

# -----------------------------------------------------------------------------
# 3) MySQL
# -----------------------------------------------------------------------------
if ($strategy -eq 'per_worker') {
  for ($i = 1; $i -le $jobs; $i++) {
    $db = Mk-DbName -baseDb $baseDb -fmt $fmt -w $i
    Seed-Db $db
  }
} else {
  Seed-Db $baseDb
}

# -----------------------------------------------------------------------------
# 4) Postgres (opcional)
# -----------------------------------------------------------------------------
$services = & $Testkit ps --services
if ($services -match '(^|\r?\n)postgres_test(\r?\n|$)') {
  $pgDir = Join-Path $TestRoot 'seeds/pgsql'
  if (Test-Path $pgDir) {
    Log '==> Seeding Postgres…'
    Get-ChildItem -Path $pgDir -Filter '*.sql' | Sort-Object Name | ForEach-Object {
      Log ("==> pg seed: {0}" -f $_.Name)
      (Get-Content -Raw $_.FullName) |
        & $Testkit exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -'
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  } else {
    Log '==> Postgres activo, pero no hay seeds/pgsql (ok).'
  }
} else {
  Log '==> Postgres no activo (ok).'
}
