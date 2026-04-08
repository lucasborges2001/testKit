Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Base = Join-Path $TestRoot "compose.yaml"
$Pg = Join-Path $TestRoot "compose.pg.yaml"

$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $TestRoot "..") }
$DoctorDockerMode = if ($env:TESTKIT_DOCTOR_DOCKER_MODE) { $env:TESTKIT_DOCTOR_DOCKER_MODE } else { 'auto' }

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
  $projectRootPath = (Resolve-Path $ProjectRoot).Path
  $envFileResolved = (Resolve-Path $EnvFilePath).Path

  $a = Join-Path $ProjectRoot "test\.env.test"
  $b = Join-Path $ProjectRoot ".env.test"
  if ((Test-Path $a) -and ($envFileResolved -eq (Resolve-Path $a).Path)) { return "/workspace/project/test/.env.test" }
  if ((Test-Path $b) -and ($envFileResolved -eq (Resolve-Path $b).Path)) { return "/workspace/project/.env.test" }

  # fallback: relative to project root
  if ($envFileResolved.StartsWith($projectRootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
    $rel = $envFileResolved.Substring($projectRootPath.Length) -replace '^[\\/]+', ''
    return ("/workspace/project/" + ($rel -replace "\\","/"))
  }

  # final fallback: canonical path
  return "/workspace/project/test/.env.test"
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

function Get-OrDefault([string]$Value, [string]$DefaultValue) {
  if ([string]::IsNullOrWhiteSpace($Value)) {
    return $DefaultValue
  }
  return $Value
}

function Dump-Config([string]$EnvFilePath) {
  $tkBackDir = Get-OrDefault $env:TK_BACK_DIR "back"
  $tkPublicDir = Get-OrDefault $env:TK_PUBLIC_DIR "public_html"
  $testJobs = Get-OrDefault $env:TEST_JOBS "1"
  $testDbStrategy = Get-OrDefault $env:TEST_DB_STRATEGY "shared"
  $testDbWorkerSuffixFormat = Get-OrDefault $env:TEST_DB_WORKER_SUFFIX_FORMAT "_w%02d"

  Write-Host ""
  Write-Host "-- Effective TestKit config --"
  Write-Host "projectRoot: $ProjectRoot"
  Write-Host "testkitRoot: $TestRoot"
  Write-Host "envFile:  $EnvFilePath"
  Write-Host "DB_ENV_PATH(in-container): $env:TESTKIT_DB_ENV_PATH"
  Write-Host ""
  Write-Host ("TK_BACK_DIR:   {0}" -f $tkBackDir)
  Write-Host ("TK_PUBLIC_DIR: {0}" -f $tkPublicDir)
  Write-Host ""
  Write-Host ("TEST_JOBS: {0}" -f $testJobs)
  Write-Host ("TEST_DB_STRATEGY: {0}" -f $testDbStrategy)
  Write-Host ("TEST_DB_WORKER_SUFFIX_FORMAT: {0}" -f $testDbWorkerSuffixFormat)
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
  else { Write-Host "[INFO] carpeta $backDir/ no detectada. Esto es normal si tu layout es distinto." }

  if (Test-Path (Join-Path $ProjectRoot $pubDir)) { Write-Host "[OK] $pubDir/ (TK_PUBLIC_DIR)" }
  else { Write-Host "[INFO] carpeta $pubDir/ no detectada. (Opcional)" }

  if (Test-Path (Join-Path $ProjectRoot 'test')) { Write-Host "[OK] test/ del proyecto" }
  else { Write-Host "[FAIL] falta $($ProjectRoot.Path)\test"; $ok = $false }

  $suiteRoots = @{
    'TK_BACK_PHP_DIR' = (Get-OrDefault $env:TK_BACK_PHP_DIR 'test/back')
    'TK_BACK_PYTHON_DIR' = (Get-OrDefault $env:TK_BACK_PYTHON_DIR 'test/back')
    'TK_FRONT_PHP_DIR' = (Get-OrDefault $env:TK_FRONT_PHP_DIR 'test/front')
    'TK_FRONT_JS_DIR' = (Get-OrDefault $env:TK_FRONT_JS_DIR 'test/front')
  }
  foreach ($entry in $suiteRoots.GetEnumerator()) {
    $resolved = Join-Path $ProjectRoot $entry.Value
    if (Test-Path $resolved) {
      if ((Get-Item Env:$($entry.Key) -ErrorAction SilentlyContinue)) { Write-Host "[OK] $($entry.Key): $($entry.Value) (override)" }
      else { Write-Host "[OK] $($entry.Key): $($entry.Value) (default)" }
    } else {
      Write-Host "[WARN] $($entry.Key): ruta no detectada: $($entry.Value). (Opcional)"
    }
  }

  $targetOverrides = Get-ChildItem Env: | Where-Object { $_.Name -like 'TESTKIT_TARGET_*' }
  if ($targetOverrides) {
    $names = ($targetOverrides | Select-Object -ExpandProperty Name) -join ','
    Write-Host "[INFO] targets personalizados detectados: $names"
  }

  if ($env:TK_MODULE_LEVEL) { Write-Host "[OK] TK_MODULE_LEVEL: $($env:TK_MODULE_LEVEL) (override)" }
  if ($env:TK_TAG_MAP) { Write-Host "[OK] TK_TAG_MAP: $($env:TK_TAG_MAP) (override)" }

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

  Write-Host "[INFO] TEST_DB_STRATEGY=$(Get-OrDefault $env:TEST_DB_STRATEGY 'shared') TEST_JOBS=$(Get-OrDefault $env:TEST_JOBS '1')"

  $testOutDir = Join-Path $ProjectRoot 'test'
  New-Item -ItemType Directory -Force -Path $testOutDir | Out-Null
  try {
    $probe = Join-Path $testOutDir '.testkit_write_probe.tmp'
    Set-Content -Path $probe -Value 'ok' -NoNewline
    Remove-Item -Force $probe
    Write-Host "[OK] $testOutDir writable"
  } catch {
    Write-Host "[FAIL] $testOutDir no es escribible"
    $ok = $false
  }


  $mysqlPort = [int](Get-OrDefault $env:TEST_MYSQL_PORT "33070")
  $pgPort = [int](Get-OrDefault $env:TEST_PG_PORT "54370")

  if (Port-InUse $mysqlPort) { Write-Host "[WARN] puerto MySQL ocupado: $mysqlPort (TEST_MYSQL_PORT)" }
  else { Write-Host "[OK] puerto MySQL libre: $mysqlPort" }

  if (Port-InUse $pgPort) { Write-Host "[WARN] puerto Postgres ocupado: $pgPort (TEST_PG_PORT)" }
  else { Write-Host "[OK] puerto Postgres libre: $pgPort" }

  $dockerRequired = $false
  if ($DoctorDockerMode -match '^(1|docker|required|strict)$') { $dockerRequired = $true }

  $dockerCliOk = $false
  try { docker --version | Out-Null; Write-Host "[OK] docker CLI"; $dockerCliOk = $true }
  catch {
    if ($dockerRequired) { Write-Host "[FAIL] docker no está disponible"; $ok = $false }
    else { Write-Host "[WARN] docker no está disponible (ok en flujo local)" }
  }

  if ($dockerCliOk) {
    try { docker info | Out-Null; Write-Host "[OK] docker daemon" }
    catch {
      if ($dockerRequired) { Write-Host "[FAIL] docker daemon no responde (¿Docker Desktop?)"; $ok = $false }
      else { Write-Host "[WARN] docker daemon no responde (ok si vas a correr local)" }
    }

    try { docker compose version | Out-Null; Write-Host "[OK] docker compose v2" }
    catch {
      if ($dockerRequired) { Write-Host "[FAIL] docker compose v2 no disponible"; $ok = $false }
      else { Write-Host "[WARN] docker compose v2 no disponible (ok si no usás flujo Docker)" }
    }
  }

  try { php -r "echo PHP_VERSION;" | ForEach-Object { if ($_){ Write-Host "[INFO] php local: $_" } } } catch {}
  try { node -v | ForEach-Object { if ($_){ Write-Host "[INFO] node local: $_" } } } catch {}


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
  $Args = if ($Args.Count -gt 1) { $Args[1..($Args.Count-1)] } else { @() }
}

$cmd = @("compose", "--env-file", $envFile) + $files + $Args
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
