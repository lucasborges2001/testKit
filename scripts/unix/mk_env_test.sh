#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/mk_env_test.sh
#
# Genera test/.env.test a partir de un env existente en el repo.
# Prioridad de búsqueda (desde <repo>):
#   1) env.debug
#   2) .env
#   3) env.test
#
# Seguridad:
# - No sobreescribe test/.env.test si ya existe.
# =============================================================================

# .../test/scripts/unix -> .../test
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

OUT="./.env.test"
if [[ -f "$OUT" ]]; then
  echo "Ya existe $OUT (no lo sobreescribo)." >&2
  exit 1
fi

SRC=""
for c in "../env.debug" "../.env" "../env.test"; do
  [[ -f "$c" ]] && { SRC="$c"; break; }
done

if [[ -z "$SRC" ]]; then
  echo "No encontré ../env.debug ni ../.env ni ../env.test. Copiá manualmente .env.test.example." >&2
  exit 1
fi

cp "$SRC" "$OUT"
{
  echo ""
  echo "APP_ENV=test"
  echo "APP_DEBUG=1"
} >> "$OUT"

echo "OK: generado $OUT desde $SRC"
