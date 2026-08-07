Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $TestRoot "..") }

function Resolve-Testkit {
  $override = $env:TESTKIT_BIN
  if ($override -and (Test-Path $override)) { return (Resolve-Path $override).Path }

  $candidates = @(
    $(Join-Path $TestRoot "bin/testkit.ps1"),
    $(Join-Path $ProjectRoot "bin/testkit.ps1"),
    $(Join-Path $TestRoot "bin/testkit"),
    $(Join-Path $ProjectRoot "bin/testkit")
  )

  foreach ($c in $candidates) {
    if (Test-Path $c) { return (Resolve-Path $c).Path }
  }

  throw "No se encontró TestKit. Seteá TESTKIT_BIN o agregá bin/testkit(.ps1)."
}

function Test-IsWindowsHost {
  $isWindowsVar = Get-Variable -Name IsWindows -ErrorAction SilentlyContinue
  if ($null -ne $isWindowsVar) { return [bool]$isWindowsVar.Value }
  return ([System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Win32NT)
}

$Testkit = Resolve-Testkit
if ((Test-IsWindowsHost) -and -not $Testkit.ToLowerInvariant().EndsWith(".ps1")) {
  throw "En Windows se espera bin/testkit.ps1. Si solo tenés bin/testkit (bash), usá los .sh."
}
Set-Location $TestRoot

function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) { return (Resolve-Path $env:TESTKIT_ENV_FILE).Path }
  $a = Join-Path $ProjectRoot "test\.env.test"
  $b = Join-Path $ProjectRoot ".env.test"
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

$driver = $env:TEST_STORE_DRIVER
if ([string]::IsNullOrEmpty($driver)) {
  [Console]::Error.WriteLine("[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Usá mysql|pgsql|none.")
  exit 2
}
if ($driver -cnotin @('mysql','pgsql','none')) {
  [Console]::Error.WriteLine("[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='$driver' no es válido. Valores exactos: mysql|pgsql|none.")
  exit 2
}
if ($driver -eq 'none') {
  Write-Host '==> TEST_STORE_DRIVER=none: limpieza estructural no aplica.'
  exit 0
}

$strategy = $env:TEST_DB_STRATEGY; if (-not $strategy) { $strategy = "shared" }
$jobs = 1
if ($env:TEST_JOBS) { [int]::TryParse($env:TEST_JOBS, [ref]$jobs) | Out-Null }
if ($jobs -lt 1) { $jobs = 1 }

$baseDb = $env:TEST_MYSQL_DB; if (-not $baseDb) { $baseDb = $env:DB_NAME }; if (-not $baseDb) { $baseDb = "app_test" }
if ($driver -eq "pgsql") {
  $baseDb = $env:TEST_PG_DB
  if (-not $baseDb) { $baseDb = $env:PG_DB }
  if (-not $baseDb) { $baseDb = "app_test" }
}
$fmt = $env:TEST_DB_WORKER_SUFFIX_FORMAT; if (-not $fmt) { $fmt = "_w%02d" }

$mode = "base"; $worker = 1
if ($Args.Count -ge 2 -and $Args[0] -eq "--worker") {
  $mode = "worker"
  [int]::TryParse($Args[1], [ref]$worker) | Out-Null
  if ($worker -lt 1) { $worker = 1 }
}
if ($Args.Count -ge 1 -and $Args[0] -eq "--all") { $mode = "all" }

function Mk-DbName([int]$w) {
  if ($fmt -match '%0(\d+)d') {
    $width = [int]$Matches[1]
    return ($baseDb + "_w" + $w.ToString("D$width"))
  }
  return ($baseDb + "_w" + $w)
}

function Clean-Db([string]$db) {
  Write-Host ("==> Cleaning {0} DB: {1}" -f $driver, $db)
  if ($driver -eq "pgsql") {
    & $Testkit run --rm -e TEST_STORE_DRIVER=pgsql -e PG_DB=$db -e TEST_PG_DB=$db testkit php /workspace/testkit/scripts/store_router.php clean pgsql
  }
  else {
    & $Testkit run --rm -e TEST_STORE_DRIVER=mysql -e DB_NAME=$db -e TEST_MYSQL_DB=$db testkit php /workspace/testkit/scripts/store_router.php clean mysql
  }
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if ($strategy -eq "per_worker") {
  if ($mode -eq "all") {
    for ($i = 1; $i -le $jobs; $i++) { Clean-Db (Mk-DbName $i) }
  }
  elseif ($mode -eq "worker") { Clean-Db (Mk-DbName $worker) }
  else { Clean-Db (Mk-DbName 1) }
}
else {
  Clean-Db $baseDb
}
