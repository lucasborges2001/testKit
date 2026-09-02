#!/usr/bin/env bash
set -euo pipefail

declare -ag TESTKIT_ENV_OVERRIDES=()
declare -ag TESTKIT_ENV_FILE_KEYS=()
TESTKIT_ENV_FILE_PATH=""

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
  TESTKIT_ENV_FILE_PATH="${f}"
  TESTKIT_ENV_OVERRIDES=()
  TESTKIT_ENV_FILE_KEYS=()
  while IFS= read -r line; do
    [[ "${line}" =~ ^[[:space:]]*# ]] && continue
    [[ "${line}" =~ ^[[:space:]]*$ ]] && continue
    if [[ "${line}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      local key="${line%%=*}"
      local file_value="${line#*=}"
      TESTKIT_ENV_FILE_KEYS+=("${key}")
      if [[ -v "${key}" ]]; then
        local current="${!key:-}"
        if [[ "${current}" != "${file_value}" ]]; then
          TESTKIT_ENV_OVERRIDES+=("${key}|process|file|${current}|${file_value}")
        fi
      else
        export "${line}"
      fi
    fi
  done < "${f}"
}

testkit_env_infrastructure_override_conflicts() {
  local encoded key from to current file_value
  for encoded in "${TESTKIT_ENV_OVERRIDES[@]}"; do
    IFS='|' read -r key from to current file_value <<< "${encoded}"
    case "${key}" in
      TESTKIT_STACK|TEST_STORE_DRIVER|TEST_STORE_PROVISION|COMPOSE_PROJECT_NAME|COMPOSE_FILE)
        echo "${encoded}"
      ;;
    esac
  done
  if [[ "${TESTKIT_STACK:-}" != "" && "${TEST_STORE_DRIVER:-}" == "none" ]]; then
    echo "TESTKIT_STACK|process|driver_none|${TESTKIT_STACK}|"
  fi
  if [[ "${COMPOSE_PROJECT_NAME:-}" != "" && "${TESTKIT_STACK:-}" != "" ]]; then
    echo "COMPOSE_PROJECT_NAME|process|compose_scope|${COMPOSE_PROJECT_NAME}|"
  fi
}

testkit_validate_env_overrides() {
  local conflicts
  conflicts="$(testkit_env_infrastructure_override_conflicts)"
  [[ -z "${conflicts}" ]] && return 0
  [[ "${TESTKIT_ALLOW_ENV_OVERRIDES:-0}" == "1" ]] && return 0

  if [[ "${TESTKIT_MODE:-}" == "agent" ]]; then
    echo "TESTKIT_ENV_OVERRIDE_CONFLICT: variables de infraestructura heredadas contradicen ${TESTKIT_ENV_FILE_PATH:-.env.test}. Usá TESTKIT_ALLOW_ENV_OVERRIDES=1 para un override intencional." >&2
    echo "${conflicts}" >&2
    return 1
  fi

  echo "WARN[TESTKIT_ENV_OVERRIDE_CONFLICT]: variables de infraestructura heredadas pisan ${TESTKIT_ENV_FILE_PATH:-.env.test}; TESTKIT_ALLOW_ENV_OVERRIDES=1 marca intención explícita." >&2
  echo "${conflicts}" >&2
  return 0
}

testkit_env_file_declares() {
  local wanted="$1"
  local key
  for key in "${TESTKIT_ENV_FILE_KEYS[@]}"; do
    [[ "${key}" == "${wanted}" ]] && return 0
  done
  return 1
}

testkit_path_is_under_root() {
  local root="$1"
  local target="$2"
  [[ "${target}" == "${root}" || "${target}" == "${root}/"* ]]
}
