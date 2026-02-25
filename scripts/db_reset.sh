#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /test/scripts/db_reset.sh
# Resetea docker: down -v, up -d
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

"$TK" down -v || true
"$TK" up -d