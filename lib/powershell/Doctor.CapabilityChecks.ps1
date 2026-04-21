function Get-TestkitDoctorCanonicalTarget([string]$Target) {
  $normalized = Normalize-TestkitDoctorToken $Target
  switch ($normalized) {
    'migration' { return 'migration-contract' }
    'migrations' { return 'migration-contract' }
    'back-python' { return 'back-py' }
    'python' { return 'back-py' }
    'py' { return 'back-py' }
    'public_html' { return 'front' }
    default { return $normalized }
  }
}

function Get-TestkitDoctorTargetClass([string]$Target) {
  switch (Get-TestkitDoctorCanonicalTarget $Target) {
    '' { return 'none' }
    'migration-contract' { return 'migration_contract' }
    'back-php' { return 'suite' }
    'back-py' { return 'suite' }
    'front-php' { return 'suite' }
    'front-js' { return 'suite' }
    'all' { return 'aggregate' }
    'back' { return 'aggregate' }
    'front' { return 'aggregate' }
    'php' { return 'aggregate' }
    'js' { return 'aggregate' }
    'smoke' { return 'category' }
    'perf' { return 'category' }
    'stress' { return 'category' }
    'contract' { return 'category' }
    'critical' { return 'category' }
    'slow' { return 'category' }
    default { return 'unknown' }
  }
}

function Invoke-TestkitDoctorCapabilityChecks {
  param(
    [Parameter(Mandatory=$true)]$Context
  )

  $target = Get-TestkitDoctorCanonicalTarget $Context.Target
  $targetClass = Get-TestkitDoctorTargetClass $target

  $dbStrategy = Normalize-TestkitDoctorToken $env:TEST_DB_STRATEGY
  if ([string]::IsNullOrWhiteSpace($dbStrategy)) { $dbStrategy = 'shared' }

  $storeDriver = Normalize-TestkitDoctorToken $env:TEST_STORE_DRIVER
  if ([string]::IsNullOrWhiteSpace($storeDriver)) { $storeDriver = 'mysql' }

  $baselineMode = Normalize-TestkitDoctorToken $env:TEST_BASELINE_MODE
  $categoryEffective = Normalize-TestkitDoctorToken $env:TEST_CATEGORY
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
      Add-TestkitDoctorCheck 'capability' 'WARN' 'MULTIWORKER_SHARED_VISIBLE_RISK' "TEST_JOBS=$jobsRaw sin per_worker deja una ruta visible de riesgo sobre store compartido." 'Volvé a TEST_JOBS=1 o usá TEST_DB_STRATEGY=per_worker.'
    }
  } else {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'TEST_JOBS_UNPARSEABLE' "TEST_JOBS=$jobsRaw no es un entero visible para doctor."
  }

  if ($dbStrategy -eq 'per_worker' -and $jobs -eq 1) {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'PER_WORKER_SINGLE_WORKER_OVERCONFIGURED' 'TEST_DB_STRATEGY=per_worker con TEST_JOBS=1 agrega complejidad sin paralelismo efectivo visible.' 'Si no necesitás aislamiento por worker, simplificá a TEST_DB_STRATEGY=shared.'
  }

  if ($storeDriver -eq 'mysql') {
    Add-TestkitDoctorCheck 'capability' 'PASS' 'MYSQL_CLOSED_PATH' 'MySQL es la ruta principal cerrada del contrato actual.'
  } else {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'ENGINE_NOT_CLOSED' "TEST_STORE_DRIVER=$($env:TEST_STORE_DRIVER) no pertenece a la ruta cerrada general de esta fase." 'Si querés el path más cerrado, usá MySQL.'
  }

  switch ($targetClass) {
    'none' { }
    'migration_contract' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'TARGET_CLASSIFIED' "doctor reconoce target=$target como ruta contractual técnica cerrada."

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
    'suite' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'TARGET_CLASSIFIED' "doctor reconoce target=$target como suite concreta."
      if ($target -eq 'front-js' -and $storeDriver -ne 'mysql') {
        Add-TestkitDoctorCheck 'capability' 'WARN' 'FRONT_JS_NON_CLOSED_ENGINE' 'front-js no cierra por sí solo una ruta contractual alternativa cuando el motor visible no es MySQL.'
      }
    }
    'aggregate' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'TARGET_CLASSIFIED' "doctor reconoce target=$target como target agregado."
      Add-TestkitDoctorCheck 'capability' 'WARN' 'AGGREGATE_TARGET_NOISY_FIRST_DIAG' "target=$target agrega varias superficies y suele ser mala primera corrida de diagnóstico." 'Empezá por una suite concreta como back-php, back-py, front-php o front-js.'
    }
    'category' {
      Add-TestkitDoctorCheck 'capability' 'PASS' 'TARGET_CLASSIFIED' "doctor reconoce target=$target como target por categoría."
      if (-not [string]::IsNullOrWhiteSpace($categoryEffective) -and $categoryEffective -ne $target) {
        Add-TestkitDoctorCheck 'capability' 'WARN' 'TARGET_CATEGORY_MISMATCH' "target=$target pero TEST_CATEGORY=$categoryEffective: la selección visible mezcla señales distintas." 'Alineá TEST_CATEGORY con el target o dejalo vacío para que el runner resuelva la categoría.'
      } else {
        Add-TestkitDoctorCheck 'capability' 'PASS' 'CATEGORY_TARGET_ALIGNED' "la categoría visible queda alineada con target=$target."
      }
    }
    default {
      Add-TestkitDoctorCheck 'capability' 'FAIL' 'TARGET_NOT_CLASSIFIED' "doctor no reconoce target=$($Context.Target) dentro del contrato visible." 'Usá all|back|front|back-php|back-py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow|migration-contract.'
    }
  }
}
