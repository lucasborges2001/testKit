Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Base = Join-Path $TestRoot "compose.yaml"
$Pg = Join-Path $TestRoot "compose.pg.yaml"

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
      # strip surrounding quotes (simple)
      if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
        $v = $v.Substring(1, $v.Length-2)
      }
      $env:$k = $v
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

function Run-Doctor {
  $ok = $true
  Write-Host "== TestKit doctor =="

  if (Test-Path (Join-Path $RepoRoot "back")) { Write-Host "[OK] back/" }
  else { Write-Host "[FAIL] falta $RepoRoot\back"; $ok = $false }

  if (Test-Path (Join-Path $RepoRoot "public_html")) { Write-Host "[OK] public_html/" }
  else { Write-Host "[FAIL] falta $RepoRoot\public_html"; $ok = $false }

  $envFile = Pick-EnvFile
  if ($envFile) {
    Write-Host "[OK] env: $envFile"
    Load-EnvKVSafe $envFile
    $mysqlPort = [int]($env:TEST_MYSQL_PORT ? $env:TEST_MYSQL_PORT : 33070)
    $pgPort = [int]($env:TEST_PG_PORT ? $env:TEST_PG_PORT : 54370)
    if (Port-InUse $mysqlPort) { Write-Host "[WARN] puerto MySQL ocupado: $mysqlPort (TEST_MYSQL_PORT)" }
    else { Write-Host "[OK] puerto MySQL libre: $mysqlPort" }
    if (Port-InUse $pgPort) { Write-Host "[WARN] puerto Postgres ocupado: $pgPort (TEST_PG_PORT)" }
    else { Write-Host "[OK] puerto Postgres libre: $pgPort" }
  } else {
    Write-Host "[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root)."; $ok = $false
  }

  try { docker --version | Out-Null; Write-Host "[OK] docker CLI" }
  catch { Write-Host "[FAIL] docker no está disponible"; $ok = $false }

  try { docker info | Out-Null; Write-Host "[OK] docker daemon" }
  catch { Write-Host "[FAIL] docker daemon no responde (¿Docker Desktop?)"; $ok = $false }

  try { docker compose version | Out-Null; Write-Host "[OK] docker compose v2" }
  catch { Write-Host "[FAIL] docker compose v2 no disponible"; $ok = $false }

  if ($ok) { Write-Host "`nDoctor: OK"; exit 0 }
  Write-Error "`nDoctor: FAIL (ver arriba)"
  exit 1
}

if ($Args.Count -gt 0 -and $Args[0] -eq "doctor") {
  Run-Doctor
}

$envFile = Pick-EnvFile
if (-not $envFile) {
  Write-Error "Falta env de tests. Copiá test/.env.test.example -> test/.env.test (preferido) o bien creá .env.test en el root del repo."
  exit 1
}

$env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)

$files = @("-f", $Base)
if ($Args.Count -gt 0 -and $Args[0] -eq "--pg") {
  $files += @("-f", $Pg)
  $Args = $Args[1..($Args.Count-1)]
}

$cmd = @("compose", "--env-file", $envFile) + $files + $Args
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
