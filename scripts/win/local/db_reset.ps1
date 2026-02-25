param(
  # ResetMode:
  # - heavy  : en local equivale a dropdb (no controla el servicio MySQL)
  # - dropdb : DROP DATABASE + CREATE DATABASE
  # - fast   : TRUNCATE tablas (rapido; requiere schema ya aplicado)
  [Parameter(Position=0)]
  [ValidateSet("heavy","dropdb","fast")]
  [string]$ResetMode = "dropdb"
)

. "$PSScriptRoot/../_lib.ps1"

# =============================================================================
# test/scripts/win/local/db_reset.ps1
#
# Reset de DB en modo LOCAL (XAMPP/MySQL).
#
# Este script NO aplica schema ni seeds.
# Orden recomendado:
#   1) db_reset.ps1 [heavy|dropdb|fast]
#   2) seed.ps1
#   3) test.ps1 <target>
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

# -----------------------------------------------------------------------------
# 1) Env (opcional)
# -----------------------------------------------------------------------------
$EnvFile = Pick-EnvFile -TestRoot $TestRoot -RepoRoot $RepoRoot
if ($EnvFile) { Load-EnvKVSafe $EnvFile }

$ResetMode = Resolve-ResetMode -requested $ResetMode
if ($ResetMode -eq "heavy") { $ResetMode = "dropdb" }

# -----------------------------------------------------------------------------
# 2) Binarios
# -----------------------------------------------------------------------------
$MysqlBin = $env:MYSQL_BIN; if (-not $MysqlBin) { $MysqlBin = "C:/xampp/mysql/bin/mysql.exe" }
if (-not (Test-Path $MysqlBin)) { Fail "No existe MYSQL_BIN: $MysqlBin" }

# -----------------------------------------------------------------------------
# 3) Conexión MySQL (prioridad DB_*)
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

Log ("==> LOCAL MySQL: {0}@{1}:{2} / base_db={3} / reset={4}" -f $dbUser, $dbHost, $dbPort, $baseDb, $ResetMode)

# -----------------------------------------------------------------------------
# 4) Estrategia shared vs per_worker
# -----------------------------------------------------------------------------
$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$fmt      = Get-WorkerSuffixFormat

function Assert-DbName([string]$db) {
  if ($db -notmatch "^[A-Za-z0-9_]+$") {
    Fail ("DB inválida: {0}. Solo se permiten [A-Za-z0-9_]." -f $db)
  }
}

function DropCreate([string]$db) {
  Assert-DbName $db
  ("DROP DATABASE IF EXISTS `{0}`; CREATE DATABASE `{0}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $db) |
    & $MysqlBin @mysqlArgs
  if ($LASTEXITCODE -ne 0) { Fail ("mysql falló drop/create {0} (exit={1})" -f $db, $LASTEXITCODE) }
}

function TruncateAll([string]$db) {
  Assert-DbName $db

  $tables = & $MysqlBin @mysqlArgs $db -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE";"
  if ($LASTEXITCODE -ne 0) { Fail ("mysql falló leyendo tablas en {0} (exit={1})" -f $db, $LASTEXITCODE) }

  if (-not $tables -or ($tables -join "").Trim() -eq "") {
    Log ("==> fast: no hay tablas en {0} (ok)" -f $db)
    return
  }

  $sql = "SET FOREIGN_KEY_CHECKS=0;`n"
  foreach ($t in $tables) {
    $tt = $t.Trim()
    if ($tt -match "^[A-Za-z0-9_]+$") { $sql += ("TRUNCATE TABLE `{0}`;`n" -f $tt) }
  }
  $sql += "SET FOREIGN_KEY_CHECKS=1;`n"

  $sql | & $MysqlBin @mysqlArgs $db
  if ($LASTEXITCODE -ne 0) { Fail ("mysql falló truncando tablas en {0} (exit={1})" -f $db, $LASTEXITCODE) }
}

# -----------------------------------------------------------------------------
# 5) Reset
# -----------------------------------------------------------------------------
function Reset-One([string]$db) {
  if ($ResetMode -eq "dropdb") {
    Log ("==> drop/create: {0}" -f $db)
    DropCreate $db
  } else {
    Log ("==> fast(truncate): {0}" -f $db)
    TruncateAll $db
  }
}

if ($strategy -eq "per_worker") {
  for ($i = 1; $i -le $jobs; $i++) {
    $db = Mk-DbName -baseDb $baseDb -fmt $fmt -w $i
    Reset-One $db
  }
} else {
  Reset-One $baseDb
}
