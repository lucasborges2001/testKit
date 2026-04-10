#!/usr/bin/env bash
set -euo pipefail

# Usa un reporte o metadata de backupkit como fuente del baseline snapshot.
# Ejemplos:
#   ./scripts/migration_contract_from_backup.sh report /ruta/restore-test-report.json
#   ./scripts/migration_contract_from_backup.sh metadata /ruta/mi_dump.sql.gz.metadata.json

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
KIND="${1:-report}"
SOURCE_PATH="${2:-}"

if [[ -z "$SOURCE_PATH" ]]; then
  echo "Uso: $0 <report|metadata> <ruta-json>" >&2
  exit 1
fi

ENV_ARGS=(-e TEST_BASELINE_MODE=snapshot -e TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS=1)
case "$KIND" in
  report)
    ENV_ARGS+=(-e "TEST_BASELINE_BACKUPKIT_REPORT_JSON=$SOURCE_PATH")
    ;;
  metadata)
    ENV_ARGS+=(-e "TEST_BASELINE_BACKUPKIT_METADATA_JSON=$SOURCE_PATH")
    ;;
  *)
    echo "Tipo inválido: $KIND (usa report o metadata)" >&2
    exit 1
    ;;
esac

"$TK" run --rm "${ENV_ARGS[@]}" testkit php /workspace/testkit/runTest.php migration-contract
