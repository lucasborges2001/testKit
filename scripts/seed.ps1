Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /testkit/scripts/seed.ps1
# Aplica la base estructural del proyecto por store dentro del contenedor TestKit.
# Lifecycle: bootstrap(store) -> reset -> schema -> base -> migrations -> validations.
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

$strategy = $env:TEST_DB_STRATEGY; if (-not $strategy) { $strategy = "shared" }
$jobs = 1
if ($env:TEST_JOBS) { [int]::TryParse($env:TEST_JOBS, [ref]$jobs) | Out-Null }
if ($jobs -lt 1) { $jobs = 1 }
$baseMysqlDb = $env:TEST_MYSQL_DB; if (-not $baseMysqlDb) { $baseMysqlDb = "app_test" }
$basePgDb = $env:TEST_PG_DB; if (-not $basePgDb) { $basePgDb = "app_test" }
$fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT; if (-not $fmt) { $fmt = "_w%02d" }
$services = & $Testkit ps --services 2>$null
$serviceNames = @($services -split "\r?\n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })

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

function Normalize-Driver([string]$driver) {
  if (-not $driver) { return "mysql" }
  if ($driver.ToLower().StartsWith("pg")) { return "pgsql" }
  return "mysql"
}

function Driver-BaseDb([string]$driver) {
  if ($driver -eq "pgsql") {
    if ($env:TEST_PG_DB) { return $env:TEST_PG_DB }
    if ($env:PG_DB) { return $env:PG_DB }
    return $basePgDb
  }
  if ($env:DB_NAME) { return $env:DB_NAME }
  return $baseMysqlDb
}

function Bootstrap-StoreShared([string]$driver) {
  Write-Host ("==> Bootstrapping store: {0} (shared)" -f $driver)
  & $Testkit run --rm testkit php /workspace/testkit/scripts/store_router.php bootstrap $driver
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Bootstrap-StoreDb([string]$driver, [string]$db) {
  Write-Host ("==> Bootstrapping store: {0} / db={1}" -f $driver, $db)
  Assert-SafeDbName $db
  if ($driver -eq "pgsql") {
    & $Testkit run --rm -e PG_DB=$db -e TEST_PG_DB=$db testkit php /workspace/testkit/scripts/store_router.php bootstrap pgsql
  }
  else {
    & $Testkit run --rm -e DB_NAME=$db -e TEST_MYSQL_DB=$db testkit php /workspace/testkit/scripts/store_router.php bootstrap mysql
  }
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Bootstrap-Driver([string]$driver) {
  $baseDb = Driver-BaseDb $driver
  if ($strategy -eq "per_worker") {
    for ($i = 1; $i -le $jobs; $i++) {
      Bootstrap-StoreDb $driver (Mk-DbName $baseDb $i)
    }
    return
  }

  Bootstrap-StoreShared $driver
}

$configuredDriver = if ($env:TEST_DB_DRIVER) { $env:TEST_DB_DRIVER } else { $env:DB_DRIVER }
if ($configuredDriver) {
  Bootstrap-Driver (Normalize-Driver $configuredDriver)
  exit 0
}

$drivers = New-Object System.Collections.Generic.List[string]
if ($serviceNames -contains 'mysql_test') { $drivers.Add('mysql') }
if ($serviceNames -contains 'postgres_test') { $drivers.Add('pgsql') }
if ($drivers.Count -eq 0) { $drivers.Add('mysql') }

foreach ($driver in $drivers) {
  Bootstrap-Driver $driver
}
