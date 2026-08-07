Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /testkit/scripts/seed.ps1
# Aplica la base estructural del proyecto por store dentro del contenedor TestKit.
# TEST_STORE_DRIVER es el único selector de store.
# =============================================================================

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { $null }

function Resolve-Testkit {
  $override = $env:TESTKIT_BIN
  if ($override -and (Test-Path $override)) { return (Resolve-Path $override).Path }
  $candidate = Join-Path $TestRoot "bin/testkit.ps1"
  if (Test-Path $candidate) { return (Resolve-Path $candidate).Path }
  throw "No se encontró bin/testkit.ps1. Seteá TESTKIT_BIN o instalá TestKit."
}

$Testkit = Resolve-Testkit
Set-Location $TestRoot

function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) { return (Resolve-Path $env:TESTKIT_ENV_FILE).Path }
  if ($ProjectRoot) {
    $a = Join-Path $ProjectRoot "test\.env.test"
    $b = Join-Path $ProjectRoot ".env.test"
    if (Test-Path $a) { return (Resolve-Path $a).Path }
    if (Test-Path $b) { return (Resolve-Path $b).Path }
  }
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
if (-not $envFile) { throw "Falta env de tests del proyecto." }
Load-EnvKVSafe $envFile

$driver = $env:TEST_STORE_DRIVER
if ([string]::IsNullOrEmpty($driver)) {
  Write-Error "[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Usá mysql|pgsql|none."
  exit 2
}
if ($driver -notin @('mysql','pgsql','none')) {
  Write-Error "[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='$driver' no es válido. Valores exactos: mysql|pgsql|none."
  exit 2
}
if ($driver -eq 'none') {
  Write-Host '==> TEST_STORE_DRIVER=none: bootstrap estructural no aplica.'
  exit 0
}

$strategy = $env:TEST_DB_STRATEGY; if (-not $strategy) { $strategy = "shared" }
$jobs = 1
if ($env:TEST_JOBS) { [int]::TryParse($env:TEST_JOBS, [ref]$jobs) | Out-Null }
if ($jobs -lt 1) { $jobs = 1 }
$baseMysqlDb = $env:TEST_MYSQL_DB; if (-not $baseMysqlDb) { $baseMysqlDb = "app_test" }
$basePgDb = $env:TEST_PG_DB; if (-not $basePgDb) { $basePgDb = "app_test" }
$fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT; if (-not $fmt) { $fmt = "_w%02d" }

function Mk-DbName([string]$baseName, [int]$w) {
  if ($fmt -match '%0(\d+)d') {
    $width = [int]$Matches[1]
    return ($baseName + "_w" + $w.ToString("D$width"))
  }
  return ($baseName + "_w" + $w)
}

function Assert-SafeDbName([string]$db) {
  if ($db -notmatch '^[A-Za-z0-9._-]+$') {
    throw "Nombre de DB inválido para provisioning: $db"
  }
}

function Driver-BaseDb([string]$storeDriver) {
  if ($storeDriver -eq "pgsql") {
    if ($env:TEST_PG_DB) { return $env:TEST_PG_DB }
    if ($env:PG_DB) { return $env:PG_DB }
    return $basePgDb
  }
  if ($env:DB_NAME) { return $env:DB_NAME }
  return $baseMysqlDb
}

function Bootstrap-StoreShared([string]$storeDriver) {
  Write-Host ("==> Bootstrapping store: {0} (shared)" -f $storeDriver)
  & $Testkit run --rm -e TEST_STORE_DRIVER=$storeDriver testkit php /workspace/testkit/scripts/store_router.php bootstrap $storeDriver
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Bootstrap-StoreDb([string]$storeDriver, [string]$db) {
  Write-Host ("==> Bootstrapping store: {0} / db={1}" -f $storeDriver, $db)
  Assert-SafeDbName $db
  if ($storeDriver -eq "pgsql") {
    & $Testkit run --rm -e TEST_STORE_DRIVER=pgsql -e PG_DB=$db -e TEST_PG_DB=$db testkit php /workspace/testkit/scripts/store_router.php bootstrap pgsql
  }
  else {
    & $Testkit run --rm -e TEST_STORE_DRIVER=mysql -e DB_NAME=$db -e TEST_MYSQL_DB=$db testkit php /workspace/testkit/scripts/store_router.php bootstrap mysql
  }
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Bootstrap-Driver([string]$storeDriver) {
  $baseDb = Driver-BaseDb $storeDriver
  if ($strategy -eq "per_worker") {
    for ($i = 1; $i -le $jobs; $i++) {
      Bootstrap-StoreDb $storeDriver (Mk-DbName $baseDb $i)
    }
    return
  }

  Bootstrap-StoreShared $storeDriver
}

Bootstrap-Driver $driver
