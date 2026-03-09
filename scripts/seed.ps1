Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# =============================================================================
# /testkit/scripts/seed.ps1
# Aplica seeds del proyecto dentro del contenedor TestKit.
# Lee SQL desde <project>/test/seeds/{mysql,pgsql}.
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
$baseDb = $env:TEST_MYSQL_DB; if (-not $baseDb) { $baseDb = "app_test" }
$fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT; if (-not $fmt) { $fmt = "_w%02d" }

function Mk-DbName([int]$w) {
  if ($fmt -match '%0(\d+)d') {
    $width = [int]$Matches[1]
    return ($baseDb + "_w" + $w.ToString("D$width"))
  }
  return ($baseDb + "_w" + $w)
}

function Seed-MySqlShared {
  Write-Host "==> Seeding MySQL (shared)…"
  & $Testkit run --rm testkit php scripts/seed_router.php mysql
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Seed-MySqlDb([string]$db) {
  Write-Host ("==> Seeding MySQL DB: {0}" -f $db)
  & $Testkit run --rm -e DB_NAME=$db -e TEST_MYSQL_DB=$db testkit php scripts/seed_router.php mysql
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Seed-PgShared {
  Write-Host "==> Seeding Postgres…"
  & $Testkit run --rm testkit php scripts/seed_router.php pgsql
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if ($strategy -eq "per_worker") {
  for ($i = 1; $i -le $jobs; $i++) { Seed-MySqlDb (Mk-DbName $i) }
} else {
  Seed-MySqlShared
}

$services = & $Testkit ps --services
if ($services -match '(^|?
)postgres_test(?
|$)') {
  Seed-PgShared
} else {
  Write-Host "==> Postgres no activo (ok)."
}
