#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

find_testkit() {
  local override="${TESTKIT_BIN:-}"
  if [[ -n "$override" && -x "$override" ]]; then echo "$override"; return 0; fi
  if [[ -x "./bin/testkit" ]]; then echo "./bin/testkit"; return 0; fi
  if [[ -x "../bin/testkit" ]]; then echo "../bin/testkit"; return 0; fi
  echo "No se encontró bin/testkit. Seteá TESTKIT_BIN o instalá TestKit." >&2
  return 1
}

TK="$(find_testkit)"
DRIVER="${1:-mysql}"
TARGET_DB="${2:-${DB_NAME:-${TEST_MYSQL_DB:-}}}"

if [[ -z "$TARGET_DB" ]]; then
  echo "Debés indicar DB objetivo o exponer DB_NAME/TEST_MYSQL_DB." >&2
  exit 1
fi

"$TK" run --rm testkit php /workspace/testkit/scripts/store_router.php invalidate-baseline "$DRIVER" "$TARGET_DB"
