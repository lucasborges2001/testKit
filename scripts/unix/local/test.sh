#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/local/test.sh
#
# Ejecuta el meta-runner en modo LOCAL:
#   ${PHP_BIN:-php} test/runTest.php <target>
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/_lib.sh"

IFS="|" read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"

# Env opcional
if ENV_FILE="$(pick_env_file "$TEST_ROOT" "$REPO_ROOT" 2>/dev/null || true)"; then
  [[ -n "${ENV_FILE:-}" ]] && load_env_kv_safe "$ENV_FILE" || true
fi

PHP_BIN="${PHP_BIN:-php}"
META="$TEST_ROOT/runTest.php"
[[ -f "$META" ]] || fail "Falta meta-runner: $META"

cd "$TEST_ROOT"
"$PHP_BIN" "$META" "$@"
