#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /test/scripts/seed.sh
# Seeds en docker (mysql_test). Postgres opcional.
# =============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."  # <repo>/test

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

STRATEGY="${TEST_DB_STRATEGY:-shared}"
JOBS="${TEST_JOBS:-1}"
BASE_DB="${TEST_MYSQL_DB:-app_test}"
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"

mk_db_name() { local w="$1"; printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"; }

mysql_admin_exec() { "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'; }
mysql_exec_db() { local db="$1"; "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$db"; }

seed_one_db() {
  local db="$1"
  [[ ! "$db" =~ ^[A-Za-z0-9_]+$ ]] && { echo "DB inválida: $db" >&2; exit 1; }

  echo "==> Seeding MySQL DB: $db"
  printf 'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" | mysql_admin_exec

  shopt -s nullglob
  for f in seeds/mysql/*.sql; do
    echo "   - $f"
    mysql_exec_db "$db" < "$f"
  done
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  [[ "$JOBS" -lt 1 ]] && JOBS=1
  for ((i=1;i<=JOBS;i++)); do seed_one_db "$(mk_db_name "$i")"; done
else
  echo "==> Seeding MySQL (shared)…"
  shopt -s nullglob
  for f in seeds/mysql/*.sql; do
    echo "   - $f"
    "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$f"
  done
fi

if "$TK" ps --services | grep -q '^postgres_test$'; then
  echo "==> Seeding Postgres…"
  shopt -s nullglob
  for f in seeds/pgsql/*.sql; do
    echo "   - $f"
    "$TK" exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -' < "$f"
  done
else
  echo "==> Postgres no activo (ok)."
fi