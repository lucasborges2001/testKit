\
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
$Influx = Join-Path $TestRoot "compose.influx.yaml"

$ProjectRoot = if ($env:TESTKIT_PROJECT_ROOT) { Resolve-Path $env:TESTKIT_PROJECT_ROOT } else { Resolve-Path (Join-Path $TestRoot "..") }
$ResolvedTestkitRoot = if ($env:TESTKIT_ROOT) { Resolve-Path $env:TESTKIT_ROOT } else { $TestRoot }

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
      'influx' {}
      'influxdb' { $token = 'influx' }
      default { throw "TESTKIT_STACK inválido: token no reconocido '$token'. Valores válidos: mysql, redis, pg, influx" }
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
  if (Stack-Has $StackCsv 'influx') {
    $files.Add('-f')
    $files.Add($Influx)
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

    if ($sawTestkit -and @('scripts/influx_router.php', './scripts/influx_router.php', '/workspace/project/scripts/influx_router.php', '/workspace/testkit/scripts/influx_router.php') -contains $rewritten[$i]) {
      $rewritten[$i] = '/workspace/testkit/scripts/influx_router.php'
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

function Normalize-SimpleToken([string]$Raw) {
  if ([string]::IsNullOrWhiteSpace($Raw)) { return '' }
  return $Raw.Trim().ToLowerInvariant()
}

function Get-CapabilityStatusRank([string]$Status) {
  switch ($Status) {
    'PASS' { return 1 }
    'WARN' { return 2 }
    'UNKNOWN' { return 3 }
    'FAIL' { return 4 }
    default { return 0 }
  }
}

$script:CapabilityStatus = 'PASS'
$script:CapabilityChecks = New-Object System.Collections.Generic.List[hashtable]

function Reset-CapabilityState {
  $script:CapabilityStatus = 'PASS'
  $script:CapabilityChecks = New-Object System.Collections.Generic.List[hashtable]
}

function Update-CapabilityStatus([string]$Candidate) {
  if ((Get-CapabilityStatusRank $Candidate) -gt (Get-CapabilityStatusRank $script:CapabilityStatus)) {
    $script:CapabilityStatus = $Candidate
  }
}

function Write-CapabilityLine([string]$Status, [string]$Code, [string]$Summary, [string]$Action = '') {
  Update-CapabilityStatus $Status
  $script:CapabilityChecks.Add(@{
    status = $Status
    code = $Code
    summary = $Summary
    action = $Action
  }) | Out-Null
  Write-Host "[$Status] $Code - $Summary"
  if (-not [string]::IsNullOrWhiteSpace($Action)) {
    Write-Host "       Acción: $Action"
  }
}

function Dump-Config([string]$EnvFilePath, [string]$StackCsv, [string]$DoctorTarget) {
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
  Write-Host "TESTKIT_DOCTOR_TARGET: $DoctorTarget"
  Write-Host "TESTKIT_CAPABILITY_STATUS: $script:CapabilityStatus"
  $capabilityCount = $script:CapabilityChecks.Count
  Write-Host "TESTKIT_CAPABILITY_CHECK_COUNT: $capabilityCount"
  for ($i = 0; $i -lt $script:CapabilityChecks.Count; $i++) {
    $n = $i + 1
    $check = $script:CapabilityChecks[$i]
    Write-Host "TESTKIT_CAPABILITY_CHECK_${n}_STATUS: $($check.status)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${n}_CODE: $($check.code)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${n}_SUMMARY: $($check.summary)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${n}_ACTION: $($check.action)"
  }
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

function Test-DirectoryWritable([string]$Path) {
  try {
    $probe = Join-Path $Path (".testkit_write_probe_" + [guid]::NewGuid().ToString('N'))
    Set-Content -Path $probe -Value 'ok' -Encoding utf8 -NoNewline
    Remove-Item -Force $probe
    return $true
  } catch {
    return $false
  }
}

function Test-PathIsUnderRoot([string]$Root, [string]$Candidate) {
  $rootResolved = (Resolve-Path $Root).Path
  $candidateResolved = (Resolve-Path $Candidate).Path
  return $candidateResolved.StartsWith($rootResolved, [System.StringComparison]::OrdinalIgnoreCase)
}

function Invoke-DoctorContractChecks([ref]$Ok, [string]$EnvFilePath, [string]$StackCsv) {
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

  if (Stack-Has $StackCsv 'influx') {
    if (Test-EnvPresentAny @('TEST_INFLUX_ORG')) { Write-Host "[OK] Influx org present" } else { Write-Host "[FAIL] falta TEST_INFLUX_ORG para stack con influx"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('TEST_INFLUX_BUCKET')) { Write-Host "[OK] Influx bucket present" } else { Write-Host "[FAIL] falta TEST_INFLUX_BUCKET para stack con influx"; $Ok.Value = $false }
    if (Test-EnvPresentAny @('TEST_INFLUX_TOKEN','TEST_INFLUX_ADMIN_TOKEN')) { Write-Host "[OK] Influx token present" } else { Write-Host "[FAIL] falta token de Influx (TEST_INFLUX_TOKEN o TEST_INFLUX_ADMIN_TOKEN)"; $Ok.Value = $false }
  }

  if (Get-Command docker -ErrorAction SilentlyContinue) {
    Write-Host "[OK] docker command found"
  } else {
    Write-Host "[FAIL] docker no está disponible en PATH"
    $Ok.Value = $false
  }
}

function Invoke-DoctorCapabilityChecks([string]$DoctorTarget, [string]$StackCsv) {
  Reset-CapabilityState

  $dbStrategy = if ($env:TEST_DB_STRATEGY) { Normalize-SimpleToken $env:TEST_DB_STRATEGY } else { 'shared' }
  $storeDriver = if ($env:TEST_STORE_DRIVER) { Normalize-SimpleToken $env:TEST_STORE_DRIVER } else { 'mysql' }
  $baselineMode = if ($env:TEST_BASELINE_MODE) { Normalize-SimpleToken $env:TEST_BASELINE_MODE } else { '' }
  $jobsRaw = if ($env:TEST_JOBS) { $env:TEST_JOBS } else { '1' }

  Write-Host ""
  Write-Host "== CAPABILITY DOCTOR =="
  if ([string]::IsNullOrWhiteSpace($DoctorTarget)) {
    Write-Host "[INFO] capability target=generic"
    Write-Host "       Sin target explícito, solo se evalúan restricciones visibles por config."
  } else {
    Write-Host "[INFO] capability target=$DoctorTarget"
  }

  switch ($dbStrategy) {
    'shared' { Write-CapabilityLine 'PASS' 'SHARED_STRATEGY_DECLARED' 'TEST_DB_STRATEGY=shared está dentro del contrato vigente.' }
    'per_worker' {
      Write-CapabilityLine 'PASS' 'PER_WORKER_STRATEGY_DECLARED' 'TEST_DB_STRATEGY=per_worker está dentro del contrato vigente para aislamiento intra-suite.'
      Write-Host "       Nota: no vuelve seguras corridas top-level concurrentes sobre el mismo store base."
    }
    'clean' { Write-CapabilityLine 'FAIL' 'CLEAN_STRATEGY_UNSUPPORTED' 'TEST_DB_STRATEGY=clean no está implementado como modo operativo.' 'Usar TEST_DB_STRATEGY=shared o TEST_DB_STRATEGY=per_worker según el nivel de aislamiento que necesite la suite.' }
    '' {
      Write-CapabilityLine 'PASS' 'DEFAULT_SHARED_STRATEGY' 'No se declaró TEST_DB_STRATEGY; doctor asume shared como default actual.'
      $dbStrategy = 'shared'
    }
    default { Write-CapabilityLine 'FAIL' 'INVALID_DB_STRATEGY' "TEST_DB_STRATEGY=$($env:TEST_DB_STRATEGY) no pertenece al contrato visible." 'Usar TEST_DB_STRATEGY=shared o TEST_DB_STRATEGY=per_worker.' }
  }

  $jobs = 1
  if ($jobsRaw -match '^\d+$') {
    $jobs = [int]$jobsRaw
    if ($jobs -lt 1) {
      Write-CapabilityLine 'WARN' 'NON_POSITIVE_TEST_JOBS' "TEST_JOBS=$jobsRaw no es una cantidad operativa normal; doctor tratará la lectura como no confiable." 'Usar un entero >= 1.'
    } elseif ($jobs -eq 1) {
      Write-CapabilityLine 'PASS' 'SINGLE_WORKER_PATH' 'TEST_JOBS=1 mantiene la ruta secuencial simple.'
    } elseif ($DoctorTarget -eq 'migration-contract') {
      Write-CapabilityLine 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SINGLE_WORKER' "migration-contract no tiene una ruta cerrada con TEST_JOBS=$jobsRaw; usar shared no vuelve seguro el paralelismo intra-suite." 'Usar TEST_JOBS=1 para migration-contract.'
    } elseif ($dbStrategy -eq 'per_worker') {
      Write-CapabilityLine 'PASS' 'MULTIWORKER_PER_WORKER' "TEST_JOBS=$jobsRaw con per_worker declara aislamiento intra-suite por worker."
    } else {
      Write-CapabilityLine 'UNKNOWN' 'MULTIWORKER_NEEDS_SUITE_CONTEXT' "TEST_JOBS=$jobsRaw sin per_worker requiere saber si la suite toca DB real; doctor no puede cerrarlo solo con config visible." 'Volver a TEST_JOBS=1 o usar TEST_DB_STRATEGY=per_worker si la suite necesita DB real con paralelismo.'
      Write-Host "       Nota: aun con per_worker, doctor no observa corridas top-level concurrentes."
    }
  } else {
    Write-CapabilityLine 'WARN' 'TEST_JOBS_UNPARSEABLE' "TEST_JOBS=$jobsRaw no es un entero visible para doctor." 'Usar un entero visible para doctor.'
  }

  if ($storeDriver -eq 'mysql' -or [string]::IsNullOrWhiteSpace($storeDriver)) {
    Write-CapabilityLine 'PASS' 'MYSQL_CLOSED_PATH' 'MySQL es la ruta principal cerrada del contrato actual.'
    if ([string]::IsNullOrWhiteSpace($storeDriver)) { $storeDriver = 'mysql' }
  } else {
    Write-CapabilityLine 'UNKNOWN' 'ENGINE_NOT_CLOSED' "TEST_STORE_DRIVER=$($env:TEST_STORE_DRIVER) no pertenece a la ruta cerrada general de esta fase." 'Usar MySQL si querés la ruta cerrada actual.'
  }

  if (Stack-Has $StackCsv 'influx') {
    Write-CapabilityLine 'PASS' 'INFLUX_STACK_ENABLED' 'TESTKIT_STACK incluye influx: el wrapper puede levantar InfluxDB 2.7 como servicio auxiliar.'
    Write-CapabilityLine 'UNKNOWN' 'INFLUX_NOT_STRUCTURAL_STORE' 'Influx queda integrado como servicio auxiliar HTTP; no reemplaza la ruta cerrada MySQL/StoreAdapter.' 'Usar scripts/influx_router.php o core/php/influx/* desde tests/proyecto.'
  }

  switch ($DoctorTarget) {
    '' {}
    'migration-contract' {
      if ($baselineMode -eq 'snapshot') {
        Write-CapabilityLine 'PASS' 'MIGRATION_CONTRACT_SNAPSHOT' 'migration-contract declara TEST_BASELINE_MODE=snapshot.'
      } else {
        Write-CapabilityLine 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SNAPSHOT' 'migration-contract exige TEST_BASELINE_MODE=snapshot.' 'Cambiar a TEST_BASELINE_MODE=snapshot.'
      }

      if ($dbStrategy -eq 'shared') {
        Write-CapabilityLine 'PASS' 'MIGRATION_CONTRACT_SHARED' 'migration-contract declara TEST_DB_STRATEGY=shared.'
      } else {
        Write-CapabilityLine 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SHARED' 'migration-contract exige TEST_DB_STRATEGY=shared.' 'Cambiar a TEST_DB_STRATEGY=shared para migration-contract.'
      }

      if ($storeDriver -eq 'mysql') {
        Write-CapabilityLine 'PASS' 'MIGRATION_CONTRACT_MYSQL' 'migration-contract queda cerrado en esta fase solo para MySQL.'
      } else {
        Write-CapabilityLine 'FAIL' 'MIGRATION_CONTRACT_NEEDS_MYSQL' 'migration-contract queda fuera de contrato si el motor efectivo no es MySQL.' 'Usar MySQL si querés la ruta cerrada de migration-contract.'
      }

      if (Test-EnvPresentAny @('TEST_BASELINE_SNAPSHOT_FILE','TEST_BASELINE_SNAPSHOT_METADATA_FILE','TEST_BASELINE_SNAPSHOT_REPORT_FILE','TEST_BASELINE_SNAPSHOT_METADATA','TEST_BASELINE_SNAPSHOT_REPORT','TEST_BASELINE_SNAPSHOT_JSON')) {
        Write-CapabilityLine 'PASS' 'SNAPSHOT_SOURCE_VISIBLE' 'Doctor ve una fuente visible de snapshot.'
      } else {
        Write-CapabilityLine 'UNKNOWN' 'SNAPSHOT_SOURCE_NOT_VISIBLE' 'Doctor no puede probar una fuente de snapshot resoluble solo con las variables visibles actuales.' 'Declarar TEST_BASELINE_SNAPSHOT_FILE o un hint visible de metadata/report JSON compatible.'
      }
    }
    'all' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target all todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'back' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target back todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'back-php' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target back-php todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'back-py' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target back-py todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'front' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target front todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'front-php' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target front-php todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'front-js' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target front-js todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'smoke' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target smoke todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'perf' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target perf todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    'stress' { Write-CapabilityLine 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'El target stress todavía no tiene mapa cerrado de sensibilidad DB en doctor.' 'Usar doctor sin target para constraints genéricas o cerrar un ruleset específico para este target.' }
    default { Write-CapabilityLine 'UNKNOWN' 'TARGET_NOT_CLASSIFIED' "Doctor no reconoce un ruleset cerrado para target=$DoctorTarget." 'Usar doctor sin target o registrar un ruleset explícito para ese target.' }
  }

  Write-Host ""
  Write-Host "Capability doctor: $script:CapabilityStatus"
  Write-Host "Nota: esta sección no cambia el exit code del wrapper; el exit sigue atado al contrato mínimo visible."
}

function Run-Doctor {
  param(
    [switch]$Dump,
    [string]$DoctorTarget = ''
  )

  $ok = $true
  $doctorTargetResolved = if ([string]::IsNullOrWhiteSpace($DoctorTarget)) { Normalize-SimpleToken $env:TESTKIT_DOCTOR_TARGET } else { Normalize-SimpleToken $DoctorTarget }

  Write-Host "== TESTKIT DOCTOR =="

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
  Write-Host "[INFO] TESTKIT_HOST_UID:GID=n/a"

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
  if ((Test-Path $testOutDir) -and (Test-DirectoryWritable $testOutDir)) {
    Write-Host "[OK] $testOutDir writable"
  } else {
    Write-Host "[FAIL] $testOutDir no es escribible"
    $ok = $false
  }

  if ($envFile) {
    Invoke-DoctorContractChecks ([ref]$ok) $envFile.Path $stackCsv
    Invoke-DoctorCapabilityChecks $doctorTargetResolved $stackCsv
  }

  if ($Dump -and $envFile) {
    $env:TESTKIT_DB_ENV_PATH = EnvFile-ToContainerDbEnvPath($envFile.Path)
    $env:TESTKIT_PROJECT_ROOT = $ProjectRoot.Path
    $env:TESTKIT_ROOT = $ResolvedTestkitRoot.Path
    Dump-Config $envFile.Path $stackCsv $doctorTargetResolved
  }

  if ($ok) { Write-Host "`nDoctor: OK"; exit 0 }
  Write-Error "`nDoctor: FAIL (ver arriba)"
  exit 1
}

if ($Args.Count -gt 0 -and $Args[0] -eq "doctor") {
  $dump = $false
  $doctorTarget = ''
  if ($Args.Count -gt 1) {
    foreach ($arg in $Args[1..($Args.Count-1)]) {
      if ($arg -eq '--dump') {
        $dump = $true
      } elseif ([string]::IsNullOrWhiteSpace($doctorTarget)) {
        $doctorTarget = $arg
      }
    }
  }
  Run-Doctor -Dump:$dump -DoctorTarget:$doctorTarget
}

$envFile = Pick-EnvFile
if (-not $envFile) {
  Write-Error "Falta env de tests. Creá <project>/test/.env.test o <project>/.env.test."
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
