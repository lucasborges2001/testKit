Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Testkit = Join-Path $TestRoot "bin/testkit.ps1"

Set-Location $TestRoot

Write-Host "==> Seeding MySQL…"
$mysqlDir = Join-Path $TestRoot "seeds/mysql"
if (Test-Path $mysqlDir) {
  Get-ChildItem -Path $mysqlDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
    Write-Host "   - $($_.Name)"
    $sql = Get-Content -Raw $_.FullName
    $sql | & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"'
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
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
      $sql | & $Testkit exec -T postgres_test sh -lc 'PGPASSWORD="${POSTGRES_PASSWORD}" psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -f -'
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
  }
} else {
  Write-Host "==> Postgres no activo (ok). Levantar con: .\bin\testkit.ps1 --pg up -d"
}
