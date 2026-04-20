#!/usr/bin/env bash
set -euo pipefail

testkit_doctor() {
  local ok=1
  local env_file
  env_file="$(testkit_pick_env_file || true)"

  echo ""
  echo "== TESTKIT DOCTOR =="

  if [[ -n "${env_file}" && -f "${env_file}" ]]; then
    env_file="$(cd "$(dirname "${env_file}")" && pwd)/$(basename "${env_file}")"
    echo "[OK] env: ${env_file}"
    testkit_load_env_kv_safe "${env_file}" || true
  else
    echo "[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root)."
    ok=0
  fi

  local stack_csv
  stack_csv="$(testkit_normalize_stack_csv "${TESTKIT_STACK:-}")" || return 1
  echo "[INFO] TESTKIT_STACK=${stack_csv}"
  echo "[INFO] TESTKIT_ROOT(host)=${TESTKIT_ROOT_HOST}"
  echo "[INFO] TESTKIT_PROJECT_ROOT(host)=${PROJECT_ROOT}"
  echo "[INFO] TESTKIT_HOST_UID:GID=${TESTKIT_HOST_UID}:${TESTKIT_HOST_GID}"

  [[ -f "${TESTKIT_ROOT_HOST}/runTest.php" ]] || { echo "[FAIL] TESTKIT_ROOT no parece repo completo"; ok=0; }
  [[ -d "${PROJECT_ROOT}" ]] || { echo "[FAIL] TESTKIT_PROJECT_ROOT no existe"; ok=0; }

  if [[ "${ok}" -eq 1 ]]; then
    echo ""
    echo "Doctor: OK"
    return 0
  fi

  echo ""
  echo "Doctor: FAIL (ver arriba)" >&2
  return 1
}
