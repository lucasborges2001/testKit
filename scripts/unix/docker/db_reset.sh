#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/docker/db_reset.sh
# Reset de DB en modo Docker (TestKit).
#
# Uso:
#   ./db_reset.sh [heavy|dropdb|fast]
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../_lib.sh"

RESET_REQ="${1:-}"

IFS='|' read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"

# Env opcional
if ENV_FILE="$(pick_env_file "$TEST_ROOT" "$REPO_ROOT" 2>/dev/null || true)"; then
  [[ -n "$ENV_FILE" ]] && load_env_kv_safe "$ENV_FILE" || true
fi

RESET="$(resolve_reset_mode "$RESET_REQ")"
TK="$(find_testkit "$TEST_ROOT" "$REPO_ROOT")"

cd "$TEST_ROOT"

ensure_up(){ "$TK" up -d; }

drop_create(){
  local db="$1"
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB inválida: '$db'"
  "$TK" exec -T mysql_test sh -lc "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
}

STRATEGY="$(get_db_strategy)"
JOBS="$(get_jobs)"
BASE_DB="$(get_base_db)"
FMT="$(get_suffix_fmt)"

if [[ "$RESET" == "heavy" ]]; then
  "$TK" down -v || warn "testkit down -v devolvió $? (continúo)"
  "$TK" up -d
  exit 0
fi

if [[ "$RESET" == "dropdb" ]]; then
  ensure_up
  if [[ "$STRATEGY" == "per_worker" ]]; then
    for ((i=1; i<=JOBS; i++)); do
      db="$(mk_db_name "$BASE_DB" "$FMT" "$i")"
      log "==> drop/create: $db"
      drop_create "$db"
    done
  else
    log "==> drop/create: $BASE_DB"
    drop_create "$BASE_DB"
  fi
  exit 0
fi

# fast
ensure_up
if [[ "$STRATEGY" == "per_worker" ]]; then
  "$SCRIPT_DIR/db_clean.sh" --all
else
  "$SCRIPT_DIR/db_clean.sh"
fi
