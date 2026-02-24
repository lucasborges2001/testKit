#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Usage:
#   ./scripts/db_clean.sh             # clean base DB
#   ./scripts/db_clean.sh --worker 2  # clean worker DB #2 (per_worker)
#   ./scripts/db_clean.sh --all       # clean all worker DBs (1..TEST_JOBS)

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

MODE="base"
WORKER="1"

if [[ "${1:-}" == "--worker" && -n "${2:-}" ]]; then
  MODE="worker"; WORKER="$2"; shift 2
elif [[ "${1:-}" == "--all" ]]; then
  MODE="all"; shift 1
fi

mk_db_name() {
  local w="$1"
  printf "%s" "${BASE_DB}$(printf "$SUFFIX_FMT" "$w")"
}

clean_db() {
  local db="$1"
  echo "==> Cleaning MySQL DB: ${db}"

  # Listar tablas BASE TABLE sin comillas (hex literal): 0x42415345205441424c45 = "BASE TABLE"
  local tables
  tables="$(./bin/testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1" -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type=0x42415345205441424c45;"' -- "$db" || true)"

  if [[ -z "${tables//[[:space:]]/}" ]]; then
    echo "   (no tables found or DB missing)" >&2
    return 0
  fi

  {
    printf "SET FOREIGN_KEY_CHECKS=0;\n"
    while IFS= read -r t; do
      t="${t//$'\r'/}"
      [[ -z "$t" ]] && continue
      if [[ "$t" =~ ^[A-Za-z0-9_]+$ ]]; then
        printf "TRUNCATE TABLE \`%s\`;\n" "$t"
      fi
    done <<< "$tables"
    printf "SET FOREIGN_KEY_CHECKS=1;\n"
  } | ./bin/testkit exec -T mysql_test sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' -- "$db"
}

if [[ "$STRATEGY" == "per_worker" ]]; then
  if [[ "$MODE" == "all" ]]; then
    for ((i=1;i<=JOBS;i++)); do clean_db "$(mk_db_name "$i")"; done
  elif [[ "$MODE" == "worker" ]]; then
    clean_db "$(mk_db_name "$WORKER")"
  else
    clean_db "$(mk_db_name 1)"
  fi
else
  clean_db "$BASE_DB"
fi
