#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# /test/scripts/mk_env_test.sh
#
# Genera <repo>/test/.env.test a partir de un env existente del repo
# (prioridad: ../env.debug, ../.env, ../env.test).
#
# Seguridad:
# - No sobreescribe test/.env.test si ya existe.
# =============================================================================

cd "$(dirname "${BASH_SOURCE[0]}")/.."

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