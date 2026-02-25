#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/docker/test.sh
# Ejecuta el meta-runner dentro del contenedor TestKit:
#   test/runTest.php <target>
# =============================================================================

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../_lib.sh"

IFS='|' read -r SCRIPT_DIR TEST_ROOT REPO_ROOT <<<"$(get_paths)"
TK="$(find_testkit "$TEST_ROOT" "$REPO_ROOT")"

cd "$TEST_ROOT"

"$TK" run --rm testkit php runTest.php "$@"
