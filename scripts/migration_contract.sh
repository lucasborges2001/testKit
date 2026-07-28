#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /testkit/scripts/migration_contract.sh
# Ejecuta la suite canónica migration-contract dentro del contenedor TestKit.
# Valida baseline snapshot + bootstrap estructural + manifest.
# =============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."  # <testkit>

find_testkit() {
  local override="${TESTKIT_BIN:-}"
  if [[ -n "$override" && -x "$override" ]]; then echo "$override"; return 0; fi
  if [[ -x "./bin/testkit" ]]; then echo "./bin/testkit"; return 0; fi
  if [[ -x "../bin/testkit" ]]; then echo "../bin/testkit"; return 0; fi
  echo "No se encontró bin/testkit. Seteá TESTKIT_BIN o instalá TestKit." >&2
  return 1
}

TK="$(find_testkit)"

"$TK" run --rm testkit php /workspace/testkit/runTest.php --suite migration-contract
