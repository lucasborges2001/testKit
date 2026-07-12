#!/usr/bin/env bash
set -euo pipefail

# Disposable MySQL lifecycle for Pruebas SQL observability.
# Operations: prepare, reset, verify, cleanup.

operation="${1:-}"
if [[ -z "$operation" ]]; then
  echo "Usage: bash scripts/sql-observability/db.sh prepare|reset|verify|cleanup" >&2
  exit 2
fi
shift || true

TESTKIT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ROOT="${TK_REPO_ROOT:-${TESTKIT_PROJECT_ROOT:-$(pwd)}}"
CONFIG_LOADER="$TESTKIT_ROOT/scripts/sql-observability/config.php"
TESTKIT_BIN="$TESTKIT_ROOT/bin/testkit"

: "${SQLOBS_ENV_FILE:?SQLOBS_ENV_FILE is required}"
: "${SQLOBS_DATASET_MANIFEST:?SQLOBS_DATASET_MANIFEST is required}"
: "${SQLOBS_DB_NAME:?SQLOBS_DB_NAME is required}"
: "${SQLOBS_DB_HOST:?SQLOBS_DB_HOST is required}"
: "${SQLOBS_DB_ROOT_PASSWORD:?SQLOBS_DB_ROOT_PASSWORD is required}"
: "${APP_ENV:?APP_ENV is required}"

if [[ "$APP_ENV" != "test" ]]; then
  echo "ERROR[db_guard_app_env] APP_ENV must be test." >&2
  exit 3
fi
if [[ ! -f "$SQLOBS_ENV_FILE" ]]; then
  echo "ERROR[db_guard_env_file] Runtime env file is missing." >&2
  exit 2
fi
if [[ ! -f "$CONFIG_LOADER" ]]; then
  echo "ERROR[db_guard_runtime] Config loader is unavailable." >&2
  exit 2
fi

