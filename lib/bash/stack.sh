#!/usr/bin/env bash
set -euo pipefail

testkit_normalize_stack_csv() {
  local raw="${1:-}"
  local fallback="mysql,redis"
  if [[ -z "${raw//[[:space:]]/}" ]]; then
    local store_driver
    store_driver="$(echo "${TEST_STORE_DRIVER:-}" | tr '[:upper:]' '[:lower:]' | xargs)"
    if [[ "${store_driver}" == "none" ]]; then
      echo ""
      return 0
    fi
    raw="${fallback}"
  fi

  local out=()
  local seen=","
  local IFS=','
  read -r -a parts <<< "${raw}"
  for part in "${parts[@]}"; do
    local token
    token="$(echo "${part}" | tr '[:upper:]' '[:lower:]' | xargs)"
    [[ -z "${token}" ]] && continue
    case "${token}" in
      mysql|redis|pg|postgres|postgresql|influx|influxdb|mailpit|smtp)
        [[ "${token}" == "postgres" || "${token}" == "postgresql" ]] && token="pg"
        [[ "${token}" == "influxdb" ]] && token="influx"
        [[ "${token}" == "smtp" ]] && token="mailpit"
        if [[ "${seen}" != *",${token},"* ]]; then
          out+=("${token}")
          seen+="${token},"
        fi
        ;;
      *)
        echo "TESTKIT_STACK inválido: token no reconocido '${token}'. Valores válidos: mysql, redis, pg, influx, mailpit" >&2
        return 1
        ;;
    esac
  done

  if [[ ${#out[@]} -eq 0 && -n "${raw//[[:space:],]/}" ]]; then
    out=(mysql redis)
  fi

  local joined=""
  local i
  for i in "${!out[@]}"; do
    [[ $i -gt 0 ]] && joined+=","
    joined+="${out[$i]}"
  done
  echo "${joined}"
}

testkit_stack_has() {
  local csv="$1"
  local token="$2"
  [[ ",${csv}," == *",${token},"* ]]
}

testkit_resolve_compose_files() {
  local stack_csv="$1"
  local -n out_ref=$2
  local root="${TESTKIT_ROOT_HOST}"

  out_ref=(-f "${root}/compose.yaml")

  if testkit_stack_has "${stack_csv}" "mysql"; then
    out_ref+=(-f "${root}/compose.mysql.yaml")
  fi

  if testkit_stack_has "${stack_csv}" "redis"; then
    out_ref+=(-f "${root}/compose.redis.yaml")
  fi

  if testkit_stack_has "${stack_csv}" "pg"; then
    out_ref+=(-f "${root}/compose.pg.yaml")
  fi

  if testkit_stack_has "${stack_csv}" "influx"; then
    out_ref+=(-f "${root}/compose.influx.yaml")
  fi

  if testkit_stack_has "${stack_csv}" "mailpit"; then
    out_ref+=(-f "${root}/compose.mailpit.yaml")
  fi

  return 0
}
