function Test-TestkitDoctorKnownTarget([string]$Target) {
  return $Target -in @(
    'all','back','front','public_html','back-php','back-py','back-python','python','py','front-php','front-js','php','js',
    'smoke','perf','stress','contract','critical','slow','migration-contract','migration','migrations'
  )
}

function Get-TestkitDoctorTargetKind([string]$Target) {
  switch ($Target) {
    'back-php' { 'suite' }
    'back-py' { 'suite' }
    'back-python' { 'suite' }
    'python' { 'suite' }
    'py' { 'suite' }
    'front-php' { 'suite' }
    'front-js' { 'suite' }
    'migration-contract' { 'suite' }
    'migration' { 'suite' }
    'migrations' { 'suite' }
    'all' { 'aggregate' }
    'back' { 'aggregate' }
    'front' { 'aggregate' }
    'public_html' { 'aggregate' }
    'php' { 'aggregate' }
    'js' { 'aggregate' }
    'smoke' { 'category' }
    'perf' { 'category' }
    'stress' { 'category' }
    'contract' { 'category' }
    'critical' { 'category' }
    'slow' { 'category' }
    default { 'unknown' }
  }
}

function Invoke-TestkitDoctorCapabilityChecks {
  param(
    [Parameter(Mandatory=$true)]$Context
  )

  $dbStrategy = Normalize-TestkitDoctorToken $env:TEST_DB_STRATEGY
  if ([string]::IsNullOrWhiteSpace($dbStrategy)) { $dbStrategy = 'shared' }

  $storeDriver = Normalize-TestkitDoctorToken $env:TEST_STORE_DRIVER
  if ([string]::IsNullOrWhiteSpace($storeDriver)) { $storeDriver = 'mysql' }

  $baselineMode = Normalize-TestkitDoctorToken $env:TEST_BASELINE_MODE
  $categoryEnv = Normalize-TestkitDoctorToken $env:TEST_CATEGORY
  $jobsRaw = if ([string]::IsNullOrWhiteSpace($env:TEST_JOBS)) { '1' } else { $env:TEST_JOBS }
  $jobs = 1

  switch ($dbStrategy) {
    'shared' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'SHARED_STRATEGY_DECLARED' 'TEST_DB_STRATEGY=shared está dentro del contrato vigente.'
    }
    'per_worker' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'PER_WORKER_STRATEGY_DECLARED' 'TEST_DB_STRATEGY=per_worker está dentro del contrato vigente para aislamiento intra-suite.'
    }
    'clean' {
      Add-TestkitDoctorCheck 'capability' 'FAIL' 'CLEAN_STRATEGY_UNSUPPORTED' 'TEST_DB_STRATEGY=clean no está implementado como modo operativo.' 'Usá shared o per_worker.'
    }
    default {
      Add-TestkitDoctorCheck 'capability' 'FAIL' 'INVALID_DB_STRATEGY' "TEST_DB_STRATEGY=$($env:TEST_DB_STRATEGY) no pertenece al contrato visible." 'Usá shared o per_worker.'
    }
  }

  if ($jobsRaw -match '^\d+$') {
    $jobs = [int]$jobsRaw
    if ($jobs -lt 1) {
      Add-TestkitDoctorCheck 'capability' 'WARN' 'NON_POSITIVE_TEST_JOBS' "TEST_JOBS=$jobsRaw no es una cantidad operativa normal."
    } elseif ($jobs -eq 1) {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'SINGLE_WORKER_PATH' 'TEST_JOBS=1 mantiene la ruta secuencial simple.'
    } elseif ($dbStrategy -eq 'per_worker') {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'MULTIWORKER_PER_WORKER' "TEST_JOBS=$jobsRaw con per_worker declara aislamiento intra-suite por worker."
    } else {
      Add-TestkitDoctorCheck 'capability' 'WARN' 'MULTIWORKER_SHARED_VISIBLE_RISK' "TEST_JOBS=$jobsRaw sin per_worker expone riesgo visible de shared DB en paralelo." 'Volvé a TEST_JOBS=1 o usá TEST_DB_STRATEGY=per_worker.'
    }
  } else {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'TEST_JOBS_UNPARSEABLE' "TEST_JOBS=$jobsRaw no es un entero visible para doctor."
  }

  if ($storeDriver -eq 'mysql') {
    Add-TestkitDoctorCheck 'capability' 'PASS' 'MYSQL_CLOSED_PATH' 'MySQL es la ruta principal cerrada del contrato actual.'
  } else {
    Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'ENGINE_NOT_CLOSED' "TEST_STORE_DRIVER=$($env:TEST_STORE_DRIVER) no pertenece a la ruta cerrada general de esta fase."
  }

  if (-not [string]::IsNullOrWhiteSpace($Context.Target) -and -not (Test-TestkitDoctorKnownTarget $Context.Target)) {
    Add-TestkitDoctorCheck 'capability' 'FAIL' 'TARGET_NOT_SUPPORTED' "doctor no reconoce target=$($Context.Target)." 'Usá php runTest.php --help o inspect config-schema para ver targets válidos.'
    return
  }

  $targetKind = Get-TestkitDoctorTargetKind $Context.Target
  if ($targetKind -eq 'aggregate') {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'AGGREGATE_TARGET_NOISY_FIRST_DIAG' "el target $($Context.Target) es agregado: sirve, pero no es la primera corrida diagnóstica más nítida." 'Preferí una suite concreta como back-php o front-js para el primer corte.'
  }

  if ($targetKind -eq 'category') {
    if (-not [string]::IsNullOrWhiteSpace($categoryEnv) -and $categoryEnv -ne $Context.Target) {
      Add-TestkitDoctorCheck 'capability' 'FAIL' 'TARGET_CATEGORY_MISMATCH' "target=$($Context.Target) contradice TEST_CATEGORY=$categoryEnv." 'Quitá TEST_CATEGORY o alinealo con el target pedido.'
    } else {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'CATEGORY_TARGET_ALIGNED' "target=$($Context.Target) cierra con la categoría visible actual."
    }
  }

  if ($dbStrategy -eq 'per_worker' -and $jobs -eq 1) {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'PER_WORKER_SINGLE_WORKER_OVERCONFIGURED' 'TEST_DB_STRATEGY=per_worker con TEST_JOBS=1 no rompe contrato, pero agrega complejidad sin paralelismo visible.' 'Si no necesitás workers múltiples, simplificá a TEST_DB_STRATEGY=shared.'
  }

  switch ($Context.Target) {
    '' {}
    'migration-contract' {
      if ($baselineMode -eq 'snapshot') {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'MIGRATION_CONTRACT_SNAPSHOT' 'migration-contract declara TEST_BASELINE_MODE=snapshot.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SNAPSHOT' 'migration-contract exige TEST_BASELINE_MODE=snapshot.' 'Usá TEST_BASELINE_MODE=snapshot.'
      }

      if ($dbStrategy -eq 'shared') {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'MIGRATION_CONTRACT_SHARED' 'migration-contract declara TEST_DB_STRATEGY=shared.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SHARED' 'migration-contract exige TEST_DB_STRATEGY=shared.' 'Usá TEST_DB_STRATEGY=shared.'
      }

      if ($storeDriver -eq 'mysql') {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'MIGRATION_CONTRACT_MYSQL' 'migration-contract queda cerrado en esta fase solo para MySQL.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'FAIL' 'MIGRATION_CONTRACT_NEEDS_MYSQL' 'migration-contract queda fuera de contrato si el motor efectivo no es MySQL.' 'Usá MySQL para este path.'
      }

      if ($jobs -eq 1) {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'MIGRATION_CONTRACT_SINGLE_WORKER' 'migration-contract mantiene TEST_JOBS=1.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'FAIL' 'MIGRATION_CONTRACT_NEEDS_SINGLE_WORKER' 'migration-contract exige TEST_JOBS=1.' 'Volvé a TEST_JOBS=1 para este target.'
      }

      if (Test-TestkitDoctorSnapshotSourceVisible) {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'SNAPSHOT_SOURCE_VISIBLE' 'doctor ve una fuente visible de snapshot por archivo o metadata.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'SNAPSHOT_SOURCE_NOT_VISIBLE' 'doctor no puede probar una fuente de snapshot resoluble solo con las variables visibles actuales.'
      }
    }
    'migration' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'migration es alias de una suite concreta y representa una ruta diagnóstica cerrada.'
    }
    'migrations' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'migrations es alias de una suite concreta y representa una ruta diagnóstica cerrada.'
    }
    'back-php' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'back-php es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado.' }
    'back-py' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'back-py es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado.' }
    'back-python' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'back-python es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado.' }
    'python' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'python es alias de una suite concreta y representa una ruta diagnóstica cerrada.' }
    'py' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'py es alias de una suite concreta y representa una ruta diagnóstica cerrada.' }
    'front-php' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'front-php es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado.' }
    'front-js' { Add-TestkitDoctorCheck 'capability' 'PASS' 'CONCRETE_SUITE_TARGET' 'front-js es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado.' }
    default { }
  }
}
