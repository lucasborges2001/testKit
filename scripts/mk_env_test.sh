#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /testkit/scripts/mk_env_test.sh
# Genera <project>/test/.env.test a partir de un env existente del proyecto.
# =============================================================================

PROJECT_ROOT="${TESTKIT_PROJECT_ROOT:-}"
[[ -z "$PROJECT_ROOT" ]] && { echo "Definí TESTKIT_PROJECT_ROOT" >&2; exit 1; }
mkdir -p "$PROJECT_ROOT/test"

SRC="${1:-}"
if [[ -z "$SRC" ]]; then
  for cand in "$PROJECT_ROOT/.env" "$PROJECT_ROOT/.env.local" "$PROJECT_ROOT/back/.env"; do
    if [[ -f "$cand" ]]; then SRC="$cand"; break; fi
  done
fi
[[ -z "$SRC" || ! -f "$SRC" ]] && { echo "No se encontró env fuente." >&2; exit 1; }

DEST="$PROJECT_ROOT/test/.env.test"
cp "$SRC" "$DEST"
echo "Generado: $DEST"
