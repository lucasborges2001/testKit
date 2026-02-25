#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/local/seed.sh
#
# Aplica schema (opcional) + seeds (fixtures) en MySQL LOCAL.
#
# Requiere que la DB exista (creada por db_reset dropdb).
# No hace DROP/CREATE.
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/_lib.sh"

IFS="|" read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"

# Env opcional
if ENV_FILE="$(pick_env_file "$TEST_ROOT" "$REPO_ROOT" 2>/dev/null || true)"; then
  [[ -n "${ENV_FILE:-}" ]] && load_env_kv_safe "$ENV_FILE" || true
fi

MYSQL_BIN="${MYSQL_BIN:-mysql}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

BASE_DB="${DB_NAME:-${TEST_MYSQL_DB:-app_test}}"

STRATEGY="$(get_db_strategy)"
JOBS="$(get_jobs)"
FMT="$(get_suffix_fmt)"

mysql_base=( "--protocol=tcp" "-h" "$DB_HOST" "-P" "$DB_PORT" "-u" "$DB_USER" )
[[ -n "$DB_PASS" ]] && mysql_base+=( "-p$DB_PASS" )

SCHEMA_DIR="$TEST_ROOT/schema/mysql"
SEEDS_DIR="$TEST_ROOT/seeds/mysql"

assert_db_name(){
  [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB invalida: $1 (solo [A-Za-z0-9_])"
}

assert_db_exists(){
  local db="$1"; assert_db_name "$db"
  local exists
  exists="$("$MYSQL_BIN" "${mysql_base[@]}" -Nse "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME="$db";")"
  [[ -n "${exists//[[:space:]]/}" ]] || fail "La DB $db no existe. Ejecuta primero db_reset.sh dropdb."
}

apply_dir(){
  local db="$1" dir="$2" label="$3"
  [[ -d "$dir" ]] || return 0
  local f
  for f in "$dir"/*.sql; do
    [[ -f "$f" ]] || continue
    log "==> $label: $(basename "$f")"
    "$MYSQL_BIN" "${mysql_base[@]}" "$db" < "$f"
  done
}

seed_one(){
  local db="$1"
  log "==> Seeding MySQL DB: $db"
  assert_db_exists "$db"
  apply_dir "$db" "$SCHEMA_DIR" "schema"
  apply_dir "$db" "$SEEDS_DIR"  "seed"
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  for ((i=1; i<=JOBS; i++)); do
    db="$(mk_db_name "$BASE_DB" "$FMT" "$i")"
    seed_one "$db"
  done
else
  seed_one "$BASE_DB"
fi
