#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /testkit/scripts/seed.sh
# Aplica seeds del proyecto dentro del contenedor TestKit.
# Lee SQL desde <project>/test/seeds/{mysql,pgsql}.
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
BASE_DB="${TEST_MYSQL_DB:-app_test}"
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"

mk_db_name() { local w="$1"; printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"; }

apply_mysql_shared() {
  echo "==> Seeding MySQL (shared)…"
  "$TK" run --rm testkit php scripts/seed_router.php mysql
}

apply_mysql_db() {
  local db="$1"
  echo "==> Seeding MySQL DB: $db"
  "$TK" run --rm -e DB_NAME="$db" -e TEST_MYSQL_DB="$db" testkit php scripts/seed_router.php mysql
}

apply_pg_shared() {
  echo "==> Seeding Postgres…"
  "$TK" run --rm testkit php scripts/seed_router.php pgsql
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  [[ "$JOBS" -lt 1 ]] && JOBS=1
  for ((i=1;i<=JOBS;i++)); do apply_mysql_db "$(mk_db_name "$i")"; done
else
  apply_mysql_shared
fi

if "$TK" ps --services | grep -q '^postgres_test$'; then
  apply_pg_shared
else
  echo "==> Postgres no activo (ok)."
fi
