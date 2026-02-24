#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

pick_env_file() {
  local override="${TESTKIT_ENV_FILE:-}"
  if [[ -n "$override" && -f "$override" ]]; then echo "$override"; return 0; fi
  if [[ -f "./.env.test" ]]; then echo "./.env.test"; return 0; fi
  if [[ -f "../.env.test" ]]; then echo "../.env.test"; return 0; fi
  echo ""; return 1
}

load_env_kv_safe() {
  local f="$1"
  [[ ! -f "$f" ]] && return 1
  while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    if [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      export "$line"
    fi
  done < "$f"
}

ENV_FILE="$(pick_env_file || true)"
if [[ -z "$ENV_FILE" ]]; then
  echo "Falta env de tests: test/.env.test (preferido) o .env.test (root)." >&2
  exit 1
fi

load_env_kv_safe "$ENV_FILE" || true

STRATEGY="${TEST_DB_STRATEGY:-shared}"
JOBS="${TEST_JOBS:-1}"
BASE_DB="${TEST_MYSQL_DB:-app_test}"
SUFFIX_FMT="${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"

mk_db_name() {
  local w="$1"
  printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"
}

mysql_exec() {
  # mysql_exec <db>
  ./bin/testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$1"
}

mysql_admin_exec() {
  # mysql_admin_exec (no default DB)
  ./bin/testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
}

seed_one_db() {
  local db="$1"
  if [[ ! "$db" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "DB name inválido: $db" >&2
    exit 1
  fi

  echo "==> Seeding MySQL DB: $db"

  # crear DB si no existe
  printf 'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" | mysql_admin_exec

  for f in $(ls -1 seeds/mysql/*.sql 2>/dev/null || true); do
    echo "   - $f"
    mysql_exec "$db" < "$f"
  done
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  # crea y seedea 1..TEST_JOBS
  if [[ "$JOBS" -lt 1 ]]; then JOBS=1; fi
  for ((i=1;i<=JOBS;i++)); do
    seed_one_db "$(mk_db_name "$i")"
  done
else
  echo "==> Seeding MySQL…"
  for f in $(ls -1 seeds/mysql/*.sql 2>/dev/null || true); do
    echo "   - $f"
    ./bin/testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$f"
  done
fi

# Postgres opcional: existe si levantaste con --pg
if ./bin/testkit ps --services | grep -q '^postgres_test$'; then
  echo "==> Seeding Postgres…"
  for f in $(ls -1 seeds/pgsql/*.sql 2>/dev/null || true); do
    echo "   - $f"
    ./bin/testkit exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -' < "$f"
  done
else
  echo "==> Postgres no activo (ok). Levantar con: ./bin/testkit --pg up -d"
fi
