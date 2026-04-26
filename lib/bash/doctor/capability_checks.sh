#!/usr/bin/env bash
set -euo pipefail

testkit_doctor_known_target() {
  local target="${1:-}"
  case "${target}" in
    all|back|front|public_html|back-php|back-py|back-python|python|py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow|migration-contract|migration|migrations)
      return 0 ;;
    *)
      return 1 ;;
  esac
}

testkit_doctor_target_kind() {
  local target="${1:-}"
  case "${target}" in
    back-php|back-py|back-python|python|py|front-php|front-js|migration-contract|migration|migrations)
      echo "suite" ;;
    all|back|front|public_html|php|js)
      echo "aggregate" ;;
    smoke|perf|stress|contract|critical|slow)
      echo "category" ;;
    *)
      echo "unknown" ;;
  esac
}

testkit_doctor_effective_store_driver() {
  local raw="${TEST_STORE_DRIVER:-}"
  if [[ -z "${raw//[[:space:]]/}" ]]; then
    raw="${DB_DRIVER:-${TEST_DB_DRIVER:-}}"
  fi
  if [[ -z "${raw//[[:space:]]/}" && -n "${TEST_DB_DSN:-}" ]]; then
    raw="${TEST_DB_DSN%%:*}"
  fi

  raw="$(testkit_doctor_normalize_token "${raw:-mysql}")"
  case "${raw}" in
    pg|postgres|postgresql) echo "pgsql" ;;
    mysql|"") echo "mysql" ;;
    *) echo "${raw}" ;;
  esac
}

testkit_doctor_stack_contains() {
  local needle="$(testkit_doctor_normalize_token "${1:-}")"
  local stack="${TESTKIT_STACK_EFFECTIVE:-${TESTKIT_STACK:-}}"
  stack="$(echo "${stack}" | tr '[:upper:]' '[:lower:]')"
  IFS=',' read -r -a parts <<< "${stack}"
  local part
  for part in "${parts[@]}"; do
    part="$(testkit_doctor_normalize_token "${part}")"
    if [[ "${part}" == "${needle}" ]]; then
      return 0
    fi
    if [[ "${needle}" == "pgsql" && "${part}" == "pg" ]]; then
      return 0
    fi
  done
  return 1
}

testkit_doctor_emit_engine_capability() {
  local store_driver="$1"
  case "${store_driver}" in
    mysql)
      testkit_doctor_add_check capability PASS MYSQL_CLOSED_PATH \
        "MySQL es la ruta principal cerrada: provision/reset/snapshot/clone/per_worker."
      ;;
    pgsql)
      testkit_doctor_add_check capability WARN POSTGRES_PARTIAL_SUPPORT \
        "PostgreSQL está clasificado como soporte parcial: runtime/provision/reset básico, sin snapshot/clone cerrado." \
        "No lo trates como equivalente a MySQL ni como ruta cerrada de migration-contract."
      ;;
    redis)
      testkit_doctor_add_check capability FAIL REDIS_NOT_STRUCTURAL_STORE \
        "Redis no es un TEST_STORE_DRIVER estructural soportado por el core PHP." \
        "Usalo solo como servicio auxiliar del proyecto, no como store driver principal."
      ;;
    influx|influxdb)
      testkit_doctor_add_check capability FAIL INFLUX_NOT_STRUCTURAL_STORE \
        "Influx no es un TEST_STORE_DRIVER estructural; su contrato es auxiliar/perfilado." \
        "No lo uses como store driver principal de seed/bootstrap."
      ;;
    *)
      testkit_doctor_add_check capability UNKNOWN ENGINE_NOT_CLOSED \
        "driver=${store_driver} no pertenece a la ruta cerrada general de esta fase."
      ;;
  esac

  if testkit_doctor_stack_contains redis; then
    testkit_doctor_add_check capability PASS REDIS_AUXILIARY_SERVICE \
      "Redis aparece en TESTKIT_STACK como servicio auxiliar; no tiene lifecycle estructural core."
  fi

  if testkit_doctor_stack_contains influx; then
    testkit_doctor_add_check capability PASS INFLUX_AUXILIARY_PROFILING \
      "Influx aparece en TESTKIT_STACK como servicio auxiliar/perfilado; no es store driver principal."
  fi

  if testkit_doctor_stack_contains pg && [[ "${store_driver}" != "pgsql" ]]; then
    testkit_doctor_add_check capability UNKNOWN POSTGRES_STACK_AUXILIARY_OR_UNUSED \
      "El stack incluye pg, pero el driver efectivo no es pgsql; doctor no asume soporte PostgreSQL cerrado."
  fi
}

