#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /test/scripts/db_clean.sh
# TRUNCATE de todas las tablas en mysql_test (docker).
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

MODE="base"   # base|worker|all
WORKER="1"
if [[ "${1:-}" == "--worker" && -n "${2:-}" ]]; then MODE="worker"; WORKER="$2"; shift 2
elif [[ "${1:-}" == "--all" ]]; then MODE="all"; shift; fi

mk_db_name() { local w="$1"; printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"; }

clean_db() {
  local db="$1"
  echo "==> Cleaning MySQL DB: ${db}"

  local tables
  tables="$("$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1" -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=\"BASE TABLE\";"' -- "$db" || true)"

  [[ -z "${tables//[[:space:]]/}" ]] && { echo "   (no tables found or DB missing)" >&2; return 0; }

  {
    printf "SET FOREIGN_KEY_CHECKS=0;\n"
    while IFS= read -r t; do
      t="${t//$'\r'/}"
      [[ -z "$t" ]] && continue
      [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] && printf "TRUNCATE TABLE \`%s\`;\n" "$t"
    done <<< "$tables"
    printf "SET FOREIGN_KEY_CHECKS=1;\n"
  } | "$TK" exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$db"
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  if [[ "$MODE" == "all" ]]; then for ((i=1;i<=JOBS;i++)); do clean_db "$(mk_db_name "$i")"; done
  elif [[ "$MODE" == "worker" ]]; then clean_db "$(mk_db_name "$WORKER")"
  else clean_db "$(mk_db_name 1)"; fi
else
  clean_db "$BASE_DB"
fi