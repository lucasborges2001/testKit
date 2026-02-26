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

if (-not $EnvFile) { Fail 'Falta env de tests (.env.test). Corré: .\bin\testkit.ps1 doctor para validar.' }

$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$baseDb   = Get-BaseDb
$fmt      = Get-WorkerSuffixFormat

$SchemaDir = Join-Path $TestRoot 'schema/mysql'
$SeedsDir  = Join-Path $TestRoot 'seeds/mysql'

# -----------------------------------------------------------------------------
# 2) Docker compose (stdin-friendly)
# -----------------------------------------------------------------------------
$BaseCompose = Join-Path $TestRoot 'compose.yaml'
$PgCompose   = Join-Path $TestRoot 'compose.pg.yaml'
if (-not (Test-Path $BaseCompose)) { Fail "Falta compose: $BaseCompose" }

$DcFiles = @('-f', $BaseCompose)
if (Test-Path $PgCompose) { $DcFiles += @('-f', $PgCompose) }

# Args base para: docker compose --env-file <env> -f compose.yaml [-f compose.pg.yaml]
$DcBase = @('compose','--env-file',$EnvFile) + $DcFiles

Set-Location $TestRoot

function Apply-SqlFiles([string]$db, [string]$dir, [string]$label) {
  if (-not (Test-Path $dir)) { return }

  Get-ChildItem -Path $dir -Filter '*.sql' | Sort-Object Name | ForEach-Object {
    Log ("==> {0}: {1}" -f $label, $_.Name)
    (Get-Content -Raw $_.FullName) | & docker @($DcBase + @('exec','-T','mysql_test','sh','-lc','mysql -uroot -p\"$MYSQL_ROOT_PASSWORD\" \"$1\"','--',$db))
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  }
}

function Ensure-Db([string]$db) {
  $sql = "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  $sql | & docker @($DcBase + @('exec','-T','mysql_test','sh','-lc','mysql -uroot -p\"$MYSQL_ROOT_PASSWORD\"'))
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
$services = & docker @($DcBase + @('ps','--services'))
if ($services -match '(^|\r?\n)postgres_test(\r?\n|$)') {
  $pgDir = Join-Path $TestRoot 'seeds/pgsql'
  if (Test-Path $pgDir) {
    Log '==> Seeding Postgres…'
    Get-ChildItem -Path $pgDir -Filter '*.sql' | Sort-Object Name | ForEach-Object {
      Log ("==> pg seed: {0}" -f $_.Name)
      (Get-Content -Raw $_.FullName) | & docker @($DcBase + @('exec','-T','postgres_test','sh','-lc','PGPASSWORD=\"$POSTGRES_PASSWORD\" psql -U \"$POSTGRES_USER\" -d \"$POSTGRES_DB\" -f -'))
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  } else {
    Log '==> Postgres activo, pero no hay seeds/pgsql (ok).'
  }
} else {
  Log '==> Postgres no activo (ok).'
}
