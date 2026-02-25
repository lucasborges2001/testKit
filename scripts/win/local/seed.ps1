. "$PSScriptRoot/../_lib.ps1"

# =============================================================================
# test/scripts/win/local/seed.ps1
#
# Aplica schema (opcional) + seeds (fixtures) en MySQL LOCAL.
#
# Requiere que la DB exista (creada por db_reset dropdb).
# No hace DROP/CREATE.
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

# -----------------------------------------------------------------------------
# 2) Binarios
# -----------------------------------------------------------------------------
$MysqlBin = $env:MYSQL_BIN; if (-not $MysqlBin) { $MysqlBin = "C:/xampp/mysql/bin/mysql.exe" }
if (-not (Test-Path $MysqlBin)) { Fail "No existe MYSQL_BIN: $MysqlBin" }

# -----------------------------------------------------------------------------
# 3) Conexión MySQL
# -----------------------------------------------------------------------------
$dbHost = $env:DB_HOST; if (-not $dbHost) { $dbHost = "127.0.0.1" }
$dbPort = $env:DB_PORT; if (-not $dbPort) { $dbPort = "3306" }
$dbUser = $env:DB_USER; if (-not $dbUser) { $dbUser = "root" }
$dbPass = $env:DB_PASS

$baseDb = $env:DB_NAME
if (-not $baseDb) { $baseDb = $env:TEST_MYSQL_DB }
if (-not $baseDb) { $baseDb = "app_test" }

$mysqlArgs = @("--protocol=tcp", "-h", $dbHost, "-P", $dbPort, "-u", $dbUser)
if ($dbPass -ne $null -and $dbPass -ne "") { $mysqlArgs += ("-p{0}" -f $dbPass) }

$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$fmt      = Get-WorkerSuffixFormat

$SchemaDir = Join-Path $TestRoot "schema/mysql"
$SeedsDir  = Join-Path $TestRoot "seeds/mysql"

function Assert-DbName([string]$db) {
  if ($db -notmatch "^[A-Za-z0-9_]+$") {
    Fail ("DB inválida: {0}. Solo se permiten [A-Za-z0-9_]." -f $db)
  }
}

function Assert-Db-Exists([string]$db) {
  Assert-DbName $db
  $exists = & $MysqlBin @mysqlArgs -Nse ("SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME="{0}";" -f $db)
  if ($LASTEXITCODE -ne 0) { Fail ("mysql falló verificando DB {0} (exit={1})" -f $db, $LASTEXITCODE) }
  if (-not $exists -or $exists.Trim() -eq "") {
    Fail ("La DB {0} no existe. Ejecutá primero db_reset.ps1 dropdb." -f $db)
  }
}

function Apply-SqlFiles([string]$db, [string]$dir, [string]$label) {
  if (-not (Test-Path $dir)) { return }
  Get-ChildItem -Path $dir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
    Log ("==> {0}: {1}" -f $label, $_.Name)
    (Get-Content -Raw $_.FullName) | & $MysqlBin @mysqlArgs $db
    if ($LASTEXITCODE -ne 0) { Fail ("mysql falló aplicando {0}: {1} (exit={2})" -f $label, $_.Name, $LASTEXITCODE) }
  }
}

function Seed-Db([string]$db) {
  Log ("==> Seeding MySQL DB: {0}" -f $db)
  Assert-Db-Exists $db
  Apply-SqlFiles -db $db -dir $SchemaDir -label "schema"
  Apply-SqlFiles -db $db -dir $SeedsDir  -label "seed"
}

if ($strategy -eq "per_worker") {
  for ($i = 1; $i -le $jobs; $i++) {
    $db = Mk-DbName -baseDb $baseDb -fmt $fmt -w $i
    Seed-Db $db
  }
} else {
  Seed-Db $baseDb
}
