#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /testkit/scripts/seed.sh
# Aplica la base estructural del proyecto por store dentro del contenedor TestKit.
# Lifecycle: bootstrap(store) -> reset -> schema -> base -> migrations -> validations.
# TEST_STORE_DRIVER es el único selector de store.
# =============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."

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
    if [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      local key="${line%%=*}"
      [[ -v "$key" ]] || export "$line"
    fi
  done < "$f"
}

ENV_FILE="$(pick_env_file || true)"
[[ -z "${ENV_FILE:-}" ]] && { echo "Falta env de tests del proyecto." >&2; exit 1; }
load_env_kv_safe "$ENV_FILE" || true

DRIVER="${TEST_STORE_DRIVER:-}"
if [[ -z "$DRIVER" ]]; then
  echo "[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Usá mysql|pgsql|none." >&2
  exit 2
fi
case "$DRIVER" in
  mysql|pgsql) ;;
  none)
    echo "==> TEST_STORE_DRIVER=none: bootstrap estructural no aplica."
    exit 0
    ;;
  *)
    echo "[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='$DRIVER' no es válido. Valores exactos: mysql|pgsql|none." >&2
    exit 2
    ;;
esac

STRATEGY="${TEST_DB_STRATEGY:-shared}"
JOBS="${TEST_JOBS:-1}"
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"

bootstrap_driver() {
  local driver="$1"
  echo "==> Bootstrapping store: $driver (strategy=$STRATEGY, jobs=$JOBS)"
  "$TK" run --rm \
    -e "TEST_STORE_DRIVER=$driver" \
    -e "TEST_DB_STRATEGY=$STRATEGY" \
    -e "TEST_JOBS=$JOBS" \
    -e "TEST_DB_WORKER_SUFFIX_FORMAT=$SUFFIX_FMT" \
    testkit php /workspace/testkit/scripts/store_router.php bootstrap "$driver"
}

bootstrap_driver "$DRIVER"