dataset_json="$(mktemp)"
trap 'rm -f "$dataset_json"' EXIT
dataset_manifest_arg="$SQLOBS_DATASET_MANIFEST"
if [[ "$dataset_manifest_arg" == /* ]]; then
  case "$dataset_manifest_arg" in
    "$ROOT"/*) dataset_manifest_arg="${dataset_manifest_arg#"$ROOT/"}" ;;
    *)
      echo "ERROR[db_guard_dataset_path] Dataset manifest must remain inside the repository." >&2
      exit 3
      ;;
  esac
fi
php "$CONFIG_LOADER" dataset --path "$dataset_manifest_arg" > "$dataset_json"

json_string() {
  local file="$1"
  local path="$2"
  php -r '
    $data=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $value=$data;
    foreach(explode(".",$argv[2]) as $part){if($part===""){continue;} if(!is_array($value)||!array_key_exists($part,$value)){exit(3);} $value=$value[$part];}
    if(!is_scalar($value)){exit(3);} echo (string)$value;
  ' "$file" "$path"
}

json_list() {
  local file="$1"
  local path="$2"
  php -r '
    $data=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $value=$data;
    foreach(explode(".",$argv[2]) as $part){if($part===""){continue;} if(!is_array($value)||!array_key_exists($part,$value)){exit(3);} $value=$value[$part];}
    if(!is_array($value)){exit(3);}
    foreach($value as $entry){if(!is_string($entry)){exit(3);} echo $entry,PHP_EOL;}
  ' "$file" "$path"
}

DB_PREFIX="$(json_string "$dataset_json" safety.database_name_prefix)"
SCHEMA_FILE="$ROOT/$(json_string "$dataset_json" schema_file)"
SEED_FILE="$ROOT/$(json_string "$dataset_json" seed_file)"

host_allowed=0
while IFS= read -r allowed; do
  if [[ "$SQLOBS_DB_HOST" == "$allowed" ]]; then
    host_allowed=1
    break
  fi
done < <(json_list "$dataset_json" safety.allowed_hosts)

if [[ "$host_allowed" -ne 1 ]]; then
  echo "ERROR[db_guard_host] Database host is not allowlisted." >&2
  exit 3
fi
if [[ "$SQLOBS_DB_NAME" != "$DB_PREFIX"* ]]; then
  echo "ERROR[db_guard_prefix] Database name does not use the dataset prefix." >&2
  exit 3
fi
if [[ ! "$SQLOBS_DB_NAME" =~ ^[a-z0-9_]{1,64}$ ]]; then
  echo "ERROR[db_guard_name] Database name is invalid." >&2
  exit 3
fi
case "$SQLOBS_DB_NAME" in
  mysql|information_schema|performance_schema|sys|production|"")
    echo "ERROR[db_guard_reserved] Reserved database name rejected." >&2
    exit 3
    ;;
esac
if [[ ! "$DB_PREFIX" =~ ^[a-z0-9][a-z0-9_]{0,39}$ ]]; then
  echo "ERROR[db_guard_prefix_contract] Unsafe dataset prefix." >&2
  exit 3
fi
if [[ ! -f "$SCHEMA_FILE" || ! -f "$SEED_FILE" ]]; then
  echo "ERROR[db_guard_dataset_files] Dataset files are unavailable." >&2
  exit 2
fi
if [[ ! -f "$TESTKIT_BIN" ]]; then
  echo "ERROR[db_guard_runtime] testKit runner is unavailable." >&2
  exit 2
fi

tk() {
  TESTKIT_ENV_FILE="$SQLOBS_ENV_FILE" \
  TESTKIT_STACK=mysql \
  TESTKIT_PROJECT_ROOT="$ROOT" \
  "$TESTKIT_BIN" "$@"
}

mysql_root() {
  tk exec -T \
    -e MYSQL_PWD="$SQLOBS_DB_ROOT_PASSWORD" \
    mysql_test \
    mysql --protocol=TCP -h127.0.0.1 -uroot "$@"
}

wait_mysql() {
  local attempt
  for attempt in $(seq 1 60); do
    if tk exec -T \
      -e MYSQL_PWD="$SQLOBS_DB_ROOT_PASSWORD" \
      mysql_test \
      mysqladmin --protocol=TCP -h127.0.0.1 -uroot ping --silent >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "ERROR[mysql_readiness] Disposable MySQL did not become ready." >&2
  return 2
}

reset_database() {
  # The guard above is mandatory before these destructive statements.
  mysql_root -e "DROP DATABASE IF EXISTS \`$SQLOBS_DB_NAME\`; CREATE DATABASE \`$SQLOBS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql_root "$SQLOBS_DB_NAME" < "$SCHEMA_FILE"
  mysql_root "$SQLOBS_DB_NAME" < "$SEED_FILE"
}

verify_database() {
  local missing=0
  while IFS= read -r table; do
    local count
    count="$(mysql_root -N -B "$SQLOBS_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table';")"
    if [[ "$count" != "1" ]]; then
      echo "ERROR[dataset_table_missing] Expected table is missing: $table" >&2
      missing=1
    fi
  done < <(json_list "$dataset_json" expected_tables)
  if [[ "$missing" -ne 0 ]]; then
    return 3
  fi
  mysql_root -N -B "$SQLOBS_DB_NAME" -e "SELECT VERSION();" | head -n1
}

case "$operation" in
  prepare)
    tk up -d mysql_test
    wait_mysql
    reset_database
    verify_database
    ;;
  reset)
    wait_mysql
    reset_database
    verify_database
    ;;
  verify)
    wait_mysql
    verify_database
    ;;
  cleanup)
    # COMPOSE_PROJECT_NAME in the runtime env isolates the volume and containers.
    tk down -v --remove-orphans >/dev/null 2>&1 || true
    ;;
  *)
    echo "ERROR[db_operation] Unsupported operation: $operation" >&2
    exit 2
    ;;
esac
