param(
  # ResetMode:
  # - heavy  : docker down -v + up -d (resetea volúmenes)
  # - dropdb : DROP/CREATE de la(s) base(s) de test dentro del contenedor mysql_test
  # - fast   : TRUNCATE tablas (vía db_clean)
  [Parameter(Position=0)]
  [ValidateSet('heavy','dropdb','fast')]
  [string]$ResetMode = 'dropdb'
)

. "$PSScriptRoot\..\_lib.ps1"

# =============================================================================
# test/scripts/win/docker/db_reset.ps1
#
# Reset de DB en modo Docker (TestKit).
#
# Este script NO aplica seeds.
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

# Env (opcional; define estrategia y nombres)
$EnvFile = Pick-EnvFile -TestRoot $TestRoot -RepoRoot $RepoRoot
if ($EnvFile) { Load-EnvKVSafe $EnvFile }

if (-not $EnvFile) { Fail 'Falta env de tests (.env.test). Corré: .\bin\testkit.ps1 doctor para validar.' }

$ResetMode = Resolve-ResetMode -requested $ResetMode

$Testkit = Resolve-Testkit -TestRoot $TestRoot -RepoRoot $RepoRoot
Assert-Testkit-Windows $Testkit
Set-Location $TestRoot

# Docker compose (stdin-friendly)
$BaseCompose = Join-Path $TestRoot 'compose.yaml'
$PgCompose   = Join-Path $TestRoot 'compose.pg.yaml'
if (-not (Test-Path $BaseCompose)) { Fail "Falta compose: $BaseCompose" }

$DcFiles = @('-f', $BaseCompose)
if (Test-Path $PgCompose) { $DcFiles += @('-f', $PgCompose) }
$DcBase = @('compose','--env-file',$EnvFile) + $DcFiles


$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$baseDb   = Get-BaseDb
$fmt      = Get-WorkerSuffixFormat

function Assert-DbName([string]$db) {
  if ($db -notmatch '^[A-Za-z0-9_]+$') {
    Fail ("DB inválida: '{0}'. Solo se permiten [A-Za-z0-9_]." -f $db)
  }
}

function Ensure-Up {
  & $Testkit up -d
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function DropCreate([string]$db) {
  Assert-DbName $db
  $sql = "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  $sql | & docker @($DcBase + @('exec','-T','mysql_test','sh','-lc','mysql -uroot -p\"$MYSQL_ROOT_PASSWORD\"'))
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

# -----------------------------------------------------------------------------
# 1) heavy
# -----------------------------------------------------------------------------
if ($ResetMode -eq 'heavy') {
  & $Testkit down -v
  if ($LASTEXITCODE -ne 0) { Warn ("testkit down -v devolvió {0} (continúo)" -f $LASTEXITCODE) }

  & $Testkit up -d
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  exit 0
}

# -----------------------------------------------------------------------------
# 2) dropdb
# -----------------------------------------------------------------------------
if ($ResetMode -eq 'dropdb') {
  Ensure-Up

  if ($strategy -eq 'per_worker') {
    for ($i = 1; $i -le $jobs; $i++) {
      $db = Mk-DbName -baseDb $baseDb -fmt $fmt -w $i
      Log ("==> drop/create: {0}" -f $db)
      DropCreate $db
    }
  } else {
    Log ("==> drop/create: {0}" -f $baseDb)
    DropCreate $baseDb
  }

  exit 0
}

# -----------------------------------------------------------------------------
# 3) fast
# -----------------------------------------------------------------------------
if ($ResetMode -eq 'fast') {
  Ensure-Up
  $clean = Join-Path $PSScriptRoot 'db_clean.ps1'
  if (-not (Test-Path $clean)) { Fail "Falta script: $clean" }

  if ($strategy -eq 'per_worker') {
    & $clean --all
  } else {
    & $clean
  }
  exit $LASTEXITCODE
}
