#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/docker/db_clean.sh
#
# Reset rápido de MySQL en Docker (mysql_test): TRUNCATE de tablas.
#
# Soporta estrategia per_worker:
#   - (sin args)       limpia el worker 1
#   - --worker <n>     limpia el worker n
#   - --all            limpia todos los workers
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

MODE="base"   # base|worker|all
WORKER="1"
if [[ "${1:-}" == "--worker" && -n "${2:-}" ]]; then
  MODE="worker"; WORKER="$2"; shift 2
elif [[ "${1:-}" == "--all" ]]; then
  MODE="all"; shift
fi

[[ "$WORKER" =~ ^[0-9]+$ ]] || WORKER="1"

TK="$(find_testkit "$TEST_ROOT" "$REPO_ROOT")"
cd "$TEST_ROOT"

clean_db() {
  local db="$1"
  log "==> Cleaning MySQL DB: $db"

  local tables
  tables="$($TK exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1" -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\"BASE TABLE\";"' -- "$db" || true)"

  [[ -z "${tables//[[:space:]]/}" ]] && { log "   (no tables found or DB missing)"; return 0; }

  {
    printf "SET FOREIGN_KEY_CHECKS=0;\n"
    while IFS= read -r t; do
      t="${t//$'\r'/}"
      [[ -z "$t" ]] && continue
      [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] && printf "TRUNCATE TABLE \`%s\`;\n" "$t"
    done <<< "$tables"
    printf "SET FOREIGN_KEY_CHECKS=1;\n"
  } | $TK exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$db"
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  if [[ "$MODE" == "all" ]]; then
    for ((i=1;i<=JOBS;i++)); do clean_db "$(mk_db_name "$BASE_DB" "$FMT" "$i")"; done
  elif [[ "$MODE" == "worker" ]]; then
    clean_db "$(mk_db_name "$BASE_DB" "$FMT" "$WORKER")"
  else
    clean_db "$(mk_db_name "$BASE_DB" "$FMT" 1)"
  fi
else
  clean_db "$BASE_DB"
fi
