#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/docker/seed.sh
#
# Aplica schema (opcional) + seeds (fixtures) en MySQL dentro de Docker (mysql_test).
# Postgres: si el servicio postgres_test está levantado, aplica seeds/pgsql (si existe).
#
# Convención:
# - Schema MySQL (opcional): test/schema/mysql/*.sql
# - Seeds  MySQL (fixtures):  test/seeds/mysql/*.sql
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../_lib.sh"

IFS='|' read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"

# Env
ENV_FILE=""
if ENV_FILE="$(pick_env_file "$TEST_ROOT" "$REPO_ROOT")"; then
  load_env_kv_safe "$ENV_FILE" || true
else
  fail "Falta env de tests. Se busca: <repo>/test/.env.test (preferido) o <repo>/.env.test."
fi

STRATEGY="$(get_db_strategy)"
JOBS="$(get_jobs)"
BASE_DB="$(get_base_db)"
FMT="$(get_suffix_fmt)"

TK="$(find_testkit "$TEST_ROOT" "$REPO_ROOT")"
cd "$TEST_ROOT"

SCHEMA_DIR="$TEST_ROOT/schema/mysql"
SEEDS_DIR="$TEST_ROOT/seeds/mysql"

mysql_admin_exec(){ "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'; }
mysql_exec_db(){ local db="$1"; "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$db"; }

ensure_db(){
  local db="$1"
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB inválida: $db"
  printf 'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" | mysql_admin_exec
}

apply_sql_dir(){
  local db="$1" dir="$2" label="$3"
  [[ -d "$dir" ]] || return 0
  while IFS= read -r f; do
    log "==> $label: $(basename "$f")"
    mysql_exec_db "$db" < "$f"
  done < <(find "$dir" -maxdepth 1 -type f -name "*.sql" | sort)
}

seed_one_db(){
  local db="$1"
  log "==> Seeding MySQL DB: $db"
  ensure_db "$db"
  apply_sql_dir "$db" "$SCHEMA_DIR" "schema"
  apply_sql_dir "$db" "$SEEDS_DIR"  "seed"
}

# -----------------------------------------------------------------------------
# 1) MySQL
# -----------------------------------------------------------------------------
if [[ "$STRATEGY" == "per_worker" ]]; then
  for ((i=1;i<=JOBS;i++)); do
    seed_one_db "$(mk_db_name "$BASE_DB" "$FMT" "$i")"
  done
else
  seed_one_db "$BASE_DB"
fi

# -----------------------------------------------------------------------------
# 2) Postgres (opcional)
# -----------------------------------------------------------------------------
if "$TK" ps --services | grep -q '^postgres_test$'; then
  PG_DIR="$TEST_ROOT/seeds/pgsql"
  if [[ -d "$PG_DIR" ]]; then
    log "==> Seeding Postgres…"
    while IFS= read -r f; do
      log "==> pg seed: $(basename "$f")"
      "$TK" exec -T postgres_test sh -lc 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -' < "$f"
    done < <(find "$PG_DIR" -maxdepth 1 -type f -name "*.sql" | sort)
  else
    log "==> Postgres activo, pero no hay seeds/pgsql (ok)."
  fi
else
  log "==> Postgres no activo (ok)."
fi
