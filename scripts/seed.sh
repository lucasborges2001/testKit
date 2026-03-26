#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /testkit/scripts/seed.sh
# Aplica la base estructural del proyecto por store dentro del contenedor TestKit.
# Lifecycle: bootstrap(store) -> reset -> schema -> base -> migrations -> validations.
# =============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."  # <testkit>

find_testkit() {
  local override="${TESTKIT_BIN:-}"
  if [[ -n "$override" && -x "$override" ]]; then echo "$override"; return 0; fi
  if [[ -x "./bin/testkit" ]]; then echo "./bin/testkit"; return 0; fi
  echo "No se encontró bin/testkit. Seteá TESTKIT_BIN o instalá TestKit." >&2
  return 1
}

TK="$(find_testkit)"

pick_env_file() {
  local override="${TESTKIT_ENV_FILE:-}"
  if [[ -n "$override" && -f "$override" ]]; then echo "$override"; return 0; fi
  if [[ -n "${TESTKIT_PROJECT_ROOT:-}" && -f "${TESTKIT_PROJECT_ROOT}/test/.env.test" ]]; then echo "${TESTKIT_PROJECT_ROOT}/test/.env.test"; return 0; fi
  if [[ -n "${TESTKIT_PROJECT_ROOT:-}" && -f "${TESTKIT_PROJECT_ROOT}/.env.test" ]]; then echo "${TESTKIT_PROJECT_ROOT}/.env.test"; return 0; fi
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
[[ -z "${ENV_FILE:-}" ]] && { echo "Falta env de tests del proyecto." >&2; exit 1; }
load_env_kv_safe "$ENV_FILE" || true

STRATEGY="${TEST_DB_STRATEGY:-shared}"
JOBS="${TEST_JOBS:-1}"
BASE_MYSQL_DB="${TEST_MYSQL_DB:-app_test}"
BASE_PG_DB="${TEST_PG_DB:-app_test}"
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"
SERVICES="$("$TK" ps --services 2>/dev/null || true)"

mk_db_name() {
  local base="$1"
  local w="$2"
  printf "%s" "${base}$(printf "$SUFFIX_FMT" "$w")"
}

normalize_driver() {
  local driver="${1,,}"
  if [[ "$driver" == pg* ]]; then
    printf '%s\n' "pgsql"
    return 0
  fi
  printf '%s\n' "mysql"
}

has_service() {
  local name="$1"
  grep -q "^${name}$" <<<"$SERVICES"
}

driver_base_db() {
  local driver="$1"
  if [[ "$driver" == "pgsql" ]]; then
    printf '%s\n' "${TEST_PG_DB:-${PG_DB:-$BASE_PG_DB}}"
    return 0
  fi
  printf '%s\n' "${DB_NAME:-$BASE_MYSQL_DB}"
}

store_env_args() {
  local driver="$1"
  local db="$2"
  if [[ "$driver" == "pgsql" ]]; then
    printf '%s\0' -e "PG_DB=$db" -e "TEST_PG_DB=$db"
    return 0
  fi
  printf '%s\0' -e "DB_NAME=$db" -e "TEST_MYSQL_DB=$db"
}

bootstrap_store_shared() {
  local driver="$1"
  echo "==> Bootstrapping store: $driver (shared)"
  "$TK" run --rm testkit php /workspace/testkit/scripts/store_router.php bootstrap "$driver"
}

bootstrap_store_db() {
  local driver="$1"
  local db="$2"
  local env_args=()
  echo "==> Bootstrapping store: $driver / db=$db"
  while IFS= read -r -d '' item; do
    env_args+=("$item")
  done < <(store_env_args "$driver" "$db")
  "$TK" run --rm "${env_args[@]}" testkit php /workspace/testkit/scripts/store_router.php bootstrap "$driver"
}

bootstrap_driver() {
  local driver="$1"
  local base_db
  base_db="$(driver_base_db "$driver")"
  if [[ "$STRATEGY" == "per_worker" ]]; then
    [[ "$JOBS" -lt 1 ]] && JOBS=1
    for ((i=1;i<=JOBS;i++)); do
      bootstrap_store_db "$driver" "$(mk_db_name "$base_db" "$i")"
    done
  else
    bootstrap_store_shared "$driver"
  fi
}

configured_driver="$(normalize_driver "${TEST_DB_DRIVER:-${DB_DRIVER:-}}")"
if [[ -n "${TEST_DB_DRIVER:-${DB_DRIVER:-}}" ]]; then
  bootstrap_driver "$configured_driver"
  exit 0
fi

drivers=()
if has_service "mysql_test"; then drivers+=("mysql"); fi
if has_service "postgres_test"; then drivers+=("pgsql"); fi
if [[ "${#drivers[@]}" -eq 0 ]]; then drivers=("mysql"); fi

for driver in "${drivers[@]}"; do
  bootstrap_driver "$driver"
done
