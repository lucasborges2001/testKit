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
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"
SERVICES="$("$TK" ps --services 2>/dev/null || true)"

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

# La política de lifecycle (strategy, naming de DB, per-worker loop, baseline clone)
# vive en ContractWorldBootstrap. store_router.php bootstrap delega a él.
# seed.sh solo detecta el driver y lanza una única invocación.
bootstrap_driver() {
  local driver="$1"
  echo "==> Bootstrapping store: $driver (strategy=$STRATEGY, jobs=$JOBS)"
  "$TK" run --rm \
    -e "TEST_DB_STRATEGY=$STRATEGY" \
    -e "TEST_JOBS=$JOBS" \
    -e "TEST_DB_WORKER_SUFFIX_FORMAT=$SUFFIX_FMT" \
    testkit php /workspace/testkit/scripts/store_router.php bootstrap "$driver"
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
