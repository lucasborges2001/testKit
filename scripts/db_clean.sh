#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

find_testkit() {
  local override="${TESTKIT_BIN:-}"
  if [[ -n "$override" && -x "$override" ]]; then echo "$override"; return 0; fi
  if [[ -x "./bin/testkit" ]]; then echo "./bin/testkit"; return 0; fi
  if [[ -x "../bin/testkit" ]]; then echo "../bin/testkit"; return 0; fi
  echo "No se encontró bin/testkit. Seteá TESTKIT_BIN o instalá TestKit." >&2
  return 1
}

TK="$(find_testkit)"

pick_env_file() {
  local override="${TESTKIT_ENV_FILE:-}"
  if [[ -n "$override" && -f "$override" ]]; then echo "$override"; return 0; fi
  if [[ -n "${TESTKIT_PROJECT_ROOT:-}" && -f "${TESTKIT_PROJECT_ROOT}/test/.env.test" ]]; then echo "${TESTKIT_PROJECT_ROOT}/test/.env.test"; return 0; fi
  if [[ -n "${TESTKIT_PROJECT_ROOT:-}" && -f "${TESTKIT_PROJECT_ROOT}/.env.test" ]]; then echo "${TESTKIT_PROJECT_ROOT}/.env.test"; return 0; fi
  if [[ -f "./.env.test" ]]; then echo "./.env.test"; return 0; fi
  if [[ -f "../.env.test" ]]; then echo "../.env.test"; return 0; fi
  return 1
}

load_env_kv_safe() {
  local f="$1"
  [[ ! -f "$f" ]] && return 1
  while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$line"
  done < "$f"
}

ENV_FILE="$(pick_env_file || true)"
[[ -z "${ENV_FILE:-}" ]] && { echo "Falta env de tests." >&2; exit 1; }
load_env_kv_safe "$ENV_FILE" || true

DRIVER="${TEST_STORE_DRIVER:-}"
if [[ -z "$DRIVER" ]]; then
  echo "[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Usá mysql|pgsql|none." >&2
  exit 2
fi
case "$DRIVER" in
  mysql|pgsql) ;;
  none)
    echo "==> TEST_STORE_DRIVER=none: limpieza estructural no aplica."
    exit 0
    ;;
  *)
    echo "[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='$DRIVER' no es válido. Valores exactos: mysql|pgsql|none." >&2
    exit 2
    ;;
esac

STRATEGY="${TEST_DB_STRATEGY:-shared}"
JOBS="${TEST_JOBS:-1}"
BASE_DB="${TEST_MYSQL_DB:-${DB_NAME:-app_test}}"
if [[ "$DRIVER" == "pgsql" ]]; then
  BASE_DB="${TEST_PG_DB:-${PG_DB:-app_test}}"
fi
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"

MODE="base"
WORKER="1"
if [[ "${1:-}" == "--worker" && -n "${2:-}" ]]; then MODE="worker"; WORKER="$2"; shift 2
elif [[ "${1:-}" == "--all" ]]; then MODE="all"; shift; fi

mk_db_name() { local w="$1"; printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"; }

clean_db() {
  local db="$1"
  echo "==> Cleaning ${DRIVER} DB: ${db}"

  local env_args=(-e "TEST_STORE_DRIVER=$DRIVER")
  if [[ "$DRIVER" == "pgsql" ]]; then
    env_args+=(-e PG_DB="$db" -e TEST_PG_DB="$db")
  else
    env_args+=(-e DB_NAME="$db" -e TEST_MYSQL_DB="$db")
  fi

  "$TK" run --rm "${env_args[@]}" testkit php /workspace/testkit/scripts/store_router.php clean "$DRIVER"
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  if [[ "$MODE" == "all" ]]; then for ((i=1;i<=JOBS;i++)); do clean_db "$(mk_db_name "$i")"; done
  elif [[ "$MODE" == "worker" ]]; then clean_db "$(mk_db_name "$WORKER")"
  else clean_db "$(mk_db_name 1)"; fi
else
  clean_db "$BASE_DB"
fi
