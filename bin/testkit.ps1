Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Base = Join-Path $TestRoot "compose.yaml"
$Pg = Join-Path $TestRoot "compose.pg.yaml"

$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $TestRoot "..") }

function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) {
    return (Resolve-Path $env:TESTKIT_ENV_FILE)
  }
  $a = Join-Path $ProjectRoot "test\.env.test"
  $b = Join-Path $ProjectRoot ".env.test"
  if (Test-Path $a) { return (Resolve-Path $a) }
  if (Test-Path $b) { return (Resolve-Path $b) }
  return $null
}

function EnvFile-ToContainerDbEnvPath([string]$EnvFilePath) {
  # volume: .. -> /app
  $a = (Join-Path $TestRoot ".env.test")
  $b = (Join-Path $RepoRoot ".env.test")
  if ((Test-Path $a) -and ($EnvFilePath -eq (Resolve-Path $a).Path)) { return "/app/test/.env.test" }
  if ((Test-Path $b) -and ($EnvFilePath -eq (Resolve-Path $b).Path)) { return "/app/.env.test" }

  # fallback: relative to repo root
  $rel = $EnvFilePath.Substring($RepoRoot.Path.Length).TrimStart("\\","/")
  return ("/app/" + ($rel -replace "\\","/"))
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

function Port-InUse([int]$Port) {
  try {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
    $listener.Start()
    $listener.Stop()
    return $false
  } catch {
    return $true
  }
}

function Dump-Config([string]$EnvFilePath) {
  Write-Host ""
  Write-Host "-- Effective TestKit config --"
  Write-Host "projectRoot: $ProjectRoot"
  Write-Host "testkitRoot: $TestRoot"
  Write-Host "envFile:  $EnvFilePath"
  Write-Host "DB_ENV_PATH(in-container): $env:TESTKIT_DB_ENV_PATH"
  Write-Host ""
  Write-Host ("TK_BACK_DIR:   {0}" -f ($env:TK_BACK_DIR ? $env:TK_BACK_DIR : "back"))
  Write-Host ("TK_PUBLIC_DIR: {0}" -f ($env:TK_PUBLIC_DIR ? $env:TK_PUBLIC_DIR : "public_html"))
  Write-Host ""
  Write-Host ("TEST_JOBS: {0}" -f ($env:TEST_JOBS ? $env:TEST_JOBS : "1"))
  Write-Host ("TEST_DB_STRATEGY: {0}" -f ($env:TEST_DB_STRATEGY ? $env:TEST_DB_STRATEGY : "shared"))
  Write-Host ("TEST_DB_WORKER_SUFFIX_FORMAT: {0}" -f ($env:TEST_DB_WORKER_SUFFIX_FORMAT ? $env:TEST_DB_WORKER_SUFFIX_FORMAT : "_w%02d"))
  Write-Host ""
}

function Run-Doctor {
  param([switch]$Dump)

  $ok = $true
  Write-Host "== TestKit doctor =="

  $envFile = Pick-EnvFile
  if ($envFile) {
    Write-Host "[OK] env: $envFile"
    Load-EnvKVSafe $envFile.Path
  } else {
    Write-Host "[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root).";
    $ok = $false
  }

  $backDir = $env:TK_BACK_DIR
  if (-not $backDir) { $backDir = "back" }
  $pubDir = $env:TK_PUBLIC_DIR
  if (-not $pubDir) { $pubDir = "public_html" }

  if (Test-Path (Join-Path $ProjectRoot $backDir)) { Write-Host "[OK] $backDir/ (TK_BACK_DIR)" }
  else { Write-Host "[FAIL] falta $ProjectRoot\$backDir (set TK_BACK_DIR en env)"; $ok = $false }

  if (Test-Path (Join-Path $ProjectRoot $pubDir)) { Write-Host "[OK] $pubDir/ (TK_PUBLIC_DIR)" }
  else { Write-Host "[FAIL] falta $ProjectRoot\$pubDir (set TK_PUBLIC_DIR en env)"; $ok = $false }

  $backAutoload = $env:TK_BACK_AUTOLOAD
  if (-not $backAutoload) { $backAutoload = ("{0}\vendor\autoload.php" -f $backDir) }

  if (Test-Path (Join-Path $ProjectRoot $backAutoload)) {
    Write-Host "[OK] back autoload: $backAutoload"
  } elseif ($env:TK_BACK_BOOTSTRAP -and (Test-Path (Join-Path $ProjectRoot $env:TK_BACK_BOOTSTRAP))) {
    Write-Host "[OK] back bootstrap: $($env:TK_BACK_BOOTSTRAP)"
  } else {
    Write-Host "[WARN] no detecté bootstrap de BACK. Si tus tests necesitan cargar código del proyecto, seteá TK_BACK_AUTOLOAD o TK_BACK_BOOTSTRAP."
  }

  $pubAutoload = $env:TK_PUBLIC_AUTOLOAD
  if (-not $pubAutoload) { $pubAutoload = ("{0}\vendor\autoload.php" -f $pubDir) }

  if (Test-Path (Join-Path $ProjectRoot $pubAutoload)) {
    Write-Host "[OK] public autoload: $pubAutoload"
  } elseif ($env:TK_PUBLIC_BOOTSTRAP -and (Test-Path (Join-Path $ProjectRoot $env:TK_PUBLIC_BOOTSTRAP))) {
    Write-Host "[OK] public bootstrap: $($env:TK_PUBLIC_BOOTSTRAP)"
  } else {
    Write-Host "[INFO] no detecté bootstrap de FRONT/PHP (ok si no tenés tests php-front o si son puros)."
  }

  $mysqlPort = [int]($env:TEST_MYSQL_PORT ? $env:TEST_MYSQL_PORT : 33070)
  $pgPort = [int]($env:TEST_PG_PORT ? $env:TEST_PG_PORT : 54370)

  if (Port-InUse $mysqlPort) { Write-Host "[WARN] puerto MySQL ocupado: $mysqlPort (TEST_MYSQL_PORT)" }
  else { Write-Host "[OK] puerto MySQL libre: $mysqlPort" }

  if (Port-InUse $pgPort) { Write-Host "[WARN] puerto Postgres ocupado: $pgPort (TEST_PG_PORT)" }
  else { Write-Host "[OK] puerto Postgres libre: $pgPort" }

  try { docker --version | Out-Null; Write-Host "[OK] docker CLI" }
  catch { Write-Host "[FAIL] docker no está disponible"; $ok = $false }

  try { docker info | Out-Null; Write-Host "[OK] docker daemon" }
  catch { Write-Host "[FAIL] docker daemon no responde (¿Docker Desktop?)"; $ok = $false }

  try { docker compose version | Out-Null; Write-Host "[OK] docker compose v2" }
  catch { Write-Host "[FAIL] docker compose v2 no disponible"; $ok = $false }

  if ($Dump -and $envFile) {
    $env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
$env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path
    Dump-Config $envFile.Path
  }

  if ($ok) { Write-Host "`nDoctor: OK"; exit 0 }
  Write-Error "`nDoctor: FAIL (ver arriba)"
  exit 1
}

if ($Args.Count -gt 0 -and $Args[0] -eq "doctor") {
  $dump = $false
  if ($Args.Count -gt 1 -and $Args[1] -eq "--dump") { $dump = $true }
  Run-Doctor -Dump:$dump
}

$envFile = Pick-EnvFile
if (-not $envFile) {
  Write-Error "Falta env de tests. Copiá test/.env.test.example -> test/.env.test (preferido) o bien creá .env.test en el root del repo."
  exit 1
}

$env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
$env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path

$files = @("-f", $Base)
if ($Args.Count -gt 0 -and $Args[0] -eq "--pg") {
  $files += @("-f", $Pg)
  $Args = $Args[1..($Args.Count-1)]
}

$cmd = @("compose", "--env-file", $envFile) + $files + $Args
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
