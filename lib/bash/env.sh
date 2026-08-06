#!/usr/bin/env bash
set -euo pipefail

testkit_env_init() {
  TESTKIT_ROOT_HOST="${TESTKIT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
  TESTKIT_ROOT_HOST="$(cd "${TESTKIT_ROOT_HOST}" && pwd)"

  PROJECT_ROOT="${TESTKIT_PROJECT_ROOT:-}"
  if [[ -z "${PROJECT_ROOT}" ]]; then
    local cwd
    cwd="$(pwd)"
    if [[ -f "${cwd}/test/.env.test" || -f "${cwd}/.env.test" ]]; then
      PROJECT_ROOT="${cwd}"
    else
      PROJECT_ROOT="$(cd "${TESTKIT_ROOT_HOST}/.." && pwd)"
    fi
  fi
  PROJECT_ROOT="$(cd "${PROJECT_ROOT}" && pwd)"

  TESTKIT_HOST_UID="${TESTKIT_HOST_UID:-$(id -u)}"
  TESTKIT_HOST_GID="${TESTKIT_HOST_GID:-$(id -g)}"

  export TESTKIT_ROOT_HOST PROJECT_ROOT TESTKIT_HOST_UID TESTKIT_HOST_GID
}

testkit_pick_env_file() {
  local override="${TESTKIT_ENV_FILE:-}"
  if [[ -n "${override}" ]]; then
    if [[ -f "${override}" ]]; then
      echo "${override}"
      return 0
    fi
    return 1
  fi

  local preferred="${PROJECT_ROOT}/test/.env.test"
  local fallback="${PROJECT_ROOT}/.env.test"
  if [[ -f "${preferred}" ]]; then
    echo "${preferred}"
    return 0
  fi
  if [[ -f "${fallback}" ]]; then
    echo "${fallback}"
    return 0
  fi
  return 1
}

testkit_env_file_to_container_path() {
  local env_file="$1"
  if [[ "${env_file}" == "${PROJECT_ROOT}/.env.test" ]]; then
    echo "/workspace/project/.env.test"
    return 0
  fi
  local rel="${env_file#"${PROJECT_ROOT}/"}"
  echo "/workspace/project/${rel}"
}

testkit_load_env_kv_safe() {
  local f="$1"
  [[ ! -f "${f}" ]] && return 1
  while IFS= read -r line; do
    [[ "${line}" =~ ^[[:space:]]*# ]] && continue
    [[ "${line}" =~ ^[[:space:]]*$ ]] && continue
    if [[ "${line}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      local key="${line%%=*}"
      [[ -v "${key}" ]] || export "${line}"
    fi
  done < "${f}"
}

testkit_path_is_under_root() {
  local root="$1"
  local target="$2"
  [[ "${target}" == "${root}" || "${target}" == "${root}/"* ]]
}
