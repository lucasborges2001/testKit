Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$RepoRoot = Resolve-Path (Join-Path $TestRoot "..")
$Testkit = Join-Path $TestRoot "bin/testkit.ps1"

Set-Location $TestRoot

function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) {
    return (Resolve-Path $env:TESTKIT_ENV_FILE)
  }
  $a = Join-Path $TestRoot ".env.test"
  $b = Join-Path $RepoRoot ".env.test"
  if (Test-Path $a) { return (Resolve-Path $a) }
  if (Test-Path $b) { return (Resolve-Path $b) }
  return $null
}

function Load-EnvKVSafe([string]$Path) {
  if (-not (Test-Path $Path)) { return }
  Get-Content $Path | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq "" -or $line.StartsWith("#")) { return }
    if ($line -match '^[A-Za-z_][A-Za-z0-9_]*=') {
      $pair = $line.Split('=',2)
      $k = $pair[0]
      $v = $pair[1]
      if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
        $v = $v.Substring(1, $v.Length-2)
      }
      Set-Item -Path ("Env:{0}" -f $k) -Value $v
    }
  }
}

$envFile = Pick-EnvFile
if ($envFile) { Load-EnvKVSafe $envFile.Path }

$strategy = $env:TEST_DB_STRATEGY
if (-not $strategy) { $strategy = "shared" }

$jobs = [int]($env:TEST_JOBS ? $env:TEST_JOBS : 1)
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

function Seed-OneDb([string]$db) {
  Write-Host "==> Seeding MySQL DB: $db"

  ("CREATE DATABASE IF NOT EXISTS {0} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $db) |
    & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

  $mysqlDir = Join-Path $TestRoot "seeds/mysql"
  if (Test-Path $mysqlDir) {
    Get-ChildItem -Path $mysqlDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
      Write-Host "   - $($_.Name)"
      $sql = Get-Content -Raw $_.FullName
      $sql | & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- $db
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  }
}

if ($strategy -eq "per_worker") {
  for ($i=1; $i -le $jobs; $i++) {
    Seed-OneDb (Mk-DbName $i)
  }
} else {
  Write-Host "==> Seeding MySQL…"
  $mysqlDir = Join-Path $TestRoot "seeds/mysql"
  if (Test-Path $mysqlDir) {
    Get-ChildItem -Path $mysqlDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
      Write-Host "   - $($_.Name)"
      $sql = Get-Content -Raw $_.FullName
      $sql | & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  }
}

# Postgres opcional
$services = & $Testkit ps --services
if ($services -match '(^|\r?\n)postgres_test(\r?\n|$)') {
  Write-Host "==> Seeding Postgres…"
  $pgDir = Join-Path $TestRoot "seeds/pgsql"
  if (Test-Path $pgDir) {
    Get-ChildItem -Path $pgDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
      Write-Host "   - $($_.Name)"
      $sql = Get-Content -Raw $_.FullName
      $sql | & $Testkit exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -'
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  }
} else {
  Write-Host "==> Postgres no activo (ok). Levantar con: .\bin\testkit.ps1 --pg up -d"
}
