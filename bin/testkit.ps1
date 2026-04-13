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

    if ($sawTestkit -and @('scripts/inspect.php', './scripts/inspect.php', '/workspace/project/scripts/inspect.php', '/workspace/testkit/scripts/inspect.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/scripts/inspect.php'
      continue
    }

    if ($sawTestkit -and @('scripts/agent-run.php', './scripts/agent-run.php', '/workspace/project/scripts/agent-run.php', '/workspace/testkit/scripts/agent-run.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/scripts/agent-run.php'
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
  $backDir = if ($env:TK_BACK_DIR) { $env:TK_BACK_DIR } else { 'back' }
  $publicDir = if ($env:TK_PUBLIC_DIR) { $env:TK_PUBLIC_DIR } else { 'public_html' }
  Write-Host "TK_BACK_DIR:   $backDir"
  Write-Host "TK_PUBLIC_DIR: $publicDir"
  Write-Host ""
  $testJobs = if ($env:TEST_JOBS) { $env:TEST_JOBS } else { '1' }
  $dbStrategy = if ($env:TEST_DB_STRATEGY) { $env:TEST_DB_STRATEGY } else { 'shared' }
  $workerSuffix = if ($env:TEST_DB_WORKER_SUFFIX_FORMAT) { $env:TEST_DB_WORKER_SUFFIX_FORMAT } else { '_w%02d' }
  $storeProvision = if ($env:TEST_STORE_PROVISION) { $env:TEST_STORE_PROVISION } else { 'managed' }
  Write-Host "TEST_JOBS: $testJobs"
  Write-Host "TEST_DB_STRATEGY: $dbStrategy"
  Write-Host "TEST_DB_WORKER_SUFFIX_FORMAT: $workerSuffix"
  Write-Host "TEST_STORE_PROVISION: $storeProvision"
  Write-Host ""
}

function Test-EnvPresentAny([string[]]$Names) {
  foreach ($name in $Names) {
    $value = [Environment]::GetEnvironmentVariable($name)
    if (-not [string]::IsNullOrWhiteSpace($value)) {
      return $true
    }
  }
  return $false
}

function Test-PathIsUnderRoot([string]$Root, [string]$Candidate) {
  $rootResolved = (Resolve-Path $Root).Path
  $candidateResolved = (Resolve-Path $Candidate).Path
  return $candidateResolved.StartsWith($rootResolved, [System.StringComparison]::OrdinalIgnoreCase)
}

function Invoke-DoctorContractChecks([ref]$Ok, [string]$EnvFilePath) {
  $storeDriver = if ($env:TEST_STORE_DRIVER) { $env:TEST_STORE_DRIVER.ToLowerInvariant().Trim() } else { 'mysql' }
  $provisionMode = if ($env:TEST_STORE_PROVISION) { $env:TEST_STORE_PROVISION.ToLowerInvariant().Trim() } else { 'managed' }

  if ($provisionMode -notin @('managed','external')) {
    Write-Host "[FAIL] TEST_STORE_PROVISION inválido: $($env:TEST_STORE_PROVISION). Valores válidos: managed|external"
    $Ok.Value = $false
    $provisionMode = 'managed'
  } else {
    Write-Host "[INFO] TEST_STORE_PROVISION=$provisionMode"
  }

  if (-not (Test-PathIsUnderRoot $ProjectRoot $EnvFilePath)) {
    Write-Host "[FAIL] TESTKIT_ENV_FILE / env detectado fuera del repo montado: $EnvFilePath"
    Write-Host "       Debe vivir dentro de $ProjectRoot para que DB_ENV_PATH sea válido dentro del contenedor."
    $Ok.Value = $false
  }

  if ($storeDriver -eq 'mysql') {
    if (Test-EnvPresentAny @('DB_HOST','TEST_MYSQL_HOST','MYSQL_HOST')) { Write-Host "[OK] MySQL host present" } else { Write-Host "[FAIL] falta host MySQL (DB_HOST / TEST_MYSQL_HOST / MYSQL_HOST)"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('DB_PORT','TEST_MYSQL_PORT','MYSQL_PORT')) { Write-Host "[OK] MySQL port present" } else { Write-Host "[FAIL] falta puerto MySQL (DB_PORT / TEST_MYSQL_PORT / MYSQL_PORT)"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('DB_NAME','TEST_MYSQL_DB','MYSQL_DATABASE')) { Write-Host "[OK] MySQL database name present" } else { Write-Host "[FAIL] falta nombre de DB MySQL (DB_NAME / TEST_MYSQL_DB / MYSQL_DATABASE)"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('DB_USER','TEST_MYSQL_USER','MYSQL_USER')) { Write-Host "[OK] MySQL runtime user present" } else { Write-Host "[FAIL] falta usuario runtime MySQL (DB_USER / TEST_MYSQL_USER / MYSQL_USER)"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('DB_PASS','TEST_MYSQL_PASSWORD','MYSQL_PASSWORD')) { Write-Host "[OK] MySQL runtime password present" } else { Write-Host "[FAIL] falta password runtime MySQL (DB_PASS / TEST_MYSQL_PASSWORD / MYSQL_PASSWORD)"; $Ok.Value = $false }

    if ($provisionMode -eq 'managed') {
      if (Test-EnvPresentAny @('TEST_MYSQL_ADMIN_USER','MYSQL_ROOT_USER')) { Write-Host "[OK] MySQL admin user present (managed provision)" } else { Write-Host "[FAIL] falta usuario admin MySQL (TEST_MYSQL_ADMIN_USER / MYSQL_ROOT_USER) para TEST_STORE_PROVISION=managed"; $Ok.Value = $false }
      if (Test-EnvPresentAny @('TEST_MYSQL_ROOT_PASSWORD','MYSQL_ROOT_PASSWORD')) { Write-Host "[OK] MySQL admin password present (managed provision)" } else { Write-Host "[FAIL] falta password admin MySQL (TEST_MYSQL_ROOT_PASSWORD / MYSQL_ROOT_PASSWORD) para TEST_STORE_PROVISION=managed"; $Ok.Value = $false }
    } else {
      Write-Host "[INFO] TEST_STORE_PROVISION=external -> no se requieren credenciales admin MySQL"
    }
  }

  if (Get-Command docker -ErrorAction SilentlyContinue) {
    Write-Host "[OK] docker command found"
  } else {
    Write-Host "[FAIL] docker no está disponible en PATH"
    $Ok.Value = $false
  }
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
  Write-Host "[INFO] TESTKIT_PROJECT_ROOT(host)=$ProjectRoot"

  if (-not (Test-Path $ResolvedTestkitRoot)) {
    Write-Host "[FAIL] TESTKIT_ROOT no existe o no es directorio: $ResolvedTestkitRoot"
    $ok = $false
  } elseif (-not (Test-Path (Join-Path $ResolvedTestkitRoot 'runTest.php'))) {
    Write-Host "[FAIL] TESTKIT_ROOT no parece repo completo: falta runTest.php en $ResolvedTestkitRoot"
    $ok = $false
  } else {
    Write-Host "[OK] TESTKIT_ROOT: $ResolvedTestkitRoot"
  }

  if (-not (Test-Path $ProjectRoot)) {
    Write-Host "[FAIL] TESTKIT_PROJECT_ROOT no existe o no es directorio: $ProjectRoot"
    $ok = $false
  } else {
    Write-Host "[OK] TESTKIT_PROJECT_ROOT: $ProjectRoot"
  }

  $testOutDir = Join-Path $ProjectRoot 'test'
  New-Item -ItemType Directory -Force -Path $testOutDir | Out-Null
  if (Test-Path $testOutDir) {
    Write-Host "[OK] $testOutDir writable"
  } else {
    Write-Host "[FAIL] $testOutDir no es escribible"
    $ok = $false
  }

  if ($envFile) {
    Invoke-DoctorContractChecks ([ref]$ok) $envFile.Path
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

if (-not (Test-PathIsUnderRoot $ProjectRoot $envFile.Path)) {
  Write-Error "El env de tests quedó fuera del repo montado: $($envFile.Path). Movelo a <project>/test/.env.test o <project>/.env.test."
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

if ($Args.Count -gt 0 -and $Args[0] -eq "inspect") {
  $inspectArgs = if ($Args.Count -gt 1) { $Args[1..($Args.Count-1)] } else { @() }
  $cmd = @("compose", "--env-file", $envFile) + $files + @("run", "--rm", "testkit", "php", "/workspace/testkit/scripts/inspect.php") + $inspectArgs
  & docker @cmd
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
  exit 0
}

$runArgs = Rewrite-RunCommandArgs $Args

$cmd = @("compose", "--env-file", $envFile) + $files + $runArgs
& docker @cmd
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
