#!/usr/bin/env bash
set -euo pipefail

testkit_doctor_run_base_checks() {
  local ok_ref_name="$1"
  local -n ok_ref="$ok_ref_name"

  if [[ -n "${ENV_FILE:-}" && -f "${ENV_FILE:-}" ]]; then
    testkit_doctor_add_check base PASS ENV_FILE_PRESENT "env detectado: ${ENV_FILE}"
  else
    testkit_doctor_add_check base FAIL ENV_FILE_MISSING "falta env de tests: <project>/test/.env.test o <project>/.env.test" \
      "Creá el env dentro del repo del proyecto."
    ok_ref=0
  fi

  if [[ -d "${TESTKIT_ROOT_HOST}" ]]; then
    if [[ -f "${TESTKIT_ROOT_HOST}/runTest.php" ]]; then
      testkit_doctor_add_check base PASS TESTKIT_ROOT_READY "TESTKIT_ROOT parece repo completo: ${TESTKIT_ROOT_HOST}"
    else
      testkit_doctor_add_check base FAIL TESTKIT_ROOT_INCOMPLETE "TESTKIT_ROOT no parece repo completo: falta runTest.php" \
        "Apuntá TESTKIT_ROOT al repo real de testkit."
      ok_ref=0
    fi
  else
    testkit_doctor_add_check base FAIL TESTKIT_ROOT_MISSING "TESTKIT_ROOT no existe o no es directorio: ${TESTKIT_ROOT_HOST}" \
      "Corregí TESTKIT_ROOT o ejecutá desde el repo correcto."
    ok_ref=0
  fi

  if [[ -d "${PROJECT_ROOT}" ]]; then
    testkit_doctor_add_check base PASS PROJECT_ROOT_READY "TESTKIT_PROJECT_ROOT existe: ${PROJECT_ROOT}"
  else
    testkit_doctor_add_check base FAIL PROJECT_ROOT_MISSING "TESTKIT_PROJECT_ROOT no existe: ${PROJECT_ROOT}" \
      "Definí TESTKIT_PROJECT_ROOT contra el repo bajo prueba."
    ok_ref=0
  fi

  if [[ -n "${ENV_FILE:-}" && -f "${ENV_FILE:-}" ]]; then
    if testkit_path_is_under_root "${PROJECT_ROOT}" "${ENV_FILE}"; then
      testkit_doctor_add_check base PASS ENV_UNDER_PROJECT "el env de tests vive dentro del repo montado"
    else
      testkit_doctor_add_check base FAIL ENV_OUTSIDE_PROJECT "el env de tests quedó fuera del repo montado: ${ENV_FILE}" \
        "Mové el env a <project>/test/.env.test o <project>/.env.test."
      ok_ref=0
    fi
  fi

  local test_dir="${PROJECT_ROOT}/test"
  mkdir -p "${test_dir}" >/dev/null 2>&1 || true
  if [[ -w "${test_dir}" ]]; then
    testkit_doctor_add_check base PASS TEST_DIR_WRITABLE "${test_dir} es escribible"
  else
    testkit_doctor_add_check base FAIL TEST_DIR_NOT_WRITABLE "${test_dir} no es escribible" \
      "Corregí permisos del repo o del volumen montado."
    ok_ref=0
  fi

  if command -v docker >/dev/null 2>&1; then
    testkit_doctor_add_check base PASS DOCKER_FOUND "docker está disponible en PATH"
    if docker buildx version >/dev/null 2>&1; then
      testkit_doctor_add_check base PASS BUILDX_AVAILABLE "BUILDX=available"
      testkit_doctor_add_check base PASS BUILD_ENGINE "BUILD_ENGINE=buildkit"
      testkit_doctor_add_check base PASS CACHE_EXPECTATION "CACHE_EXPECTATION=normal"
    else
      testkit_doctor_add_check base WARN BUILDX_MISSING "BUILDX=missing" \
        "La suite puede usar el builder clásico, pero los builds y el cache pueden rendir peor. Instalá Buildx fuera de TestKit."
      testkit_doctor_add_check base WARN BUILD_ENGINE "BUILD_ENGINE=classic"
      testkit_doctor_add_check base WARN CACHE_EXPECTATION "CACHE_EXPECTATION=degraded"
    fi
  else
    testkit_doctor_add_check base FAIL DOCKER_MISSING "docker no está disponible en PATH" \
      "Instalá Docker o corregí PATH."
    ok_ref=0
  fi

  testkit_doctor_add_check base PASS STACK_RESOLVED "TESTKIT_STACK efectivo: ${TESTKIT_STACK_EFFECTIVE:-<empty>}"

  local infra_conflicts
  infra_conflicts="$(testkit_env_infrastructure_override_conflicts)"
  if [[ -n "${infra_conflicts}" ]]; then
    if [[ "${TESTKIT_ALLOW_ENV_OVERRIDES:-0}" == "1" ]]; then
      testkit_doctor_add_check base WARN ENV_OVERRIDE_EXPLICIT \
        "override explícito de infraestructura desde proceso sobre ${ENV_FILE:-.env.test}" \
        "Revisá provenance en --dump si necesitás auditar valores efectivos."
    elif [[ "${TESTKIT_MODE:-}" == "agent" ]]; then
      testkit_doctor_add_check base FAIL ENV_OVERRIDE_CONFLICT \
        "variables heredadas de infraestructura contradicen ${ENV_FILE:-.env.test}" \
        "Limpiá el entorno o usá TESTKIT_ALLOW_ENV_OVERRIDES=1 si es intencional."
      ok_ref=0
    else
      testkit_doctor_add_check base WARN ENV_OVERRIDE_CONFLICT \
        "variables heredadas de infraestructura contradicen ${ENV_FILE:-.env.test}" \
        "Limpiá el entorno o usá TESTKIT_ALLOW_ENV_OVERRIDES=1 si es intencional."
    fi
  fi

  local provision_mode
  provision_mode="$(testkit_doctor_normalize_token "${TEST_STORE_PROVISION:-managed}")"
  if [[ "${provision_mode}" != "managed" && "${provision_mode}" != "external" ]]; then
    testkit_doctor_add_check base FAIL INVALID_STORE_PROVISION \
      "TEST_STORE_PROVISION=${TEST_STORE_PROVISION:-} no es válido" \
      "Usá managed o external."
    ok_ref=0
    provision_mode="managed"
  else
    testkit_doctor_add_check base PASS STORE_PROVISION_DECLARED "TEST_STORE_PROVISION=${provision_mode}"
  fi

  local store_driver="${TEST_STORE_DRIVER:-}"

  if [[ "${store_driver}" == "none" ]]; then
    testkit_doctor_add_check base PASS STORE_DRIVER_NONE \
      "proyecto sin store runtime: TEST_STORE_DRIVER=none"
  elif [[ "${store_driver}" == "mysql" ]]; then
    if testkit_doctor_env_present_any DB_HOST TEST_MYSQL_HOST MYSQL_HOST; then
      testkit_doctor_add_check base PASS MYSQL_HOST_PRESENT "host MySQL visible"
    else
      testkit_doctor_add_check base FAIL MYSQL_HOST_MISSING "falta host MySQL (DB_HOST / TEST_MYSQL_HOST / MYSQL_HOST)" \
        "Declará el host runtime del store."
      ok_ref=0
    fi

    if testkit_doctor_env_present_any DB_PORT TEST_MYSQL_PORT MYSQL_PORT; then
      testkit_doctor_add_check base PASS MYSQL_PORT_PRESENT "puerto MySQL visible"
    else
      testkit_doctor_add_check base FAIL MYSQL_PORT_MISSING "falta puerto MySQL (DB_PORT / TEST_MYSQL_PORT / MYSQL_PORT)" \
        "Declará el puerto runtime del store."
      ok_ref=0
    fi

    if testkit_doctor_env_present_any DB_NAME TEST_MYSQL_DB MYSQL_DATABASE; then
      testkit_doctor_add_check base PASS MYSQL_DB_PRESENT "nombre de DB MySQL visible"
    else
      testkit_doctor_add_check base FAIL MYSQL_DB_MISSING "falta nombre de DB MySQL (DB_NAME / TEST_MYSQL_DB / MYSQL_DATABASE)" \
        "Declará la DB runtime del store."
      ok_ref=0
    fi

    if testkit_doctor_env_present_any DB_USER TEST_MYSQL_USER MYSQL_USER; then
      testkit_doctor_add_check base PASS MYSQL_USER_PRESENT "usuario runtime MySQL visible"
    else
      testkit_doctor_add_check base FAIL MYSQL_USER_MISSING "falta usuario runtime MySQL (DB_USER / TEST_MYSQL_USER / MYSQL_USER)" \
        "Declará el usuario runtime del store."
      ok_ref=0
    fi

    if testkit_doctor_env_present_any DB_PASS TEST_MYSQL_PASSWORD MYSQL_PASSWORD; then
      testkit_doctor_add_check base PASS MYSQL_PASSWORD_PRESENT "password runtime MySQL visible"
    else
      testkit_doctor_add_check base FAIL MYSQL_PASSWORD_MISSING "falta password runtime MySQL (DB_PASS / TEST_MYSQL_PASSWORD / MYSQL_PASSWORD)" \
        "Declará la credencial runtime del store."
      ok_ref=0
    fi

    if [[ "${provision_mode}" == "managed" ]]; then
      if testkit_doctor_env_present_any TEST_MYSQL_ADMIN_USER MYSQL_ROOT_USER; then
        testkit_doctor_add_check base PASS MYSQL_ADMIN_USER_PRESENT "usuario admin MySQL visible (managed)"
      else
        testkit_doctor_add_check base FAIL MYSQL_ADMIN_USER_MISSING \
          "falta usuario admin MySQL (TEST_MYSQL_ADMIN_USER / MYSQL_ROOT_USER)" \
          "Declará la credencial admin para provision managed."
        ok_ref=0
      fi

      if testkit_doctor_env_present_any TEST_MYSQL_ROOT_PASSWORD MYSQL_ROOT_PASSWORD; then
        testkit_doctor_add_check base PASS MYSQL_ADMIN_PASSWORD_PRESENT "password admin MySQL visible (managed)"
      else
        testkit_doctor_add_check base FAIL MYSQL_ADMIN_PASSWORD_MISSING \
          "falta password admin MySQL (TEST_MYSQL_ROOT_PASSWORD / MYSQL_ROOT_PASSWORD)" \
          "Declará la credencial admin para provision managed."
        ok_ref=0
      fi
    else
      testkit_doctor_add_check base PASS EXTERNAL_PROVISION_PATH \
        "TEST_STORE_PROVISION=external: no se exigen credenciales admin MySQL"
    fi
  elif [[ "${store_driver}" == "pgsql" ]]; then
    testkit_doctor_add_check base WARN NON_MYSQL_BASE_CHECKS_PARTIAL \
      "doctor base no cierra validación de credenciales específicas para driver=pgsql" \
      "Completá checks específicos del motor si querés endurecer este path."
  elif [[ -z "${store_driver}" ]]; then
    testkit_doctor_add_check base FAIL STORE_DRIVER_REQUIRED \
      "TEST_STORE_DRIVER es obligatorio" \
      "Usá exactamente mysql, pgsql o none."
    ok_ref=0
  else
    testkit_doctor_add_check base FAIL INVALID_STORE_DRIVER \
      "TEST_STORE_DRIVER=${store_driver} no pertenece al contrato" \
      "Usá exactamente mysql, pgsql o none."
    ok_ref=0
  fi
}
