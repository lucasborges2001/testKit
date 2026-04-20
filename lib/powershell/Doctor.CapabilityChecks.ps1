function Invoke-TestkitDoctorCapabilityChecks {
  param(
    [Parameter(Mandatory=$true)]$Context
  )

  $dbStrategy = Normalize-TestkitDoctorToken $env:TEST_DB_STRATEGY
  if ([string]::IsNullOrWhiteSpace($dbStrategy)) { $dbStrategy = 'shared' }

  $storeDriver = Normalize-TestkitDoctorToken $env:TEST_STORE_DRIVER
  if ([string]::IsNullOrWhiteSpace($storeDriver)) { $storeDriver = 'mysql' }

  $baselineMode = Normalize-TestkitDoctorToken $env:TEST_BASELINE_MODE
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
      Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'MULTIWORKER_NEEDS_SUITE_CONTEXT' "TEST_JOBS=$jobsRaw sin per_worker requiere contexto de suite y runtime DB real."
    }
  } else {
    Add-TestkitDoctorCheck 'capability' 'WARN' 'TEST_JOBS_UNPARSEABLE' "TEST_JOBS=$jobsRaw no es un entero visible para doctor."
  }

  if ($storeDriver -eq 'mysql') {
    Add-TestkitDoctorCheck 'capability' 'PASS' 'MYSQL_CLOSED_PATH' 'MySQL es la ruta principal cerrada del contrato actual.'
  } else {
    Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'ENGINE_NOT_CLOSED' "TEST_STORE_DRIVER=$($env:TEST_STORE_DRIVER) no pertenece a la ruta cerrada general de esta fase."
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
    'all' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target all todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'back' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target back todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'back-php' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target back-php todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'back-py' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target back-py todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'front' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target front todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'front-php' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target front-php todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'front-js' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target front-js todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'smoke' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target smoke todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'perf' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target perf todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'stress' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target stress todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'contract' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target contract todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'critical' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target critical todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    'slow' { Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_RULESET_PARTIAL' 'el target slow todavía no tiene mapa cerrado de sensibilidad DB en doctor.' }
    default {
      Add-TestkitDoctorCheck 'capability' 'UNKNOWN' 'TARGET_NOT_CLASSIFIED' "doctor no reconoce un ruleset cerrado para target=$($Context.Target)."
    }
  }
}