testkit_doctor_run_capability_checks() {
  local requested_target="${1:-}"
  local db_strategy
  db_strategy="$(testkit_doctor_normalize_token "${TEST_DB_STRATEGY:-shared}")"

  local store_driver
  store_driver="$(testkit_doctor_effective_store_driver)"

  local baseline_mode
  baseline_mode="$(testkit_doctor_normalize_token "${TEST_BASELINE_MODE:-}")"

  local category_env
  category_env="$(testkit_doctor_normalize_token "${TEST_CATEGORY:-}")"

  local jobs_raw="${TEST_JOBS:-1}"
  local jobs=1

  case "${db_strategy}" in
    shared)
      testkit_doctor_add_check capability PASS SHARED_STRATEGY_DECLARED \
        "TEST_DB_STRATEGY=shared está dentro del contrato vigente."
      ;;
    per_worker)
      testkit_doctor_add_check capability PASS PER_WORKER_STRATEGY_DECLARED \
        "TEST_DB_STRATEGY=per_worker está dentro del contrato vigente solo para aislamiento intra-suite; no habilita runners top-level concurrentes."
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
        "TEST_JOBS=${jobs_raw} con per_worker declara aislamiento intra-suite por worker; no top-level concurrency."
    else
      testkit_doctor_add_check capability WARN MULTIWORKER_SHARED_VISIBLE_RISK \
        "TEST_JOBS=${jobs_raw} sin per_worker expone riesgo visible de shared DB en paralelo." \
        "Volvé a TEST_JOBS=1 o usá TEST_DB_STRATEGY=per_worker."
    fi
  else
    testkit_doctor_add_check capability WARN TEST_JOBS_UNPARSEABLE \
      "TEST_JOBS=${jobs_raw} no es un entero visible para doctor."
  fi

  testkit_doctor_emit_engine_capability "${store_driver}"

  if [[ -n "${requested_target}" ]] && ! testkit_doctor_known_target "${requested_target}"; then
    testkit_doctor_add_check capability FAIL TARGET_NOT_SUPPORTED \
      "doctor no reconoce target=${requested_target}." \
      "Usá php runTest.php --help o inspect config-schema para ver targets válidos."
    return 0
  fi

  local target_kind
  target_kind="$(testkit_doctor_target_kind "${requested_target}")"

  if [[ "${target_kind}" == "aggregate" ]]; then
    testkit_doctor_add_check capability WARN AGGREGATE_TARGET_NOISY_FIRST_DIAG \
      "el target ${requested_target} es agregado: sirve, pero no es la primera corrida diagnóstica más nítida." \
      "Preferí una suite concreta como back-php o front-js para el primer corte."
  fi

  if [[ "${target_kind}" == "category" ]]; then
    if [[ -n "${category_env}" && "${category_env}" != "${requested_target}" ]]; then
      testkit_doctor_add_check capability FAIL TARGET_CATEGORY_MISMATCH \
        "target=${requested_target} contradice TEST_CATEGORY=${category_env}." \
        "Quitá TEST_CATEGORY o alinealo con el target pedido."
    else
      testkit_doctor_add_check capability PASS CATEGORY_TARGET_ALIGNED \
        "target=${requested_target} cierra con la categoría visible actual."
    fi
  fi

  if [[ "${db_strategy}" == "per_worker" && "${jobs}" == "1" ]]; then
    testkit_doctor_add_check capability WARN PER_WORKER_SINGLE_WORKER_OVERCONFIGURED \
      "TEST_DB_STRATEGY=per_worker con TEST_JOBS=1 no rompe contrato, pero agrega complejidad sin paralelismo visible." \
      "Si no necesitás workers múltiples, simplificá a TEST_DB_STRATEGY=shared."
  fi

  case "${requested_target}" in
    "")
      ;;
    migration-contract|migration|migrations)
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
        if [[ "${store_driver}" == "mysql" ]]; then
          testkit_doctor_add_check capability PASS SNAPSHOT_SOURCE_VISIBLE \
            "doctor ve una fuente visible de snapshot por archivo o metadata/report para la ruta MySQL."
        else
          testkit_doctor_add_check capability WARN SNAPSHOT_SOURCE_NON_MYSQL_NOT_CLOSED \
            "hay fuente de snapshot visible, pero snapshot/clone no está cerrado para driver=${store_driver}."
        fi
      else
        testkit_doctor_add_check capability UNKNOWN SNAPSHOT_SOURCE_NOT_VISIBLE \
          "doctor no puede probar una fuente de snapshot resoluble solo con las variables visibles actuales."
      fi
      ;;
    back-php|back-py|back-python|python|py|front-php|front-js)
      testkit_doctor_add_check capability PASS CONCRETE_SUITE_TARGET \
        "${requested_target} es una suite concreta y representa una ruta diagnóstica más cerrada que un target agregado."
      ;;
    all|back|front|public_html|php|js)
      ;;
    smoke|perf|stress|contract|critical|slow)
      ;;
  esac
}
