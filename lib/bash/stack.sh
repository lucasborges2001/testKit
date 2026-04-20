#!/usr/bin/env bash
set -euo pipefail

testkit_normalize_stack_csv() {
  local raw="${1:-}"
  local fallback="mysql,redis"
  if [[ -z "${raw//[[:space:]]/}" ]]; then
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
      mysql|redis|pg|postgres|postgresql|influx|influxdb)
        [[ "${token}" == "postgres" || "${token}" == "postgresql" ]] && token="pg"
        [[ "${token}" == "influxdb" ]] && token="influx"
        if [[ "${seen}" != *",${token},"* ]]; then
          out+=("${token}")
          seen+="${token},"
        fi
        ;;
      *)
        echo "TESTKIT_STACK inválido: token no reconocido '${token}'. Valores válidos: mysql, redis, pg, influx" >&2
        return 1
        ;;
    esac
  done

  if [[ ${#out[@]} -eq 0 ]]; then
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
  testkit_stack_has "${stack_csv}" "mysql" && out_ref+=(-f "${root}/compose.mysql.yaml")
  testkit_stack_has "${stack_csv}" "redis" && out_ref+=(-f "${root}/compose.redis.yaml")
  testkit_stack_has "${stack_csv}" "pg" && out_ref+=(-f "${root}/compose.pg.yaml")
  testkit_stack_has "${stack_csv}" "influx" && out_ref+=(-f "${root}/compose.influx.yaml")
}
