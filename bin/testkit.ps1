Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$EnvFile = Join-Path $TestRoot ".env.test"
$Base = Join-Path $TestRoot "compose.yaml"
$Pg = Join-Path $TestRoot "compose.pg.yaml"

$RepoRoot = Resolve-Path (Join-Path $TestRoot "..")

function Run-Doctor {
  $ok = $true
  Write-Host "== TestKit doctor =="

  if (Test-Path (Join-Path $RepoRoot "back")) { Write-Host "[OK] back/" }
  else { Write-Host "[FAIL] falta $RepoRoot\back"; $ok = $false }

  if (Test-Path (Join-Path $RepoRoot "public_html")) { Write-Host "[OK] public_html/" }
  else { Write-Host "[FAIL] falta $RepoRoot\public_html"; $ok = $false }

  if (Test-Path $EnvFile) { Write-Host "[OK] $EnvFile" }
  else { Write-Host "[FAIL] falta $EnvFile (copiá .env.test.example)"; $ok = $false }

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

if (-not (Test-Path $EnvFile)) {
  Write-Error "Falta $EnvFile. Copiá test/.env.test.example -> test/.env.test"
  exit 1
}

$files = @("-f", $Base)
if ($Args.Count -gt 0 -and $Args[0] -eq "--pg") {
  $files += @("-f", $Pg)
  $Args = $Args[1..($Args.Count-1)]
}

$cmd = @("compose", "--env-file", $EnvFile) + $files + $Args
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
