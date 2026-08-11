#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${root}/lib/bash/rewrite.sh"

rewrite() {
  local out=()
  mapfile -d '' -t out < <(testkit_rewrite_run_command_args "$@")
  printf '%s\n' "${out[*]}"
}

[[ "$(rewrite run --rm testkit php runTest.php --suite back-php)" == \
  'run --rm -e TESTKIT_WRAPPER_KIND=bash testkit php /workspace/testkit/runTest.php --suite back-php' ]]
[[ "$(TESTKIT_SKIP_STORE_BOOTSTRAP=1 rewrite run --rm testkit php runTest.php --suite back-php)" == \
  'run --no-deps --rm -e TESTKIT_WRAPPER_KIND=bash testkit php /workspace/testkit/runTest.php --suite back-php' ]]
[[ "$(rewrite run --rm testkit php runTest.php --suite front-js)" == \
  'run --rm -e TESTKIT_WRAPPER_KIND=bash testkit-browser php /workspace/testkit/runTest.php --suite front-js' ]]
[[ "$(rewrite run --rm testkit node /workspace/testkit/runners/runBrowserE2e.mjs smoke.spec.mjs)" == \
  'run --rm -e TESTKIT_WRAPPER_KIND=bash testkit-browser node /workspace/testkit/runners/runBrowserE2e.mjs smoke.spec.mjs' ]]
[[ "$(TESTKIT_RUN_BUILD=1 rewrite run --rm testkit php runTest.php --suite back-php)" == \
  'run --build --rm -e TESTKIT_WRAPPER_KIND=bash testkit php /workspace/testkit/runTest.php --suite back-php' ]]

echo 'Wrapper runtime contract PASS'
