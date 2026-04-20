#!/usr/bin/env bash
set -euo pipefail

testkit_doctor_run_capability_checks() {
  local requested_target="${1:-}"
  local db_strategy
  db_strategy="$(testkit_doctor_normalize_token "${TEST_DB_STRATEGY:-shared}")"

  local store_driver
  store_driver="$(testkit_doctor_normalize_token "${TEST_STORE_DRIVER:-mysql}")"

  local baseline_mode
  baseline_mode="$(testkit_doctor_normalize_token "${TEST_BASELINE_MODE:-}")"

  local jobs_raw="${TEST_JOBS:-1}"
  local jobs=1

  case "${db_strategy}" in
    shared)
      testkit_doctor_add_check capability PASS SHARED_STRATEGY_DECLARED \
        "TEST_DB_STRATEGY=shared está dentro del contrato vigente."
      ;;
    per_worker)
      testkit_doctor_add_check capability PASS PER_WORKER_STRATEGY_DECLARED \
        "TEST_DB_STRATEGY=per_worker está dentro del contrato vigente para aislamiento intra-suite."
      ;;
    clean)
      testkit_doctor_add_check capability FAIL CLEAN_STRATEGY_UNSUPPORTED \
        "TEST_DB_STRATEGY=clean no está implementado como modo operativo." \
        "Usá shared o per_worker."
      ;;
    "")
      testkit_doctor_add_check capability PASS DEFAULT_SHARED_STRATEGY \
        "no se declaró TEST_DB_STRATEGY; doctor asume shared como default actual."
      db_strategy="shared"
      ;;
    *)
      testkit_doctor_add_check capability FAIL INVALID_DB_STRATEGY \
        "TEST_DB_STRATEGY=${TEST_DB_STRATEGY:-} no pertenece al contrato visible." \
        "Usá shared o per_worker."
      ;;
  esac

  if [[ "${jobs_raw}" =~ ^[0-9]+$ ]]; then
    jobs="${jobs_raw}"
    if (( jobs < 1 )); then
      testkit_doctor_add_check capability WARN NON_POSITIVE_TEST_JOBS \
        "TEST_JOBS=${jobs_raw} no es una cantidad operativa normal."
    elif (( jobs == 1 )); then
      testkit_doctor_add_check capability PASS SINGLE_WORKER_PATH \
        "TEST_JOBS=1 mantiene la ruta secuencial simple."
    elif [[ "${db_strategy}" == "per_worker" ]]; then
      testkit_doctor_add_check capability PASS MULTIWORKER_PER_WORKER \
        "TEST_JOBS=${jobs_raw} con per_worker declara aislamiento intra-suite por worker."
    else
      testkit_doctor_add_check capability UNKNOWN MULTIWORKER_NEEDS_SUITE_CONTEXT \
        "TEST_JOBS=${jobs_raw} sin per_worker requiere contexto de suite y runtime DB real."
    fi
  else
    testkit_doctor_add_check capability WARN TEST_JOBS_UNPARSEABLE \
      "TEST_JOBS=${jobs_raw} no es un entero visible para doctor."
  fi

  if [[ "${store_driver}" == "mysql" || -z "${store_driver}" ]]; then
    testkit_doctor_add_check capability PASS MYSQL_CLOSED_PATH \
      "MySQL es la ruta principal cerrada del contrato actual."
    store_driver="${store_driver:-mysql}"
  else
    testkit_doctor_add_check capability UNKNOWN ENGINE_NOT_CLOSED \
      "TEST_STORE_DRIVER=${TEST_STORE_DRIVER:-} no pertenece a la ruta cerrada general de esta fase."
  fi

  case "${requested_target}" in
    "")
      ;;
    migration-contract)
      if [[ "${baseline_mode}" == "snapshot" ]]; then
        testkit_doctor_add_check capability PASS MIGRATION_CONTRACT_SNAPSHOT \
          "migration-contract declara TEST_BASELINE_MODE=snapshot."
      else
        testkit_doctor_add_check capability FAIL MIGRATION_CONTRACT_NEEDS_SNAPSHOT \
          "migration-contract exige TEST_BASELINE_MODE=snapshot." \
          "Usá TEST_BASELINE_MODE=snapshot."
      fi

      if [[ "${db_strategy}" == "shared" ]]; then
        testkit_doctor_add_check capability PASS MIGRATION_CONTRACT_SHARED \
          "migration-contract declara TEST_DB_STRATEGY=shared."
      else
        testkit_doctor_add_check capability FAIL MIGRATION_CONTRACT_NEEDS_SHARED \
          "migration-contract exige TEST_DB_STRATEGY=shared." \
          "Usá TEST_DB_STRATEGY=shared."
      fi

      if [[ "${store_driver}" == "mysql" ]]; then
        testkit_doctor_add_check capability PASS MIGRATION_CONTRACT_MYSQL \
          "migration-contract queda cerrado en esta fase solo para MySQL."
      else
        testkit_doctor_add_check capability FAIL MIGRATION_CONTRACT_NEEDS_MYSQL \
          "migration-contract queda fuera de contrato si el motor efectivo no es MySQL." \
          "Usá MySQL para este path."
      fi

      if (( jobs == 1 )); then
        testkit_doctor_add_check capability PASS MIGRATION_CONTRACT_SINGLE_WORKER \
          "migration-contract mantiene TEST_JOBS=1."
      else
        testkit_doctor_add_check capability FAIL MIGRATION_CONTRACT_NEEDS_SINGLE_WORKER \
          "migration-contract exige TEST_JOBS=1." \
          "Volvé a TEST_JOBS=1 para este target."
      fi

      if testkit_doctor_snapshot_source_visible; then
        testkit_doctor_add_check capability PASS SNAPSHOT_SOURCE_VISIBLE \
          "doctor ve una fuente visible de snapshot por archivo o metadata."
      else
        testkit_doctor_add_check capability UNKNOWN SNAPSHOT_SOURCE_NOT_VISIBLE \
          "doctor no puede probar una fuente de snapshot resoluble solo con las variables visibles actuales."
      fi
      ;;
    all|back|back-php|back-py|front|front-php|front-js|smoke|perf|stress|contract|critical|slow)
      testkit_doctor_add_check capability UNKNOWN TARGET_RULESET_PARTIAL \
        "el target ${requested_target} todavía no tiene mapa cerrado de sensibilidad DB en doctor."
      ;;
    *)
      testkit_doctor_add_check capability UNKNOWN TARGET_NOT_CLASSIFIED \
        "doctor no reconoce un ruleset cerrado para target=${requested_target}."
      ;;
  esac
}
