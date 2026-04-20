#!/usr/bin/env bash
set -euo pipefail

testkit_run_main() {
  testkit_env_init

  if [[ "${1:-}" == "doctor" ]]; then
    shift || true
    testkit_doctor "$@"
    return $?
  fi

  local env_file
  env_file="$(testkit_pick_env_file || true)"
  if [[ -z "${env_file}" || ! -f "${env_file}" ]]; then
    echo "Falta env de tests en el proyecto. Creá <project>/test/.env.test o <project>/.env.test." >&2
    return 1
  fi
  env_file="$(cd "$(dirname "${env_file}")" && pwd)/$(basename "${env_file}")"
  if ! testkit_path_is_under_root "${PROJECT_ROOT}" "${env_file}"; then
    echo "El env de tests quedó fuera del repo montado: ${env_file}" >&2
    return 1
  fi

  testkit_load_env_kv_safe "${env_file}" || true

  local legacy_pg_flag=0
  if [[ "${1:-}" == "--pg" ]]; then
    legacy_pg_flag=1
    shift || true
  fi

  local stack_csv
  stack_csv="$(testkit_normalize_stack_csv "${TESTKIT_STACK:-}")" || return 1
  if [[ "${legacy_pg_flag}" -eq 1 ]] && ! testkit_stack_has "${stack_csv}" "pg"; then
    stack_csv="${stack_csv},pg"
  fi

  export TESTKIT_DB_ENV_PATH
  TESTKIT_DB_ENV_PATH="$(testkit_env_file_to_container_path "${env_file}")"
  export TESTKIT_PROJECT_ROOT="${PROJECT_ROOT}"
  export TESTKIT_ROOT="${TESTKIT_ROOT_HOST}"

  local files=()
  testkit_resolve_compose_files "${stack_csv}" files

  if [[ "${1:-}" == "inspect" ]]; then
    shift || true
    docker compose --env-file "${env_file}" "${files[@]}" run --rm \
      -e TESTKIT_WRAPPER_KIND="${TESTKIT_WRAPPER_KIND:-bash}" \
      testkit php /workspace/testkit/scripts/inspect.php "$@"
    return $?
  fi

  local rewritten=()
  mapfile -d '' -t rewritten < <(testkit_rewrite_run_command_args "$@")
  docker compose --env-file "${env_file}" "${files[@]}" "${rewritten[@]}"
}
