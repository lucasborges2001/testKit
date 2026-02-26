Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Base = Join-Path $TestRoot "compose.yaml"
$Pg   = Join-Path $TestRoot "compose.pg.yaml"
$RepoRoot = Resolve-Path (Join-Path $TestRoot "..")

function Pick-EnvFile {
  if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) {
    return (Resolve-Path $env:TESTKIT_ENV_FILE)
  }
  $a = Join-Path $TestRoot ".env.test"      # <repo>\test\.env.test
  $b = Join-Path $RepoRoot ".env.test"      # <repo>\.env.test
  if (Test-Path $a) { return (Resolve-Path $a) }
  if (Test-Path $b) { return (Resolve-Path $b) }
  return $null
}

function EnvFile-ToContainerDbEnvPath([string]$EnvFilePath) {
  $a = (Join-Path $TestRoot ".env.test")
  $b = (Join-Path $RepoRoot ".env.test")
  if ((Test-Path $a) -and ($EnvFilePath -eq (Resolve-Path $a).Path)) { return "/app/test/.env.test" }
  if ((Test-Path $b) -and ($EnvFilePath -eq (Resolve-Path $b).Path)) { return "/app/.env.test" }

  $rel = $EnvFilePath.Substring($RepoRoot.Path.Length).TrimStart("\\","/")
  return ("/app/" + ($rel -replace "\\","/"))
}

function Env-Truthy([string]$v) {
  if (-not $v) { return $false }
  $s = $v.Trim().ToLowerInvariant()
  return ($s -in @('1','true','yes','y','on'))
}

function Detect-ProjectName {
  if ($env:TESTKIT_PROJECT) { return $env:TESTKIT_PROJECT }
  if ($env:COMPOSE_PROJECT_NAME) { return $env:COMPOSE_PROJECT_NAME }
  return (Split-Path -Leaf $TestRoot.Path)
}

function Pg-Running([string]$Project) {
  try {
    $names = docker ps --format "{{.Names}}" 2>$null
    if (-not $names) { return $false }
    foreach ($n in $names) {
      if ($n -match "^$([Regex]::Escape($Project))-(postgres_test)-") { return $true }
    }
  } catch {}
  return $false
}

function Print-Help {
  Write-Host @"
TestKit (Windows)

Uso:
  .\bin\testkit.ps1 doctor
  .\bin\testkit.ps1 [pg|nopg] <docker compose args...>

Atajos:
  pg    = incluye compose.pg.yaml (Postgres)
  nopg  = fuerza modo sin Postgres

Modo auto (default):
  - Si postgres_test está corriendo, incluye compose.pg.yaml automáticamente.
  - Podés forzar default con TESTKIT_DEFAULT_PG=1

Ejemplos:
  .\bin\testkit.ps1 up -d
  .\bin\testkit.ps1 pg up -d
  .\bin\testkit.ps1 run --rm testkit php runTest.php back
  .\bin\testkit.ps1 pg run --rm testkit php runTest.php
"@
}

function Run-Doctor {
  # Reusa el doctor existente del repo si existe, si no, hace checks mínimos.
  Write-Host "== TestKit doctor =="

  $envFile = Pick-EnvFile
  if ($envFile) {
    Write-Host ("[OK] env: {0}" -f $envFile.Path)
  } else {
    Write-Host "[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root)."
    exit 1
  }

  try { docker --version | Out-Null; Write-Host "[OK] docker CLI" } catch { Write-Host "[FAIL] docker"; exit 1 }
  try { docker info | Out-Null; Write-Host "[OK] docker daemon" } catch { Write-Host "[FAIL] docker daemon"; exit 1 }
  try { docker compose version | Out-Null; Write-Host "[OK] docker compose v2" } catch { Write-Host "[FAIL] docker compose"; exit 1 }

  Write-Host "`nDoctor: OK"
  exit 0
}

if ($Args.Count -eq 0 -or $Args[0] -in @('-h','--help','help')) {
  Print-Help
  exit 0
}

if ($Args.Count -gt 0 -and $Args[0] -eq 'doctor') {
  Run-Doctor
}

$envFile = Pick-EnvFile
if (-not $envFile) {
  Write-Error "Falta env de tests. Copiá test/.env.test.example -> test/.env.test (preferido) o bien creá .env.test en el root del repo."
  exit 1
}

$env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)

# Mode selection
$modePg = Env-Truthy $env:TESTKIT_DEFAULT_PG
if ($Args.Count -gt 0) {
  switch ($Args[0]) {
    '--pg' { $modePg = $true;  $Args = @($Args | Select-Object -Skip 1) }
    'pg'   { $modePg = $true;  $Args = @($Args | Select-Object -Skip 1) }
    '--nopg' { $modePg = $false; $Args = @($Args | Select-Object -Skip 1) }
    'nopg'   { $modePg = $false; $Args = @($Args | Select-Object -Skip 1) }
  }
}

# Auto-PG
if (-not $modePg -and -not (Env-Truthy $env:TESTKIT_DISABLE_AUTO_PG)) {
  $proj = Detect-ProjectName
  if (Pg-Running $proj) { $modePg = $true }
}

$files = @('-f', $Base)
if ($modePg) { $files += @('-f', $Pg) }

$cmd = @('compose','--env-file',$envFile) + $files + $Args
& docker @cmd
exit $LASTEXITCODE
