Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestRoot = Resolve-Path (Join-Path $Here "..")
$Base = Join-Path $TestRoot "compose.yaml"
$Mysql = Join-Path $TestRoot "compose.mysql.yaml"
$Redis = Join-Path $TestRoot "compose.redis.yaml"
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

  if ($envFileResolved.StartsWith($projectRootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
    $rel = $envFileResolved.Substring($projectRootPath.Length) -replace '^[\\/]+', ''
    return ("/workspace/project/" + ($rel -replace "\\","/"))
  }

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

function Normalize-StackCsv([string]$Raw) {
  $fallback = 'mysql,redis'
  if ([string]::IsNullOrWhiteSpace($Raw)) { $Raw = $fallback }

  $out = New-Object System.Collections.Generic.List[string]
  $seen = @{}
  foreach ($part in ($Raw -split ',')) {
    $token = $part.Trim().ToLowerInvariant()
    if ($token -eq '') { continue }
    switch ($token) {
      'mysql' {}
      'redis' {}
      'pg' {}
      'postgres' { $token = 'pg' }
      'postgresql' { $token = 'pg' }
      default { throw "TESTKIT_STACK inválido: token no reconocido '$token'. Valores válidos: mysql, redis, pg" }
    }
    if (-not $seen.ContainsKey($token)) {
      $seen[$token] = $true
      [void]$out.Add($token)
    }
  }

  if ($out.Count -eq 0) {
    [void]$out.Add('mysql')
    [void]$out.Add('redis')
  }
  return ($out -join ',')
}

function Stack-Has([string]$Csv, [string]$Token) {
  return (",$Csv,").Contains(",$Token,")
}

function Resolve-ComposeFiles([string]$StackCsv) {
  $files = @("-f", $Base)
  if (Stack-Has $StackCsv 'mysql') { $files += @("-f", $Mysql) }
  if (Stack-Has $StackCsv 'redis') { $files += @("-f", $Redis) }
  if (Stack-Has $StackCsv 'pg') { $files += @("-f", $Pg) }
  return ,$files
}

function Rewrite-TestkitEntrypoints([string[]]$InArgs) {
  if (-not $InArgs -or $InArgs.Count -eq 0) { return ,$InArgs }
  if ($InArgs[0] -ne 'run') { return ,$InArgs }

  $serviceIndex = -1
  $i = 1
  while ($i -lt $InArgs.Count) {
    $arg = $InArgs[$i]
    switch ($arg) {
      '--rm' { $i += 1; continue }
      '--no-deps' { $i += 1; continue }
      '--service-ports' { $i += 1; continue }
      '--use-aliases' { $i += 1; continue }
      '--quiet-pull' { $i += 1; continue }
      '-T' { $i += 1; continue }
      '-i' { $i += 1; continue }
      '-e' { $i += 2; continue }
      '--env' { $i += 2; continue }
      '--env-from-file' { $i += 2; continue }
      '-l' { $i += 2; continue }
      '--label' { $i += 2; continue }
      '-p' { $i += 2; continue }
      '--publish' { $i += 2; continue }
      '-v' { $i += 2; continue }
      '--volume' { $i += 2; continue }
      '-w' { $i += 2; continue }
      '--workdir' { $i += 2; continue }
      '-u' { $i += 2; continue }
      '--user' { $i += 2; continue }
      '--entrypoint' { $i += 2; continue }
      '--name' { $i += 2; continue }
      default {
        if ($arg.StartsWith('-')) {
          $i += 1
          continue
        }
        $serviceIndex = $i
        break
      }
    }
  }

  if ($serviceIndex -lt 0) { return ,$InArgs }
  if ($InArgs[$serviceIndex] -ne 'testkit') { return ,$InArgs }

  $out = @($InArgs)
  for ($j = $serviceIndex + 1; $j -lt $out.Count; $j++) {
    switch ($out[$j]) {
      'runTest.php' { $out[$j] = '/workspace/testkit/runTest.php' }
      './runTest.php' { $out[$j] = '/workspace/testkit/runTest.php' }
      'runners/runTest.php' { $out[$j] = '/workspace/testkit/runners/runTest.php' }
      './runners/runTest.php' { $out[$j] = '/workspace/testkit/runners/runTest.php' }
      'scripts/report.php' { $out[$j] = '/workspace/testkit/scripts/report.php' }
      './scripts/report.php' { $out[$j] = '/workspace/testkit/scripts/report.php' }
      'scripts/query_report.php' { $out[$j] = '/workspace/testkit/scripts/query_report.php' }
      './scripts/query_report.php' { $out[$j] = '/workspace/testkit/scripts/query_report.php' }
    }
  }

  return ,$out
}

function Dump-Config([string]$EnvFilePath) {
  Write-Host ""
  Write-Host "-- Effective TestKit config --"
  Write-Host "projectRoot: $ProjectRoot"
  Write-Host "testkitRoot: $TestRoot"
  Write-Host "envFile:  $EnvFilePath"
  Write-Host "DB_ENV_PATH(in-container): $env:TESTKIT_DB_ENV_PATH"
  Write-Host ""
  Write-Host "TESTKIT_STACK: $env:TESTKIT_STACK_EFFECTIVE"
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
    Write-Host "[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root)."
    $ok = $false
  }

  $env:TESTKIT_STACK_EFFECTIVE = Normalize-StackCsv $env:TESTKIT_STACK
  Write-Host "[INFO] TESTKIT_STACK=$($env:TESTKIT_STACK_EFFECTIVE)"

  if (Test-Path (Join-Path $ProjectRoot 'test')) { Write-Host "[OK] test/ del proyecto" }
  else { Write-Host "[FAIL] falta $($ProjectRoot.Path)\test"; $ok = $false }

  try { docker --version | Out-Null; Write-Host "[OK] docker CLI" } catch {
    if ($DoctorDockerMode -match '^(1|docker|required|strict)$') { $ok = $false }
  }

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

Load-EnvKVSafe $envFile.Path

$legacyPgFlag = $false
if ($Args.Count -gt 0 -and $Args[0] -eq "--pg") {
  $legacyPgFlag = $true
  $Args = if ($Args.Count -gt 1) { $Args[1..($Args.Count-1)] } else { @() }
}

$env:TESTKIT_STACK_EFFECTIVE = Normalize-StackCsv $env:TESTKIT_STACK
if ($legacyPgFlag -and -not (Stack-Has $env:TESTKIT_STACK_EFFECTIVE 'pg')) {
  $env:TESTKIT_STACK_EFFECTIVE = "$($env:TESTKIT_STACK_EFFECTIVE),pg"
}

$env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
$env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path

$files = Resolve-ComposeFiles $env:TESTKIT_STACK_EFFECTIVE
$rewrittenArgs = Rewrite-TestkitEntrypoints $Args

$cmd = @("compose", "--env-file", $envFile) + $files + $rewrittenArgs
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
