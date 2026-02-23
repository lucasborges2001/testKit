#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Genera test/.env.test a partir de una fuente del repo (prioridad: env.debug, .env, env.test)
# Mantiene el contrato: el env REAL vive en test/.env.test

OUT="./.env.test"
if [[ -f "$OUT" ]]; then
  echo "Ya existe $OUT (no lo sobreescribo)." >&2
  exit 1
fi

SRC=""
for c in "../env.debug" "../.env" "../env.test"; do
  if [[ -f "$c" ]]; then SRC="$c"; break; fi
done

if [[ -z "$SRC" ]]; then
  echo "No encontré ../env.debug ni ../.env ni ../env.test. Copiá manualmente .env.test.example." >&2
  exit 1
fi

cp "$SRC" "$OUT"
# Hard‑defaults seguros
{
  echo ""
  echo "APP_ENV=test"
  echo "APP_DEBUG=1"
} >> "$OUT"

echo "OK: generado $OUT desde $SRC"
