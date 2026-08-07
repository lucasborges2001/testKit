#!/usr/bin/env bash
set -euo pipefail

_testkit_doctor_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/doctor"

# shellcheck source=/dev/null
source "${_testkit_doctor_dir}/shared.sh"
# shellcheck source=/dev/null
source "${_testkit_doctor_dir}/base_checks.sh"
# shellcheck source=/dev/null
source "${_testkit_doctor_dir}/capability_checks.sh"
# shellcheck source=/dev/null
source "${_testkit_doctor_dir}/contract_registry.sh"
# shellcheck source=/dev/null
source "${_testkit_doctor_dir}/render.sh"

testkit_doctor_validate_store_driver() {
  local driver="${TEST_STORE_DRIVER:-}"
  if [[ -z "${driver}" ]]; then
    echo "[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Valores exactos: mysql|pgsql|none." >&2
    return 1
  fi

  case "${driver}" in
    mysql|pgsql|none) return 0 ;;
    *)
      echo "[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='${driver}' no es válido. Valores exactos: mysql|pgsql|none." >&2
      return 1
      ;;
  esac
}

testkit_doctor_run() {
  local ok=1

  testkit_doctor_reset_state

  ENV_FILE="$(testkit_pick_env_file || true)"
  if [[ -n "${ENV_FILE}" && -f "${ENV_FILE}" ]]; then
    ENV_FILE="$(cd "$(dirname "${ENV_FILE}")" && pwd)/$(basename "${ENV_FILE}")"
    testkit_load_env_kv_safe "${ENV_FILE}" || true
  fi

  testkit_doctor_parse_args "$@" || return 1

  if [[ -n "${ENV_FILE:-}" && -f "${ENV_FILE:-}" ]]; then
    testkit_doctor_validate_store_driver || return 1
  fi

  TESTKIT_STACK_EFFECTIVE="$(testkit_normalize_stack_csv "${TESTKIT_STACK:-}")" || {
    echo "TESTKIT_STACK inválido. Corregí TESTKIT_STACK antes de correr doctor." >&2
    return 1
  }

  testkit_doctor_run_base_checks ok
  if [[ -n "${ENV_FILE:-}" && -f "${ENV_FILE:-}" ]]; then
    testkit_doctor_run_capability_checks "${TESTKIT_DOCTOR_TARGET}"
  fi

  case "${TESTKIT_DOCTOR_MODE}" in
    compact) testkit_doctor_render_compact ;;
    *) testkit_doctor_render_full ;;
  esac

  if [[ "${TESTKIT_DOCTOR_DUMP}" == "1" && -n "${ENV_FILE:-}" && -f "${ENV_FILE:-}" ]]; then
    export TESTKIT_DB_ENV_PATH
    TESTKIT_DB_ENV_PATH="$(testkit_env_file_to_container_path "${ENV_FILE}")"
    export TESTKIT_PROJECT_ROOT="${PROJECT_ROOT}"
    export TESTKIT_ROOT="${TESTKIT_ROOT_HOST}"
    testkit_doctor_render_dump
  fi

  if [[ "${ok}" -eq 1 ]]; then
    return 0
  fi

  return 1
}
