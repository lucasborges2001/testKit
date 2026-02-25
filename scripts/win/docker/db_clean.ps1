. "$PSScriptRoot\..\_lib.ps1"

# =============================================================================
# test/scripts/win/docker/db_clean.ps1
#
# Reset rápido de MySQL en Docker (mysql_test): TRUNCATE de tablas.
#
# Soporta estrategia per_worker:
#   - (sin args)       limpia el worker 1
#   - --worker <n>     limpia el worker n
#   - --all            limpia todos los workers
# =============================================================================

$P = Get-Paths
$TestRoot = $P.TestRoot
$RepoRoot = $P.RepoRoot

# Env (opcional)
$EnvFile = Pick-EnvFile -TestRoot $TestRoot -RepoRoot $RepoRoot
if ($EnvFile) { Load-EnvKVSafe $EnvFile }

$strategy = Get-DbStrategy
$jobs     = Get-Jobs
$baseDb   = Get-BaseDb
$fmt      = Get-WorkerSuffixFormat

# Args
$mode = 'base'; $worker = 1
if ($Args.Count -ge 2 -and $Args[0] -eq '--worker') {
  $mode = 'worker'
  [int]::TryParse($Args[1], [ref]$worker) | Out-Null
  if ($worker -lt 1) { $worker = 1 }
}
if ($Args.Count -ge 1 -and $Args[0] -eq '--all') { $mode = 'all' }

$Testkit = Resolve-Testkit -TestRoot $TestRoot -RepoRoot $RepoRoot
Assert-Testkit-Windows $Testkit
Set-Location $TestRoot

function Clean-Db([string]$db) {
  Log ("==> Cleaning MySQL DB: {0}" -f $db)

  $tables = & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1" -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\"BASE TABLE\";"' -- $db
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

  if (-not $tables -or ($tables -join '').Trim() -eq '') {
    Log '   (no tables found or DB missing)'
    return
  }

  $sql = "SET FOREIGN_KEY_CHECKS=0;`n"
  foreach ($t in $tables) {
    $tt = $t.Trim()
    if ($tt -match '^[A-Za-z0-9_]+$') { $sql += "TRUNCATE TABLE ``$tt``;`n" }
  }
  $sql += "SET FOREIGN_KEY_CHECKS=1;`n"

  $sql | & $Testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- $db
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if ($strategy -eq 'per_worker') {
  if ($mode -eq 'all') {
    for ($i = 1; $i -le $jobs; $i++) { Clean-Db (Mk-DbName -baseDb $baseDb -fmt $fmt -w $i) }
  }
  elseif ($mode -eq 'worker') {
    Clean-Db (Mk-DbName -baseDb $baseDb -fmt $fmt -w $worker)
  }
  else {
    Clean-Db (Mk-DbName -baseDb $baseDb -fmt $fmt -w 1)
  }
}
else {
  Clean-Db $baseDb
}
