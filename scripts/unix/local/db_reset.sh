#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/local/db_reset.sh
#
# Reset de DB en modo LOCAL (MySQL + PHP instalados en el host).
#
# Este script NO aplica schema ni seeds.
# Orden recomendado:
#   1) db_reset.sh [heavy|dropdb|fast]
#   2) seed.sh
#   3) test.sh <target>
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/_lib.sh"

RESET_REQ="${1:-}"

IFS="|" read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"

# Env opcional
if ENV_FILE="$(pick_env_file "$TEST_ROOT" "$REPO_ROOT" 2>/dev/null || true)"; then
  [[ -n "${ENV_FILE:-}" ]] && load_env_kv_safe "$ENV_FILE" || true
fi

RESET="$(resolve_reset_mode "$RESET_REQ")"
[[ "$RESET" == "heavy" ]] && RESET="dropdb"

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

log "==> LOCAL MySQL: $DB_USER@$DB_HOST:$DB_PORT / base_db=$BASE_DB / reset=$RESET"

assert_db_name(){
  [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB invalida: $1 (solo [A-Za-z0-9_])"
}

drop_create(){
  local db="$1"; assert_db_name "$db"
  "$MYSQL_BIN" "${mysql_base[@]}" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

truncate_all(){
  local db="$1"; assert_db_name "$db"

  local tables
  tables="$("$MYSQL_BIN" "${mysql_base[@]}" "$db" -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE";")"

  [[ -z "${tables//[[:space:]]/}" ]] && { log "==> fast: no hay tablas en $db (ok)"; return 0; }

  local sql="SET FOREIGN_KEY_CHECKS=0;
"
  while IFS= read -r t; do
    t="${t//[$rnt]/}"
    [[ -z "$t" ]] && continue
    [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || continue
    sql+="TRUNCATE TABLE \`$t\`;\n"
  done <<<"$tables"
  sql+="SET FOREIGN_KEY_CHECKS=1;\n"

  printf "%b" "$sql" | "$MYSQL_BIN" "${mysql_base[@]}" "$db"
}

reset_one(){
  local db="$1"
  if [[ "$RESET" == "dropdb" ]]; then
    log "==> drop/create: $db"
    drop_create "$db"
  else
    log "==> fast(truncate): $db"
    truncate_all "$db"
  fi
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  for ((i=1; i<=JOBS; i++)); do
    db="$(mk_db_name "$BASE_DB" "$FMT" "$i")"
    reset_one "$db"
  done
else
  reset_one "$BASE_DB"
fi
