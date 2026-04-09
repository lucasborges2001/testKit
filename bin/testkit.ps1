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
$ResolvedTestkitRoot = if ($env:TESTKIT_ROOT) { Resolve-Path $env:TESTKIT_ROOT } else { $TestRoot }
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
  if ([string]::IsNullOrWhiteSpace($Raw)) {
    $Raw = $fallback
  }

  $out = New-Object System.Collections.Generic.List[string]
  $seen = @{}

  foreach ($part in ($Raw -split ',')) {
    $token = $part.Trim().ToLowerInvariant()
    if ([string]::IsNullOrWhiteSpace($token)) { continue }

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
      $out.Add($token)
    }
  }

  if ($out.Count -eq 0) {
    $out.Add('mysql')
    $out.Add('redis')
  }

  return ($out -join ',')
}

function Stack-Has([string]$Csv, [string]$Token) {
  return (",$Csv,").Contains(",$Token,")
}

function Resolve-ComposeFiles([string]$StackCsv) {
  $files = New-Object System.Collections.Generic.List[string]
  $files.Add('-f')
  $files.Add($Base)

  if (Stack-Has $StackCsv 'mysql') {
    $files.Add('-f')
    $files.Add($Mysql)
  }
  if (Stack-Has $StackCsv 'redis') {
    $files.Add('-f')
    $files.Add($Redis)
  }
  if (Stack-Has $StackCsv 'pg') {
    $files.Add('-f')
    $files.Add($Pg)
  }

  return ,$files.ToArray()
}

function Rewrite-RunCommandArgs([string[]]$InputArgs) {
  if (-not $InputArgs -or $InputArgs.Count -eq 0) { return ,$InputArgs }
  if ($InputArgs[0] -ne 'run') { return ,$InputArgs }

  $rewritten = @($InputArgs)
  $sawTestkit = $false

  for ($i = 0; $i -lt $rewritten.Count; $i++) {
    if ($rewritten[$i] -eq 'testkit') {
      $sawTestkit = $true
      continue
    }

    if ($sawTestkit -and @('runTest.php', './runTest.php', '/workspace/project/runTest.php', '/workspace/testkit/runTest.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/runTest.php'
      continue
    }

    if ($sawTestkit -and @('scripts/report.php', './scripts/report.php', '/workspace/project/scripts/report.php', '/workspace/testkit/scripts/report.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/scripts/report.php'
      continue
    }

    if ($sawTestkit -and @('scripts/query_report.php', './scripts/query_report.php', '/workspace/project/scripts/query_report.php', '/workspace/testkit/scripts/query_report.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/scripts/query_report.php'
      continue
    }

    if ($sawTestkit -and @('runners/runTest.php', './runners/runTest.php', '/workspace/project/runners/runTest.php', '/workspace/testkit/runners/runTest.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/runners/runTest.php'
      continue
    }
  }

  return ,$rewritten
}

function Dump-Config([string]$EnvFilePath, [string]$StackCsv) {
  Write-Host ""
  Write-Host "-- Effective TestKit config --"
  Write-Host "projectRoot: $ProjectRoot"
  Write-Host "testkitRootHost: $ResolvedTestkitRoot"
  Write-Host "envFile:  $EnvFilePath"
  Write-Host "DB_ENV_PATH(in-container): $env:TESTKIT_DB_ENV_PATH"
  Write-Host ""
  Write-Host "TESTKIT_STACK: $StackCsv"
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

  $stackCsv = Normalize-StackCsv $env:TESTKIT_STACK
  Write-Host "[INFO] TESTKIT_STACK=$stackCsv"
  Write-Host "[INFO] TESTKIT_ROOT(host)=$ResolvedTestkitRoot"

  if (-not (Test-Path $ResolvedTestkitRoot)) {
    Write-Host "[FAIL] TESTKIT_ROOT no existe o no es directorio: $ResolvedTestkitRoot"
    $ok = $false
  } elseif (-not (Test-Path (Join-Path $ResolvedTestkitRoot 'runTest.php'))) {
    Write-Host "[FAIL] TESTKIT_ROOT no parece repo completo: falta runTest.php en $ResolvedTestkitRoot"
    $ok = $false
  } else {
    Write-Host "[OK] TESTKIT_ROOT: $ResolvedTestkitRoot"
  }

  if ($Dump -and $envFile) {
    $env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
    $env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path
    $env:TESTKIT_ROOT = $ResolvedTestkitRoot.Path
    Dump-Config $envFile.Path $stackCsv
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

$stackCsv = Normalize-StackCsv $env:TESTKIT_STACK
if ($legacyPgFlag -and -not (Stack-Has $stackCsv 'pg')) {
  $stackCsv = "$stackCsv,pg"
}

$env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
$env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path
$env:TESTKIT_ROOT = $ResolvedTestkitRoot.Path

$files = Resolve-ComposeFiles $stackCsv
$runArgs = Rewrite-RunCommandArgs $Args

$cmd = @("compose", "--env-file", $envFile) + $files + $runArgs
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
